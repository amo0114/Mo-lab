<?php

namespace Plugin\FakaBridge\Models;

use Illuminate\Database\Eloquent\Model;

class FakaOrder extends Model
{
    protected $table = 'faka_orders';

    protected $fillable = [
        'external_order_no',
        'xboard_trade_no',
        'email',
        'sku',
        'plan_id',
        'period',
        'status',
        'paid_at',
        'completed_at',
        'error_log',
        'raw_payload',
    ];

    protected $casts = [
        'paid_at'      => 'datetime',
        'completed_at' => 'datetime',
    ];
}
