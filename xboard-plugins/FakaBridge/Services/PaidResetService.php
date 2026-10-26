<?php
namespace Plugin\FakaBridge\Services;

use App\Models\{Order,Plan,TrafficResetLog,User};
use App\Services\{OrderService,PlanService};
use Illuminate\Support\Facades\{DB,Schema};
use Plugin\FakaBridge\Models\FakaOrder;

/** Commercial reset only. All identity and replay decisions use stable IDs. */
class PaidResetService extends FakaOrderService
{
    public const ORDER_CORE_SHA256 = '50d712e59c90b60d71269dc08cb95aecd0b0808dde912145ee585fc2ec91b6bf';

    private function client(): string {
        $id = (string) $this->config->get('v2_client_id', 'monexus');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        return $id;
    }

    public function storageReady(): bool {
        $this->client();
        if (!Schema::hasColumns('faka_orders', ['protocol_version','client_id','xboard_user_id','semantic_hash','result_payload'])) return false;
        return (new FakaOrder)->getConnection() === DB::connection()
            && NativeResetAdapter::transactionalTables(['faka_orders']);
    }

    public function available(): bool {
        if (!filter_var($this->config->get('v2_paid_reset_enabled', false), FILTER_VALIDATE_BOOLEAN)
            || !filter_var($this->config->get('v2_read_enabled', false), FILTER_VALIDATE_BOOLEAN)
            || !$this->storageReady() || !(new NativeResetAdapter)->supported()) return false;
        $file = (new \ReflectionClass(OrderService::class))->getFileName();
        if (!$file || hash_file('sha256', $file) !== self::ORDER_CORE_SHA256) return false;
        foreach ([new User,new Plan,new Order,new TrafficResetLog] as $model) {
            if ($model->getConnection() !== DB::connection()) return false;
        }
        return NativeResetAdapter::transactionalTables(['v2_order']);
    }

    /** Delimited by a fixed JSON array, independent of request_id/nonce/email. */
    public static function payloadHash(array $body, string $client): string {
        return hash('sha256', json_encode(['paid_reset_v1', $client, $body['order_no'], $body['user_id'],
            $body['sku'], $body['expected_plan_id'], $body['period']], JSON_THROW_ON_ERROR));
    }

    private function mappedPlan(string $sku): string {
        try { $mapping = $this->resolveSku($sku, 'reset_traffic'); }
        catch (\Throwable) { throw new ProtocolV2Exception('REQUEST_INVALID', 400); }
        if ($mapping['period'] !== 'reset_traffic' || $mapping['plan_id'] < 1 || $mapping['plan_id'] > 2147483647) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
        return (string) $mapping['plan_id'];
    }

    private function eligibility(?User $user, ?Plan $plan): ?string {
        if (!$user) return 'ACCOUNT_NOT_FOUND';
        if (!$plan || $user->banned || (string) $user->plan_id !== (string) $plan->id
            || (int) $user->transfer_enable <= 0 || ($user->expired_at !== null && (int) $user->expired_at <= time())) return 'SUBSCRIPTION_INELIGIBLE';
        // Match Xboard's paid-reset policy. New-subscriber capacity is deliberately
        // absent: this does not add an account or a subscription.
        if (!(new PlanService($plan))->canResetTraffic($plan)) return 'RESET_NOT_FOR_SALE';
        return null;
    }

    public function preview(string $userId, string $sku): array {
        if (!$this->available()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        $planId = $this->mappedPlan($sku);
        $user = User::find($userId); $plan = Plan::find($planId);
        $reason = $this->eligibility($user, $plan);
        return ['preview'=>['user_id'=>$userId,'sku'=>$sku,'plan_id'=>$planId,'period'=>'reset_traffic',
            'eligible'=>$reason === null,'block_reason'=>$reason]];
    }

    public function operate(string $action, array $body): array {
        if (!$this->storageReady()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        $client = $this->client(); $hash = self::payloadHash($body, $client);
        // A cancellation must remain possible when the product, plan or user has
        // since disappeared. Only new execution needs current SKU eligibility.
        return DB::transaction(function () use ($action, $body, $client, $hash) {
            if ($action !== 'paid-reset-status') {
                // Also acquires SQLite's writer lock before reading the target.
                DB::table('faka_orders')->insertOrIgnore([
                    'external_order_no'=>$body['order_no'],'email'=>'','sku'=>$body['sku'],
                    'plan_id'=>(int) $body['expected_plan_id'],'period'=>'reset_traffic','status'=>'pending',
                    'protocol_version'=>2,'client_id'=>$client,'xboard_user_id'=>$body['user_id'],'semantic_hash'=>$hash,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $row = FakaOrder::where('external_order_no', $body['order_no'])->lockForUpdate()->first();
            if (!$row) return ['operation'=>$this->result($body, $hash, 'not_found')];
            if ($row->protocol_version !== 2 || $row->client_id !== $client || !is_string($row->semantic_hash)
                || !hash_equals($row->semantic_hash, $hash) || $row->xboard_user_id !== $body['user_id']
                || (string) $row->plan_id !== $body['expected_plan_id'] || $row->sku !== $body['sku'] || $row->period !== 'reset_traffic') {
                throw new ProtocolV2Exception('OPERATION_CONFLICT', 409);
            }
            if ($action === 'paid-reset-revoke') {
                // Reset cannot be reversed. Retain committed before/after evidence
                // for history; do not expire, extend or otherwise mutate the user.
                if ($row->status !== 'revoked') $this->save($row, ['status'=>'revoked']);
                return ['operation'=>$this->rowResult($row, $body, $hash)];
            }
            if ($action === 'paid-reset-status' || in_array($row->status, ['completed','failed','revoked'], true)) {
                return ['operation'=>$this->rowResult($row, $body, $hash)];
            }
            if (!$this->available()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
            if ($this->mappedPlan($body['sku']) !== $body['expected_plan_id']) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
            // Match the core open() order: Order -> User. No committed partial
            // commercial order is created by this path, so a pending bridge row
            // with a bound trade indicates corruption and must not be reopened.
            if ($row->xboard_trade_no) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
            $user = User::whereKey($body['user_id'])->lockForUpdate()->first();
            $plan = Plan::whereKey($body['expected_plan_id'])->lockForUpdate()->first();
            $reason = $this->eligibility($user, $plan);
            if ($reason) {
                $this->save($row, ['status'=>'failed','error_log'=>$reason]);
                return ['operation'=>$this->rowResult($row, $body, $hash)];
            }
            $before = $this->snapshot($user);
            $logsBefore = TrafficResetLog::where('user_id', $user->id)->count();
            $order = OrderService::createFromRequest($user, $plan, 'reset_traffic');
            $order->refresh();
            if ((string) $order->user_id !== $body['user_id'] || (string) $order->plan_id !== $body['expected_plan_id']
                || $order->period !== 'reset_traffic' || (int) $order->type !== Order::TYPE_RESET_TRAFFIC) throw new \RuntimeException('commercial order target changed');
            $this->save($row, ['xboard_trade_no'=>$order->trade_no,'status'=>'processing']);
            $this->ensurePaid($order, $body['order_no']);
            $user->refresh(); $order->refresh(); $after = $this->snapshot($user);
            // The pinned core ignores performReset(false). These postconditions
            // are checked BEFORE the same outer transaction can commit.
            if ((string) $order->user_id !== $body['user_id'] || (string) $order->plan_id !== $body['expected_plan_id']
                || $order->period !== 'reset_traffic' || (int) $order->type !== Order::TYPE_RESET_TRAFFIC
                || $order->trade_no !== $row->xboard_trade_no || (int) $order->status !== Order::STATUS_COMPLETED || $after['u'] !== '0' || $after['d'] !== '0'
                || (int) $before['reset_count'] === PHP_INT_MAX || (int) $after['reset_count'] !== (int) $before['reset_count'] + 1
                || TrafficResetLog::where('user_id', $user->id)->count() !== $logsBefore + 1) throw new \RuntimeException('commercial reset was not applied');
            foreach (['plan_id','transfer_enable','expired_at'] as $key) {
                if ($before[$key] !== $after[$key]) throw new \RuntimeException('commercial reset changed subscription');
            }
            $this->save($row, ['status'=>'completed','completed_at'=>now(),'error_log'=>null,
                'result_payload'=>json_encode(['before'=>$before,'after'=>$after], JSON_THROW_ON_ERROR)]);
            return ['operation'=>$this->rowResult($row, $body, $hash)];
        }, 3);
    }

    private function save(FakaOrder $row, array $attributes): void {
        if (!$row->fill($attributes)->save()) throw new \RuntimeException('bridge save failed');
    }

    private function snapshot(User $user): array {
        $out = [];
        foreach (['plan_id','transfer_enable','expired_at','last_reset_at','reset_count','next_reset_at','u','d'] as $key) {
            $value = $user->getRawOriginal($key);
            if ($value !== null && (!preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string) $value)
                || strlen((string) $value) === 19 && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
            $out[$key] = $value === null ? null : (string) $value;
        }
        foreach (['plan_id','transfer_enable','reset_count','u','d'] as $key) if ($out[$key] === null) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
        if ((int) $out['u'] > PHP_INT_MAX - (int) $out['d']) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
        return $out;
    }

    private function result(array $body, string $hash, string $status): array {
        return ['order_no'=>$body['order_no'],'user_id'=>$body['user_id'],'sku'=>$body['sku'],'plan_id'=>$body['expected_plan_id'],
            'period'=>'reset_traffic','payload_hash'=>$hash,'status'=>$status,'trade_no'=>null,'error_code'=>null,
            'before'=>null,'after'=>null,'completed_at'=>null];
    }
    private function rowResult(FakaOrder $row, array $body, string $hash): array {
        $out = $this->result($body, $hash, $row->status);
        $out['trade_no'] = $row->xboard_trade_no;
        $out['error_code'] = $row->status === 'failed' ? $row->error_log : null;
        if ($row->result_payload) {
            $result = json_decode($row->result_payload, true, 16, JSON_THROW_ON_ERROR);
            $out['before'] = $result['before']; $out['after'] = $result['after'];
        }
        $out['completed_at'] = $row->completed_at?->utc()->format('Y-m-d\TH:i:s\Z');
        return $out;
    }
}
