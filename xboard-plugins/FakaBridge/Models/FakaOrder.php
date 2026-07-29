<?php

namespace Plugin\FakaBridge\Models;

use Illuminate\Database\Eloquent\Model;

class FakaOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

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
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'plan_id' => 'integer',
    ];

    public function isTerminalSuccess(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PROCESSING,
        ], true) && !empty($this->xboard_trade_no);
    }

    public function canResume(): bool
    {
        if ($this->status === self::STATUS_PENDING) {
            return true;
        }

        // Failed before binding trade_no → safe to retry create+paid
        if ($this->status === self::STATUS_FAILED && empty($this->xboard_trade_no)) {
            return true;
        }

        // Bound trade_no but paid/open may have crashed → retry paid path carefully
        if ($this->status === self::STATUS_FAILED && !empty($this->xboard_trade_no)) {
            return true;
        }

        if ($this->status === self::STATUS_PROCESSING && !empty($this->xboard_trade_no)) {
            // Likely already paid; treat as success (idempotent)
            return false;
        }

        return false;
    }
}
