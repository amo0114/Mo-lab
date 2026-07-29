<?php

namespace Plugin\FakaBridge\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Models\FakaOrder;
use Plugin\FakaBridge\Services\FakaBridgeConfig;
use Plugin\FakaBridge\Services\FakaOrderService;

class WebhookController extends Controller
{
    public function __construct(
        protected FakaBridgeConfig $config,
        protected FakaOrderService $orderService
    ) {
    }

    public function orderPaid(Request $request)
    {
        Log::info('[FakaBridge] 收到回调', [
            'ip' => $request->ip(),
            'order_no' => $request->input('order_no'),
        ]);

        try {
            $this->verifySignature($request);

            $externalOrderNo = trim((string) $request->input('order_no'));
            $email = strtolower(trim((string) $request->input('email')));
            $sku = trim((string) $request->input('sku'));
            $period = (string) $request->input('period', 'monthly');
            $paidAt = $request->input('paid_at');

            if ($externalOrderNo === '' || $email === '' || $sku === '') {
                throw new ApiException('缺少必要参数: order_no, email, sku');
            }

            if (strlen($externalOrderNo) > 64) {
                throw new ApiException('order_no 过长');
            }

            $result = $this->orderService->handleOrder(
                $externalOrderNo,
                $email,
                $sku,
                $period,
                $paidAt !== null && $paidAt !== '' ? (int) $paidAt : null
            );

            return response()->json([
                'success' => true,
                'trade_no' => $result['trade_no'],
                'order_no' => $result['order_no'],
                'status' => $result['status'],
                'message' => $result['message'],
            ]);
        } catch (ApiException $e) {
            Log::warning('[FakaBridge] 业务异常: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] 系统异常: ' . $e->getMessage(), [
                'exception' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => '内部服务错误',
            ], 500);
        }
    }

    public function orderStatus(Request $request)
    {
        try {
            $this->verifySignature($request);

            $externalOrderNo = trim((string) $request->input('order_no'));
            if ($externalOrderNo === '') {
                throw new ApiException('缺少必要参数: order_no');
            }

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
                'error_log' => $fakaOrder->status === FakaOrder::STATUS_FAILED
                    ? $fakaOrder->error_log
                    : null,
            ]);
        } catch (ApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] orderStatus 异常: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => '内部服务错误',
            ], 500);
        }
    }

    /**
     * GET plan-capacity — MoNexus checkout precheck for Xboard capacity_limit.
     * Signed query: sku + paid_at + sign.
     */
    public function planCapacity(Request $request)
    {
        try {
            $this->verifySignature($request);

            $sku = trim((string) $request->input('sku'));
            if ($sku === '') {
                throw new ApiException('缺少必要参数: sku');
            }

            $result = $this->orderService->getPlanCapacity($sku);

            return response()->json(array_merge(['success' => true], $result));
        } catch (ApiException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('[FakaBridge] planCapacity 异常: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => '内部服务错误',
            ], 500);
        }
    }

    protected function verifySignature(Request $request): void
    {
        $this->config->assertReady();

        $sign = $request->input('sign');
        if (!$sign) {
            throw new ApiException('缺少签名');
        }

        $secret = $this->config->hmacSecret();
        $timeWindow = $this->config->timeWindow();

        $params = $request->except(['sign']);
        // Normalize scalar values for stable signing (arrays not expected)
        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                throw new ApiException('签名参数格式无效: ' . $key);
            }
            $parts[] = $key . '=' . $value;
        }
        $payload = implode('&', $parts);

        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, (string) $sign)) {
            throw new ApiException('签名验证失败');
        }

        $paidAt = $request->input('paid_at');
        if ($paidAt !== null && $paidAt !== '' && abs(time() - (int) $paidAt) > $timeWindow) {
            throw new ApiException('请求已过期');
        }
    }
}
