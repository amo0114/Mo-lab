<?php

namespace Plugin\FakaBridge;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Plugin\FakaBridge\Models\FakaOrder;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->listen('order.open.after', function ($order) {
            try {
                $fakaOrder = FakaOrder::where('xboard_trade_no', $order->trade_no)->first();
                if ($fakaOrder) {
                    $fakaOrder->update([
                        'status' => 'completed',
                        'xboard_trade_no' => $order->trade_no,
                        'completed_at' => now(),
                    ]);
                    Log::info("[FakaBridge] 订单开通完成", [
                        'faka_order_no' => $fakaOrder->external_order_no,
                        'xboard_trade_no' => $order->trade_no,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("[FakaBridge] order.open.after 钩子异常: " . $e->getMessage());
            }
        });

        Log::info('[FakaBridge] 插件已启动');
    }
}
