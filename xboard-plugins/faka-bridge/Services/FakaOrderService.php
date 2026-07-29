<?php

namespace Plugin\FakaBridge\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugin\FakaBridge\Models\FakaOrder;
use App\Exceptions\ApiException;

class FakaOrderService
{
    const SKU_MAPPING = [
        'aster-basic-monthly'     => ['plan_id' => 3, 'period' => 'month_price'],
        'aster-basic-quarterly'   => ['plan_id' => 3, 'period' => 'quarter_price'],
        'aster-basic-half-yearly' => ['plan_id' => 3, 'period' => 'half_year_price'],
        'aster-basic-yearly'      => ['plan_id' => 3, 'period' => 'year_price'],
        'aster-pro-monthly'       => ['plan_id' => 4, 'period' => 'month_price'],
        'aster-pro-quarterly'     => ['plan_id' => 4, 'period' => 'quarter_price'],
        'aster-pro-half-yearly'   => ['plan_id' => 4, 'period' => 'half_year_price'],
        'aster-pro-yearly'        => ['plan_id' => 4, 'period' => 'year_price'],
        'aster-legend-monthly'    => ['plan_id' => 5, 'period' => 'month_price'],
        'aster-legend-quarterly'  => ['plan_id' => 5, 'period' => 'quarter_price'],
        'aster-legend-yearly'     => ['plan_id' => 5, 'period' => 'year_price'],
    ];

    public function handleOrder(
        string $externalOrderNo,
        string $email,
        string $sku,
        string $period = 'month_price',
        ?int $paidAt = null
    ): array {
        return DB::transaction(function () use ($externalOrderNo, $email, $sku, $period, $paidAt) {
            $mapping = $this->resolveSku($sku, $period);
            $planId = $mapping['plan_id'];
            $mappedPeriod = $mapping['period'];

            $user = $this->findOrCreateUser($email);

            $plan = Plan::find($planId);
            if (!$plan) {
                throw new ApiException("套餐不存在 (plan_id={$planId})");
            }

            $order = OrderService::createFromRequest($user, $plan, $mappedPeriod);

            $orderService = new OrderService($order);
            $callbackNo = 'faka:' . $externalOrderNo;
            if (!$orderService->paid($callbackNo)) {
                throw new ApiException('订单支付失败');
            }

            FakaOrder::create([
                'external_order_no' => $externalOrderNo,
                'xboard_trade_no'   => $order->trade_no,
                'email'             => $email,
                'sku'               => $sku,
                'plan_id'           => $planId,
                'period'            => $mappedPeriod,
                'status'            => 'processing',
                'paid_at'           => $paidAt ? date('Y-m-d H:i:s', $paidAt) : now(),
                'raw_payload'       => json_encode([
                    'sku'    => $sku,
                    'period' => $period,
                    'paid_at' => $paidAt,
                ]),
            ]);

            Log::info('[FakaBridge] 订单处理成功', [
                'external_order_no' => $externalOrderNo,
                'xboard_trade_no'   => $order->trade_no,
                'email'             => $email,
                'plan_id'           => $planId,
                'xboard_user_id'    => $user->id,
            ]);

            return [
                'trade_no' => $order->trade_no,
                'status'   => 'processing',
                'message'  => '订单已创建并开始处理',
            ];
        });
    }

    protected function resolveSku(string $sku, string $period): array
    {
        if (isset(self::SKU_MAPPING[$sku])) {
            return self::SKU_MAPPING[$sku];
        }

        if (preg_match('/^plan-(\d+)-(.+)$/', $sku, $matches)) {
            return [
                'plan_id' => (int) $matches[1],
                'period'  => $matches[2],
            ];
        }

        throw new ApiException("未配置的 SKU: {$sku}，请在 SKU_MAPPING 中添加映射");
    }

    protected function findOrCreateUser(string $email): User
    {
        $email = strtolower(trim($email));

        $user = User::where('email', $email)->first();
        if ($user) {
            Log::info('[FakaBridge] 找到已有用户', ['email' => $email, 'user_id' => $user->id]);
            return $user;
        }

        $userService = new UserService();
        $password = Str::random(16);

        $user = $userService->createUser([
            'email'    => $email,
            'password' => $password,
        ]);

        if (!$user->save()) {
            throw new ApiException('自动创建用户失败');
        }

        Log::info('[FakaBridge] 自动创建新用户', [
            'email'     => $email,
            'user_id'   => $user->id,
        ]);

        return $user;
    }
}
