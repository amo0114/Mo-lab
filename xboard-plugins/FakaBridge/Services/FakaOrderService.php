<?php

namespace Plugin\FakaBridge\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugin\FakaBridge\Models\FakaOrder;

/**
 * Create / renew Xboard subscriptions for MoNexus paid orders.
 *
 * Hardened flow (image :new / cedar2025 OrderService):
 *  1. Validate SKU / email
 *  2. Claim faka_orders row (unique external_order_no) — concurrent safe
 *  3. createFromRequest → bind trade_no on claim row (before paid)
 *  4. paid() → dispatchSync → open() → order.open.after may mark completed
 *  5. Reconcile status if hook raced; persist failed without losing claim
 *
 * Period keys: monthly | quarterly | half_yearly | yearly | two_yearly | three_yearly
 */
class FakaOrderService
{
    public const PERIODS = [
        'monthly',
        'quarterly',
        'half_yearly',
        'yearly',
        'two_yearly',
        'three_yearly',
        'onetime',
        'reset_traffic',
    ];

    public const LEGACY_PERIOD_MAP = [
        'month_price' => 'monthly',
        'quarter_price' => 'quarterly',
        'half_year_price' => 'half_yearly',
        'year_price' => 'yearly',
        'two_year_price' => 'two_yearly',
        'three_year_price' => 'three_yearly',
        'onetime_price' => 'onetime',
        'reset_price' => 'reset_traffic',
    ];

    public const SKU_MAPPING = [
        'aster-basic-monthly' => ['plan_id' => 3, 'period' => 'monthly'],
        'aster-basic-quarterly' => ['plan_id' => 3, 'period' => 'quarterly'],
        'aster-basic-half-yearly' => ['plan_id' => 3, 'period' => 'half_yearly'],
        'aster-basic-yearly' => ['plan_id' => 3, 'period' => 'yearly'],
        'aster-basic-two-yearly' => ['plan_id' => 3, 'period' => 'two_yearly'],
        'aster-basic-three-yearly' => ['plan_id' => 3, 'period' => 'three_yearly'],
        'aster-pro-monthly' => ['plan_id' => 4, 'period' => 'monthly'],
        'aster-pro-quarterly' => ['plan_id' => 4, 'period' => 'quarterly'],
        'aster-pro-half-yearly' => ['plan_id' => 4, 'period' => 'half_yearly'],
        'aster-pro-yearly' => ['plan_id' => 4, 'period' => 'yearly'],
        'aster-pro-two-yearly' => ['plan_id' => 4, 'period' => 'two_yearly'],
        'aster-pro-three-yearly' => ['plan_id' => 4, 'period' => 'three_yearly'],
        'aster-legend-monthly' => ['plan_id' => 5, 'period' => 'monthly'],
        'aster-legend-quarterly' => ['plan_id' => 5, 'period' => 'quarterly'],
        'aster-legend-half-yearly' => ['plan_id' => 5, 'period' => 'half_yearly'],
        'aster-legend-yearly' => ['plan_id' => 5, 'period' => 'yearly'],
        'aster-legend-two-yearly' => ['plan_id' => 5, 'period' => 'two_yearly'],
        'aster-legend-three-yearly' => ['plan_id' => 5, 'period' => 'three_yearly'],
        'aster-speed-monthly' => ['plan_id' => 6, 'period' => 'monthly'],
        'aster-speed-yearly' => ['plan_id' => 6, 'period' => 'yearly'],
        'aster-direct-monthly' => ['plan_id' => 7, 'period' => 'monthly'],
        'aster-direct-yearly' => ['plan_id' => 7, 'period' => 'yearly'],
        'aster-chain-monthly' => ['plan_id' => 9, 'period' => 'monthly'],
        'aster-chain-yearly' => ['plan_id' => 9, 'period' => 'yearly'],
    ];

    public function __construct(
        protected FakaBridgeConfig $config
    ) {
    }

    /**
     * @return array{trade_no: ?string, status: string, message: string, order_no: string, resumed?: bool}
     */
    public function handleOrder(
        string $externalOrderNo,
        string $email,
        string $sku,
        string $period = 'monthly',
        ?int $paidAt = null
    ): array {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('邮箱格式无效');
        }

        $mapping = $this->resolveSku($sku, $period);
        $planId = $mapping['plan_id'];
        $mappedPeriod = $mapping['period'];

        $plan = Plan::find($planId);
        if (!$plan) {
            throw new ApiException("套餐不存在 (plan_id={$planId})");
        }

        $claim = $this->claimOrGetExisting(
            $externalOrderNo,
            $email,
            $sku,
            $planId,
            $mappedPeriod,
            $period,
            $paidAt
        );

        /** @var FakaOrder $faka */
        $faka = $claim['order'];

        if ($faka->isRevoked()) {
            throw new ApiException('订单已撤销，不可重新开通');
        }

        if ($claim['mode'] === 'existing' && !$faka->canResume()) {
            return $this->successPayload($faka, '订单已处理,请勿重复回调');
        }

        try {
            return $this->fulfill($faka, $plan, $mappedPeriod, $email);
        } catch (\Throwable $e) {
            // Claim row lives outside this failure so retries stay idempotent.
            $this->markFailed($faka, $e->getMessage());
            throw $e instanceof ApiException
                ? $e
                : new ApiException('订单处理失败: ' . $e->getMessage());
        }
    }

    /**
     * @return array{mode: 'claimed'|'existing', order: FakaOrder}
     */
    protected function claimOrGetExisting(
        string $externalOrderNo,
        string $email,
        string $sku,
        int $planId,
        string $mappedPeriod,
        string $requestPeriod,
        ?int $paidAt
    ): array {
        $existing = FakaOrder::where('external_order_no', $externalOrderNo)->first();
        if ($existing) {
            return ['mode' => 'existing', 'order' => $existing];
        }

        try {
            $order = FakaOrder::create([
                'external_order_no' => $externalOrderNo,
                'xboard_trade_no' => null,
                'email' => $email,
                'sku' => $sku,
                'plan_id' => $planId,
                'period' => $mappedPeriod,
                'status' => FakaOrder::STATUS_PENDING,
                'paid_at' => $paidAt ? date('Y-m-d H:i:s', $paidAt) : now(),
                'raw_payload' => json_encode([
                    'sku' => $sku,
                    'period' => $requestPeriod,
                    'paid_at' => $paidAt,
                ], JSON_UNESCAPED_UNICODE),
                'error_log' => null,
            ]);

            Log::info('[FakaBridge] 已占坑 faka_orders', [
                'external_order_no' => $externalOrderNo,
            ]);

            return ['mode' => 'claimed', 'order' => $order];
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = FakaOrder::where('external_order_no', $externalOrderNo)->first();
            if (!$existing) {
                throw $e;
            }

            Log::info('[FakaBridge] 并发占坑冲突，转为已有订单', [
                'external_order_no' => $externalOrderNo,
                'status' => $existing->status,
            ]);

            return ['mode' => 'existing', 'order' => $existing];
        }
    }

    /**
     * Fulfill under row lock so concurrent retries serialize on the same claim.
     *
     * trade_no is written before paid() so order.open.after can match the row
     * while dispatchSync(open) still runs inside paid().
     *
     * @return array{
     *   trade_no: ?string,
     *   status: string,
     *   message: string,
     *   order_no: string,
     *   resumed?: bool,
     *   expired_at?: int|null,
     *   subscription?: array
     * }
     */
    protected function fulfill(FakaOrder $faka, Plan $plan, string $mappedPeriod, string $email): array
    {
        return DB::transaction(function () use ($faka, $plan, $mappedPeriod, $email) {
            /** @var FakaOrder $locked */
            $locked = FakaOrder::query()->whereKey($faka->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new ApiException('订单记录丢失');
            }

            if ($locked->isTerminalSuccess()) {
                return $this->successPayload($locked, '订单已处理,请勿重复回调', false, $mappedPeriod);
            }

            $user = $this->findOrCreateUser($email);
            // Snapshot BEFORE paid/open so MoNexus can show "续期前 / 购买前" to buyers.
            $before = $this->snapshotUserSubscription($user);
            $resumed = $locked->status !== FakaOrder::STATUS_PENDING
                || !empty($locked->xboard_trade_no);

            if (!empty($locked->xboard_trade_no)) {
                $order = Order::where('trade_no', $locked->xboard_trade_no)->first();
                if (!$order) {
                    throw new ApiException('关联的 Xboard 订单不存在: ' . $locked->xboard_trade_no);
                }

                $this->ensurePaid($order, $locked->external_order_no);
                $this->reconcileStatus($locked, $order);
                $user->refresh();

                return $this->successPayload(
                    $locked->fresh(),
                    $resumed ? '订单已恢复处理' : '订单已创建并开始处理',
                    $resumed,
                    $mappedPeriod,
                    $before,
                    $this->snapshotUserSubscription($user)
                );
            }

            $order = OrderService::createFromRequest($user, $plan, $mappedPeriod);
            // Reload from DB so status is real PENDING(0), not in-memory null.
            $order->refresh();

            // Bind before paid(): open.after runs inside paid() via dispatchSync.
            $locked->update([
                'xboard_trade_no' => $order->trade_no,
                'status' => FakaOrder::STATUS_PROCESSING,
                'error_log' => null,
            ]);

            $this->ensurePaid($order, $locked->external_order_no);
            $this->reconcileStatus($locked, $order);
            $user->refresh();

            $fresh = $locked->fresh();
            Log::info('[FakaBridge] 订单处理成功', [
                'external_order_no' => $fresh->external_order_no,
                'xboard_trade_no' => $fresh->xboard_trade_no,
                'email' => $email,
                'plan_id' => $plan->id,
                'period' => $mappedPeriod,
                'status' => $fresh->status,
                'xboard_user_id' => $user->id,
            ]);

            return $this->successPayload(
                $fresh,
                $fresh->status === FakaOrder::STATUS_COMPLETED
                    ? '订单已创建并开通完成'
                    : '订单已创建并开始处理',
                $resumed,
                $mappedPeriod,
                $before,
                $this->snapshotUserSubscription($user)
            );
        });
    }

    protected function ensurePaid(Order $order, string $externalOrderNo): void
    {
        // Critical: createFromRequest often leaves in-memory status=null even when
        // DB default is 0(待支付). OrderService::paid() uses strict !== PENDING and
        // would early-return true without charging/opening — user stays 待支付, no plan.
        $order->refresh();
        $callbackNo = 'faka:' . $externalOrderNo;

        $status = (int) $order->status;

        if ($status === (int) Order::STATUS_COMPLETED) {
            return;
        }

        // 开通中 but open() never finished (job failed / nested-tx) → retry open path.
        if ($status === (int) Order::STATUS_PROCESSING) {
            $orderService = new OrderService($order);
            try {
                $orderService->open();
            } catch (\Throwable $e) {
                throw new ApiException('订单开通失败: ' . $e->getMessage());
            }
            $order->refresh();
            if ((int) $order->status !== (int) Order::STATUS_COMPLETED) {
                throw new ApiException('订单开通后仍未完成');
            }

            return;
        }

        if ($status !== (int) Order::STATUS_PENDING) {
            throw new ApiException('订单状态异常，无法支付: status=' . $status);
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            // paid() may leave status=PROCESSING if open failed after save
            $order->refresh();
            throw new ApiException(
                '订单支付失败 (xboard_status=' . (int) $order->status . ')'
            );
        }

        $order->refresh();
        if ((int) $order->status !== (int) Order::STATUS_COMPLETED) {
            // paid() returned true but open did not complete (null-status early return
            // or open swallowed). Force open once more.
            if ((int) $order->status === (int) Order::STATUS_PENDING) {
                // Truly still pending: paid() was a no-op — call again after refresh
                // already done; set status explicitly and re-invoke.
                if (!$orderService->paid($callbackNo)) {
                    throw new ApiException('订单支付失败(二次)');
                }
                $order->refresh();
            }
            if ((int) $order->status === (int) Order::STATUS_PROCESSING) {
                try {
                    $orderService->open();
                } catch (\Throwable $e) {
                    throw new ApiException('订单开通失败: ' . $e->getMessage());
                }
                $order->refresh();
            }
            if ((int) $order->status !== (int) Order::STATUS_COMPLETED) {
                throw new ApiException(
                    '支付回调后订单未完成 (xboard_status=' . (int) $order->status . ')'
                );
            }
        }
    }

    protected function reconcileStatus(FakaOrder $faka, Order $order): void
    {
        $order->refresh();
        $faka->refresh();

        if ($faka->status === FakaOrder::STATUS_COMPLETED) {
            return;
        }

        $completed = Order::STATUS_COMPLETED;

        if ((int) $order->status === (int) $completed) {
            $faka->update([
                'status' => FakaOrder::STATUS_COMPLETED,
                'completed_at' => $faka->completed_at ?? now(),
                'error_log' => null,
            ]);

            return;
        }

        if ($faka->status !== FakaOrder::STATUS_PROCESSING) {
            $faka->update([
                'status' => FakaOrder::STATUS_PROCESSING,
                'error_log' => null,
            ]);
        }
    }

    protected function markFailed(FakaOrder $faka, string $message): void
    {
        try {
            // Separate connection write: claim must survive fulfill() rollback.
            DB::connection()->transaction(function () use ($faka, $message) {
                $row = FakaOrder::query()->whereKey($faka->id)->lockForUpdate()->first();
                if (!$row || $row->status === FakaOrder::STATUS_COMPLETED) {
                    return;
                }

                // Keep trade_no if already bound (resume paid later).
                $row->update([
                    'status' => FakaOrder::STATUS_FAILED,
                    'error_log' => mb_substr($message, 0, 2000),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] 写入 failed 状态失败: ' . $e->getMessage(), [
                'external_order_no' => $faka->external_order_no ?? null,
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $before Snapshot before paid/open
     * @param array<string, mixed>|null $after Snapshot after paid/open
     * @return array{
     *   trade_no: ?string,
     *   status: string,
     *   message: string,
     *   order_no: string,
     *   resumed?: bool,
     *   expired_at?: int|null,
     *   period?: string,
     *   action?: string,
     *   before?: array,
     *   after?: array,
     *   subscription?: array
     * }
     */
    protected function successPayload(
        FakaOrder $faka,
        string $message,
        bool $resumed = false,
        ?string $period = null,
        ?array $before = null,
        ?array $after = null
    ): array {
        $payload = [
            'trade_no' => $faka->xboard_trade_no,
            'status' => $faka->status,
            'message' => $message,
            'order_no' => $faka->external_order_no,
        ];
        if ($resumed) {
            $payload['resumed'] = true;
        }

        $period = $period ?? ($faka->period ?: null);
        if ($after === null) {
            $after = $this->snapshotUserByEmail($faka->email);
        }
        if ($before === null) {
            // Idempotent replay: before is unknown; still expose current (after) state.
            $before = $after;
        }

        // Ground truth for MoNexus DeliveryRecord.expiresAt: Xboard user.expired_at
        // (unix seconds). MoNexus must not re-project period with validityDays alone —
        // renewals and remaining time live on this field after paid()/open().
        $expiredAt = isset($after['expired_at']) ? $after['expired_at'] : $this->resolveUserExpiredAt($faka->email);
        if ($expiredAt !== null) {
            $payload['expired_at'] = $expiredAt;
        }

        $action = $this->inferSubscriptionAction($period, $before, $after);
        $payload['period'] = $period;
        $payload['action'] = $action;
        $payload['before'] = $before;
        $payload['after'] = $after;
        // Nested block for MoNexus structured delivery / success UI.
        $payload['subscription'] = [
            'action' => $action,
            'period' => $period,
            'before' => $before,
            'after' => $after,
        ];

        return $payload;
    }

    /**
     * @return array{
     *   expired_at: int|null,
     *   transfer_enable: int,
     *   u: int,
     *   d: int,
     *   used: int,
     *   remaining: int,
     *   plan_id: int|null
     * }
     */
    public function snapshotUserSubscription(User $user): array
    {
        $transfer = (int) ($user->transfer_enable ?? 0);
        $up = (int) ($user->u ?? 0);
        $down = (int) ($user->d ?? 0);
        $used = max(0, $up + $down);
        $expiredRaw = $user->expired_at;
        $expiredAt = null;
        if ($expiredRaw !== null && $expiredRaw !== '') {
            $ts = (int) $expiredRaw;
            // NULL/0 often means unlimited time on Xboard onetime traffic packs.
            $expiredAt = $ts > 0 ? $ts : null;
        }

        return [
            'expired_at' => $expiredAt,
            'transfer_enable' => $transfer,
            'u' => $up,
            'd' => $down,
            'used' => $used,
            'remaining' => max(0, $transfer - $used),
            'plan_id' => $user->plan_id !== null ? (int) $user->plan_id : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshotUserByEmail(?string $email): ?array
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            return null;
        }

        return $this->snapshotUserSubscription($user);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    protected function inferSubscriptionAction(?string $period, ?array $before, ?array $after): string
    {
        $period = strtolower(trim((string) $period));
        if ($period === 'reset_traffic') {
            return 'reset_traffic';
        }
        if ($period === 'onetime') {
            return 'onetime';
        }

        $beforeExp = isset($before['expired_at']) ? $before['expired_at'] : null;
        $afterExp = isset($after['expired_at']) ? $after['expired_at'] : null;
        $now = time();

        if ($beforeExp !== null && (int) $beforeExp > $now
            && $afterExp !== null && (int) $afterExp > (int) $beforeExp) {
            return 'renew';
        }
        if ($beforeExp === null || (int) $beforeExp <= $now) {
            return 'new';
        }

        return 'renew';
    }

    /**
     * Look up Xboard user subscription end for a provision email.
     *
     * @return int|null Unix seconds, or null when unknown / unset
     */
    public function resolveUserExpiredAt(?string $email): ?int
    {
        $snap = $this->snapshotUserByEmail($email);
        if ($snap === null) {
            return null;
        }

        return $snap['expired_at'] ?? null;
    }

    /**
     * Capacity snapshot for MoNexus precheck (Xboard capacity_limit).
     * Does not create users or orders. Upgrade/downgrade policy is unchanged —
     * this only answers "can a *new* holder of this plan be created?".
     *
     * @return array{
     *   sku: string,
     *   plan_id: int,
     *   period: string,
     *   capacity_limit: int|null,
     *   active_users: int,
     *   remaining: int|null,
     *   sellable: bool,
     *   show: bool,
     *   sell: bool
     * }
     */
    public function getPlanCapacity(string $sku, string $period = 'monthly'): array
    {
        $sku = strtolower(trim($sku));
        $mapping = $this->resolveSku($sku, $period);
        $planId = $mapping['plan_id'];
        $mappedPeriod = $mapping['period'];

        $plan = Plan::find($planId);
        if (!$plan) {
            throw new ApiException("套餐不存在 (plan_id={$planId})");
        }

        $planService = new PlanService($plan);
        $capacityLimit = $plan->capacity_limit !== null ? (int) $plan->capacity_limit : null;

        $activeUsers = (int) User::where('plan_id', $plan->id)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhereNull('expired_at');
            })
            ->count();

        $remaining = null;
        if ($capacityLimit !== null) {
            $remaining = max(0, $capacityLimit - $activeUsers);
        }

        $hasCapacity = $planService->hasCapacity($plan);
        $show = (bool) $plan->show;
        $sell = (bool) $plan->sell;
        // New-purchase sellable: plan listed for sale + capacity remaining.
        // Note: renewals/same-plan do not consume capacity the same way; MoNexus
        // may still sell renewal SKUs when remaining=0 for users already on plan.
        $sellable = $show && $sell && $hasCapacity;

        return [
            'sku' => $sku,
            'plan_id' => $planId,
            'period' => $mappedPeriod,
            'capacity_limit' => $capacityLimit,
            'active_users' => $activeUsers,
            'remaining' => $remaining,
            'sellable' => $sellable,
            'show' => $show,
            'sell' => $sell,
        ];
    }

    /**
     * Set Xboard plan capacity_limit (null = unlimited).
     * Only reachable via signed FakaBridge secret (MoNexus platform admin).
     *
     * @return array same shape as getPlanCapacity after write
     */
    public function setPlanCapacity(string $sku, ?int $capacityLimit, string $period = 'monthly'): array
    {
        $sku = strtolower(trim($sku));
        $mapping = $this->resolveSku($sku, $period);
        $planId = $mapping['plan_id'];

        $plan = Plan::find($planId);
        if (!$plan) {
            throw new ApiException("套餐不存在 (plan_id={$planId})");
        }

        if ($capacityLimit !== null) {
            if ($capacityLimit < 0) {
                throw new ApiException('capacity_limit 不能为负数');
            }
            // Soft cap to avoid accidental huge writes
            if ($capacityLimit > 1_000_000) {
                throw new ApiException('capacity_limit 过大');
            }
        }

        $plan->capacity_limit = $capacityLimit;
        if (!$plan->save()) {
            throw new ApiException('保存套餐人数限制失败');
        }

        Log::info('[FakaBridge] capacity_limit 已更新', [
            'sku' => $sku,
            'plan_id' => $planId,
            'capacity_limit' => $capacityLimit,
        ]);

        return $this->getPlanCapacity($sku, $period);
    }

    /**
     * Catalog of Xboard plans for MoNexus admin import (HMAC-only).
     *
     * @return array{plans: list<array<string, mixed>>}
     */
    public function listPlanCatalog(): array
    {
        $plans = Plan::query()->orderBy('sort')->orderBy('id')->get();
        $items = [];

        foreach ($plans as $plan) {
            $prices = is_array($plan->prices) ? $plan->prices : [];
            $periods = [];
            foreach ($prices as $periodKey => $price) {
                if (!is_numeric($price) || (float) $price <= 0) {
                    continue;
                }
                $periods[] = [
                    'period' => (string) $periodKey,
                    'price' => (float) $price,
                    // Suggested MoNexus externalSku alias
                    'sku_alias' => 'plan-' . $plan->id . '-' . $periodKey,
                ];
            }

            $capacityLimit = $plan->capacity_limit !== null ? (int) $plan->capacity_limit : null;
            $activeUsers = (int) User::where('plan_id', $plan->id)
                ->where(function ($query) {
                    $query->where('expired_at', '>=', time())
                        ->orWhereNull('expired_at');
                })
                ->count();
            $remaining = $capacityLimit === null ? null : max(0, $capacityLimit - $activeUsers);

            // Prefer named SKU from plugin map when plan_id matches
            $namedSkus = [];
            foreach (self::SKU_MAPPING as $skuKey => $map) {
                if ((int) $map['plan_id'] === (int) $plan->id) {
                    $namedSkus[] = [
                        'sku' => $skuKey,
                        'period' => $map['period'],
                    ];
                }
            }

            $items[] = [
                'plan_id' => (int) $plan->id,
                'name' => (string) $plan->name,
                // Plan content: HTML/markdown from Xboard admin (套餐介绍)
                'content' => $plan->content !== null ? (string) $plan->content : null,
                'show' => (bool) $plan->show,
                'sell' => (bool) $plan->sell,
                'renew' => (bool) $plan->renew,
                'group_id' => $plan->group_id !== null ? (int) $plan->group_id : null,
                'transfer_enable' => (int) $plan->transfer_enable,
                'capacity_limit' => $capacityLimit,
                'active_users' => $activeUsers,
                'remaining' => $remaining,
                'periods' => $periods,
                'named_skus' => $namedSkus,
            ];
        }

        return ['plans' => $items];
    }

    /**
     * Revoke a FakaBridge provision after MoNexus refund / admin action.
     * Best-effort: expire matching Xboard user subscription; mark faka_orders revoked.
     * Idempotent on external_order_no.
     *
     * @return array{
     *   order_no: string,
     *   status: string,
     *   trade_no: ?string,
     *   expired_user: bool,
     *   message: string
     * }
     */
    public function revokeOrder(string $externalOrderNo, ?string $reason = null): array
    {
        $externalOrderNo = trim($externalOrderNo);
        if ($externalOrderNo === '') {
            throw new ApiException('缺少必要参数: order_no');
        }

        /** @var FakaOrder|null $faka */
        $faka = FakaOrder::where('external_order_no', $externalOrderNo)->first();
        if (!$faka) {
            throw new ApiException('订单不存在');
        }

        if ($faka->status === FakaOrder::STATUS_REVOKED) {
            return [
                'order_no' => $faka->external_order_no,
                'status' => FakaOrder::STATUS_REVOKED,
                'trade_no' => $faka->xboard_trade_no,
                'expired_user' => false,
                'message' => '订单已撤销，请勿重复回调',
            ];
        }

        $expiredUser = false;
        $reasonText = $reason !== null && $reason !== ''
            ? mb_substr($reason, 0, 500)
            : 'MoNexus refund/revoke';

        // Expire active subscription only when plan_id still matches this provision.
        // Avoid nuking a user who already upgraded to a different plan.
        if (!empty($faka->email) && $faka->plan_id) {
            $user = User::where('email', strtolower(trim($faka->email)))->first();
            if ($user && (int) $user->plan_id === (int) $faka->plan_id) {
                $stillActive = $user->expired_at === null || (int) $user->expired_at > time();
                if ($stillActive) {
                    $user->expired_at = time() - 1;
                    // Capacity counts active users via expired_at; clearing transfer is optional.
                    $user->save();
                    $expiredUser = true;
                    Log::info('[FakaBridge] 已过期用户订阅(revoke)', [
                        'external_order_no' => $externalOrderNo,
                        'user_id' => $user->id,
                        'plan_id' => $faka->plan_id,
                    ]);
                }
            }
        }

        $faka->update([
            'status' => FakaOrder::STATUS_REVOKED,
            'error_log' => mb_substr('[revoked] ' . $reasonText, 0, 2000),
            'completed_at' => $faka->completed_at ?? now(),
        ]);

        Log::info('[FakaBridge] 订单已撤销', [
            'external_order_no' => $externalOrderNo,
            'expired_user' => $expiredUser,
            'trade_no' => $faka->xboard_trade_no,
        ]);

        return [
            'order_no' => $faka->external_order_no,
            'status' => FakaOrder::STATUS_REVOKED,
            'trade_no' => $faka->xboard_trade_no,
            'expired_user' => $expiredUser,
            'message' => $expiredUser
                ? '已撤销开通并过期对应订阅'
                : '已标记撤销（无需或未能过期用户订阅）',
        ];
    }

    /**
     * @return array{plan_id: int, period: string}
     */
    protected function resolveSku(string $sku, string $period): array
    {
        if (isset(self::SKU_MAPPING[$sku])) {
            return self::SKU_MAPPING[$sku];
        }

        if (preg_match('/^plan-(\d+)-(.+)$/', $sku, $matches)) {
            return [
                'plan_id' => (int) $matches[1],
                'period' => $this->normalizePeriod($matches[2]),
            ];
        }

        throw new ApiException("未配置的 SKU: {$sku}，请在 SKU_MAPPING 中添加映射");
    }

    public function normalizePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        if (isset(self::LEGACY_PERIOD_MAP[$period])) {
            return self::LEGACY_PERIOD_MAP[$period];
        }

        if (in_array($period, self::PERIODS, true)) {
            return $period;
        }

        throw new ApiException("不支持的周期: {$period}");
    }

    protected function findOrCreateUser(string $email): User
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            Log::info('[FakaBridge] 找到已有用户', ['email' => $email, 'user_id' => $user->id]);

            return $user;
        }

        if (!$this->config->autoCreateUser()) {
            throw new ApiException('用户不存在且未开启自动创建: ' . $email);
        }

        $userService = new UserService();
        $password = Str::random(16);

        $data = [
            'email' => $email,
            'password' => $password,
        ];

        $groupId = $this->config->defaultGroupId();
        if ($groupId !== null && $groupId > 0) {
            $data['group_id'] = $groupId;
        }

        $user = $userService->createUser($data);

        if (!$user->save()) {
            throw new ApiException('自动创建用户失败');
        }

        Log::info('[FakaBridge] 自动创建新用户', [
            'email' => $email,
            'user_id' => $user->id,
            'group_id' => $groupId,
        ]);

        return $user;
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        if ($sqlState === '23000' || $sqlState === '23505' || $driverCode === 1062) {
            return true;
        }

        return str_contains($message, 'Duplicate')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'unique constraint');
    }
}
