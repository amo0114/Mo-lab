<?php

namespace Plugin\FakaBridge\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Services\FakaOrderService;
use Plugin\FakaBridge\Models\FakaOrder;
use App\Exceptions\ApiException;

class WebhookController extends Controller
{
    public function orderPaid(Request $request)
    {
        Log::info('[FakaBridge] 收到回调', [
            'ip' => $request->ip(),
            'order_no' => $request->input('order_no'),
        ]);

        try {
            $this->verifySignature($request);

            $externalOrderNo = $request->input('order_no');
            $email = strtolower(trim($request->input('email')));
            $sku = $request->input('sku');
            $period = $request->input('period', 'month_price');
            $paidAt = $request->input('paid_at');

            if (!$externalOrderNo || !$email || !$sku) {
                throw new ApiException('缺少必要参数: order_no, email, sku');
            }

            $existing = FakaOrder::where('external_order_no', $externalOrderNo)->first();
            if ($existing) {
                Log::info('[FakaBridge] 重复订单', [
                    'external_order_no' => $externalOrderNo,
                    'status' => $existing->status,
                ]);
                return response()->json([
                    'success' => true,
                    'trade_no' => $existing->xboard_trade_no,
                    'status' => $existing->status,
                    'message' => '订单已处理',
                ]);
            }

            $orderService = app(FakaOrderService::class);
            $result = $orderService->handleOrder(
                $externalOrderNo, $email, $sku, $period, $paidAt
            );

            return response()->json([
                'success' => true,
                'trade_no' => $result['trade_no'],
                'status' => $result['status'],
                'message' => $result['message'],
            ]);

        } catch (ApiException $e) {
            Log::warning('[FakaBridge] 业务异常: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[FakaBridge] 系统异常: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => '内部服务错误',
            ], 500);
        }
    }

    public function orderStatus(Request $request)
    {
        $this->verifySignature($request);

        $externalOrderNo = $request->input('order_no');
        $fakaOrder = FakaOrder::where('external_order_no', $externalOrderNo)->first();

        if (!$fakaOrder) {
            return response()->json([
                'success' => false,
                'error' => '订单不存在',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order_no' => $fakaOrder->external_order_no,
            'status' => $fakaOrder->status,
            'trade_no' => $fakaOrder->xboard_trade_no,
            'created_at' => $fakaOrder->created_at,
            'completed_at' => $fakaOrder->completed_at,
        ]);
    }

    protected function verifySignature(Request $request): void
    {
        $sign = $request->input('sign');
        if (!$sign) {
            throw new ApiException('缺少签名');
        }

        $pluginConfig = \App\Models\Plugin::where('code', 'faka_bridge')
            ->where('is_enabled', true)
            ->first();

        if (!$pluginConfig) {
            throw new ApiException('插件未启用');
        }

        $config = json_decode($pluginConfig->config, true) ?: [];
        $secret = $config['hmac_secret'] ?? null;
        if (!$secret) {
            throw new ApiException('插件未配置 HMAC 密钥');
        }

        $timeWindow = (int) ($config['time_window'] ?? 300);

        $params = $request->except(['sign']);
        ksort($params);

        $payload = '';
        foreach ($params as $key => $value) {
            $payload .= $key . '=' . $value . '&';
        }
        $payload = rtrim($payload, '&');

        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $sign)) {
            throw new ApiException('签名验证失败');
        }

        $paidAt = $request->input('paid_at');
        if ($paidAt && abs(time() - (int) $paidAt) > $timeWindow) {
            throw new ApiException('请求已过期');
        }
    }
}
