<?php

namespace Plugin\FakaBridge;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Models\FakaOrder;

class Plugin extends AbstractPlugin
{
    public const CODE = 'faka_bridge';
    public const VERSION = '1.3.1';

    public function boot(): void
    {
        // paid() → OrderHandleJob::dispatchSync → open() fires this hook
        // while fulfill() still runs. trade_no must already be on faka_orders.
        $this->listen('order.open.after', function ($order) {
            try {
                $tradeNo = is_object($order)
                    ? (string) ($order->trade_no ?? '')
                    : (string) ($order['trade_no'] ?? '');

                if ($tradeNo === '') {
                    return;
                }

                $updated = FakaOrder::query()
                    ->where('xboard_trade_no', $tradeNo)
                    ->whereIn('status', [
                        FakaOrder::STATUS_PENDING,
                        FakaOrder::STATUS_PROCESSING,
                        FakaOrder::STATUS_FAILED,
                    ])
                    ->update([
                        'status' => FakaOrder::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'error_log' => null,
                        'updated_at' => now(),
                    ]);

                if ($updated) {
                    Log::info('[FakaBridge] 订单开通完成 (order.open.after)', [
                        'xboard_trade_no' => $tradeNo,
                        'rows' => $updated,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('[FakaBridge] order.open.after 钩子异常: ' . $e->getMessage());
            }
        });

        Log::info('[FakaBridge] 插件已启动', [
            'version' => self::VERSION,
            'code' => self::CODE,
        ]);
    }
}
