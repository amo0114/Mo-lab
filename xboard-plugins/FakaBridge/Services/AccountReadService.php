<?php

namespace Plugin\FakaBridge\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Plugin\FakaBridge\Models\FakaOrder;
use Plugin\FakaBridge\Plugin;

/** Read-only core adapter. Never resolves by email or creates a remote user. */
class AccountReadService
{
    public function __construct(protected FakaBridgeConfig $config) {}

    public function capabilities(): array
    {
        $readable = filter_var($this->config->get('v2_read_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && Schema::hasColumns((new Order)->getTable(), ['trade_no', 'user_id', 'status'])
            && Schema::hasColumns((new User)->getTable(), ['id', 'email', 'plan_id', 'banned', 'transfer_enable', 'u', 'd', 'expired_at'])
            && Schema::hasColumns('faka_orders', ['external_order_no', 'xboard_trade_no', 'status', 'completed_at'])
            && Schema::hasColumns('v2_plan', ['id', 'name']);
        $reset = new TrafficResetService($this->config);
        $canReset = $readable && $reset->available();
        $canReconcile = $reset->storageReady();
        return [
            'plugin_version' => Plugin::VERSION, 'build_id' => $this->buildId(),
            'core_adapter' => 'xboard-eloquent-read-v1',
            'capabilities' => [
                'resolve_by_order' => $readable, 'subscription_by_user_id' => $readable,
                'reset_idempotency' => $canReset, 'reset_status' => $canReconcile,
                'cancel_tombstone' => $canReconcile, 'paid_reset_by_user_id' => (new PaidResetService($this->config))->available(),
            ],
        ];
    }

    public function resolve(string $externalOrderNo): array
    {
        $this->assertReadable();
        $bridge = FakaOrder::where('external_order_no', $externalOrderNo)->first();
        if (!$bridge || !in_array($bridge->status, [FakaOrder::STATUS_COMPLETED, FakaOrder::STATUS_REVOKED], true)
            || !$bridge->completed_at || !$bridge->xboard_trade_no) {
            throw new ProtocolV2Exception('ACCOUNT_UNRESOLVED', 409);
        }
        $order = Order::where('trade_no', $bridge->xboard_trade_no)->first();
        if (!$order || !in_array((int) $order->status, [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $order->user_id)) {
            throw new ProtocolV2Exception('ACCOUNT_UNRESOLVED', 409);
        }
        $user = User::find($order->user_id);
        if (!$user) throw new ProtocolV2Exception('ACCOUNT_UNRESOLVED', 409);
        return ['external_order_no' => $externalOrderNo, 'trade_no' => (string) $bridge->xboard_trade_no,
            'source_status' => $bridge->status, 'account' => $this->account($user)];
    }

    public function subscription(string $userId): array
    {
        $this->assertReadable();
        $user = User::find($userId);
        if (!$user) throw new ProtocolV2Exception('ACCOUNT_NOT_FOUND', 404);
        $attributes = $user->getAttributes();
        $upload = $this->unsigned($attributes['u'] ?? null);
        $download = $this->unsigned($attributes['d'] ?? null);
        $total = $this->unsigned($attributes['transfer_enable'] ?? null);
        $used = $this->add($upload, $download);
        $expires = $attributes['expired_at'] === null ? null : $this->unsigned($attributes['expired_at']);
        $plan = $user->plan_id === null ? null : $user->plan()->first(['id', 'name']);
        $status = $user->banned ? 'blocked' : ($user->plan_id === null || !$plan ? 'no_plan'
            : ($expires !== null && $this->compare($expires, (string) time()) <= 0 ? 'expired' : 'active'));
        return ['account' => $this->account($user), 'subscription' => [
            'status' => $status, 'plan_id' => $user->plan_id === null ? null : (string) $user->plan_id,
            'plan_name' => $plan?->name, 'total_bytes' => $total, 'upload_bytes' => $upload,
            'download_bytes' => $download, 'used_bytes' => $used, 'remaining_bytes' => $this->subtract($total, $used),
            // Preserve 0 vs null: in the pinned core, 0 is expired, null is unlimited time.
            'expires_at' => $expires,
            'next_reset_at' => isset($attributes['next_reset_at']) ? $this->unsigned($attributes['next_reset_at']) : null,
            'reset_count' => isset($attributes['reset_count']) ? $this->unsigned($attributes['reset_count']) : null,
        ]];
    }

    private function assertReadable(): void
    {
        if (!$this->capabilities()['capabilities']['resolve_by_order']) {
            throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        }
    }

    private function account(User $user): array
    {
        return ['user_id' => (string) $user->getKey(), 'email' => (string) $user->email];
    }

    private function buildId(): string
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)) {
                $files[substr($file->getPathname(), strlen($root))] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function unsigned(mixed $value): string
    {
        $text = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]{0,18})$/D', $text)) {
            throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
        }
        return $text;
    }

    private function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    private function add(string $a, string $b): string
    {
        $out = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $out = ($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return $out;
    }

    private function subtract(string $a, string $b): string
    {
        if ($this->compare($a, $b) <= 0) return '0';
        $out = ''; $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $n = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $n < 0 ? 1 : 0; $out = (($n + 10) % 10) . $out;
        }
        return ltrim($out, '0') ?: '0';
    }
}
