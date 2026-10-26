<?php

namespace Plugin\FakaBridge\Models;

use Illuminate\Database\Eloquent\Model;

class FakaOrder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    /** MoNexus refund / admin revoke. A reset revoke keeps the original subscription. */
    public const STATUS_REVOKED = 'revoked';

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
        'protocol_version',
        'client_id',
        'xboard_user_id',
        'semantic_hash',
        'result_payload',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'plan_id' => 'integer',
        'protocol_version' => 'integer',
    ];

    /**
     * Only COMPLETED is terminal. PROCESSING with trade_no may still mean
     * Xboard order stuck at 待支付 if paid()/open never really ran.
     */
    public function isTerminalSuccess(): bool
    {
        return $this->status === self::STATUS_COMPLETED && !empty($this->xboard_trade_no);
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function canResume(): bool
    {
        // Never re-open a revoked (refunded) provision.
        if ($this->status === self::STATUS_REVOKED) {
            return false;
        }

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

        // Stuck after createFromRequest: must re-enter ensurePaid/open.
        if ($this->status === self::STATUS_PROCESSING && !empty($this->xboard_trade_no)) {
            return true;
        }

        return false;
    }
}
