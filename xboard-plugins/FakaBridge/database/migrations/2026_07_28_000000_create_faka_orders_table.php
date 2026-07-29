<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faka_orders', function (Blueprint $table) {
            $table->id();
            $table->string('external_order_no')->unique()->comment('MoNexus 订单号');
            $table->string('xboard_trade_no')->nullable()->comment('Xboard 订单号');
            $table->string('email')->comment('用户邮箱');
            $table->string('sku')->comment('MoNexus 商品 SKU');
            $table->integer('plan_id')->comment('Xboard 套餐 ID');
            $table->string('period')->comment('订阅周期');
            $table->string('status')->default('pending')->comment('状态: pending/processing/completed/failed');
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->text('error_log')->nullable()->comment('错误日志');
            $table->text('raw_payload')->nullable()->comment('原始回调数据');
            $table->timestamps();

            $table->index('email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faka_orders');
    }
};
