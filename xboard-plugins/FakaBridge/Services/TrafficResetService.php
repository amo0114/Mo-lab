<?php
namespace Plugin\FakaBridge\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrafficResetService
{
    public function __construct(protected FakaBridgeConfig $config, protected NativeResetAdapter $native = new NativeResetAdapter()) {}
    public function available(): bool
    {
        return filter_var($this->config->get('v2_reset_enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $this->storageReady() && count($this->plans()) > 0 && $this->native->supported();
    }
    private function plans(): array {
        $raw = (string) $this->config->get('v2_reset_plan_ids', '');
        return preg_match('/^[1-9][0-9]{0,18}(?:,[1-9][0-9]{0,18})*$/D', $raw) ? explode(',', $raw) : [];
    }
    private function client(): string {
        $id = (string) $this->config->get('v2_client_id', 'monexus');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        return $id;
    }
    public function storageReady(): bool {
        $this->client();
        if (!Schema::hasTable('faka_traffic_reset_operations') || !Schema::hasTable('faka_traffic_reset_consumptions')) return false;
        return NativeResetAdapter::transactionalTables(['faka_traffic_reset_operations','faka_traffic_reset_consumptions']);
    }
    private function snapshot(User $user): array {
        $out = [];
        foreach (['plan_id', 'transfer_enable', 'expired_at', 'last_reset_at', 'reset_count', 'next_reset_at', 'u', 'd'] as $key) {
            $value = $user->getRawOriginal($key);
            if ($value !== null && (!preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string) $value) || strlen((string) $value) === 19 && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
            $out[$key] = $value === null ? null : (string) $value;
        }
        if ($out['u'] === null || $out['d'] === null || $out['transfer_enable'] === null || $out['reset_count'] === null) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
        // Native logs use PHP integers, unlike the read-only string arithmetic.
        // Reject a usage sum that cannot be represented by the audited core.
        if ((int) $out['u'] > PHP_INT_MAX - (int) $out['d']) throw new ProtocolV2Exception('CORE_DATA_INVALID', 503);
        return $out;
    }
    private function fingerprint(array $snapshot): string {
        return hash('sha256', json_encode(array_slice($snapshot, 0, 6), JSON_THROW_ON_ERROR));
    }
    private function eligibility(User $user): ?string {
        if ($user->banned || !$user->plan || !in_array((string) $user->plan_id, $this->plans(), true)) return 'SUBSCRIPTION_INELIGIBLE';
        if ($user->expired_at !== null && (int) $user->expired_at <= time()) return 'SUBSCRIPTION_INELIGIBLE';
        // Let the native scheduled reset happen first, then request a new intent.
        if ($user->next_reset_at !== null && (int) $user->next_reset_at <= time()) return 'SUBSCRIPTION_CHANGED';
        return null;
    }
    public function preview(string $id): array {
        if (!$this->available()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        $user = User::find($id);
        if (!$user) throw new ProtocolV2Exception('ACCOUNT_NOT_FOUND', 404);
        $snapshot = $this->snapshot($user);
        $reason = $this->eligibility($user);
        if (!$reason && (int) $snapshot['u'] === 0 && (int) $snapshot['d'] === 0) $reason = 'NOTHING_TO_RESET';
        return ['preview' => ['user_id' => $id, 'plan_id' => $snapshot['plan_id'], 'fingerprint' => $this->fingerprint($snapshot),
            'eligible' => $reason === null, 'block_reason' => $reason, 'snapshot' => $snapshot]];
    }
    public static function payloadHash(array $body): string {
        return hash('sha256', json_encode(array_map(fn ($key) => $body[$key],
            ['operation_id','actor_ref','user_id','benefit_code','expected_plan_id','subscription_fingerprint']), JSON_THROW_ON_ERROR));
    }
    public function operate(string $action, array $body): array {
        if (!$this->storageReady()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
        $client = $this->client(); $hash = self::payloadHash($body);
        return DB::transaction(function () use ($action, $body, $client, $hash) {
            $query = fn () => DB::table('faka_traffic_reset_operations')->where('client_id', $client)->where('operation_id', $body['operation_id']);
            if ($action !== 'traffic-reset-status') {
                // First DML precedes all mutable reads: SQLite acquires its
                // database writer lock here (FOR UPDATE alone is not a lock).
                // Claim/cancel with the same unique row. No status-not-found can
                // release a local reservation; a cancel tombstone can.
                $query()->insertOrIgnore(['client_id'=>$client,'operation_id'=>$body['operation_id'],'payload_hash'=>$hash,
                    'actor_ref'=>$body['actor_ref'],'user_id'=>$body['user_id'],'benefit_code'=>$body['benefit_code'],
                    'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            }
            $row = $query()->lockForUpdate()->first();
            if (!$row) return ['operation' => $this->result($body['operation_id'], $hash, 'not_found')];
            if (!hash_equals($row->payload_hash, $hash)) throw new ProtocolV2Exception('OPERATION_CONFLICT', 409);
            if (in_array($row->status, ['succeeded','failed','cancelled'], true)) return ['operation' => $this->rowResult($row)];
            if ($action === 'traffic-reset-status') throw new ProtocolV2Exception('BRIDGE_UNAVAILABLE', 503);
            if ($action === 'traffic-reset-cancel') {
                $query()->update(['status'=>'cancelled','error_code'=>'CANCELLED','completed_at'=>now(),'updated_at'=>now()]);
                return ['operation'=>$this->rowResult($query()->first())];
            }
            if (!$this->available()) throw new ProtocolV2Exception('CAPABILITY_UNAVAILABLE', 503);
            $user = User::whereKey($body['user_id'])->lockForUpdate()->first();
            $before = $user ? $this->snapshot($user) : null;
            $reason = !$user ? 'ACCOUNT_NOT_FOUND' : $this->eligibility($user);
            if (!$reason && ((string) $user->plan_id !== $body['expected_plan_id'] || !hash_equals($this->fingerprint($before), $body['subscription_fingerprint']))) $reason = 'SUBSCRIPTION_CHANGED';
            if (!$reason && (int) $before['u'] === 0 && (int) $before['d'] === 0) $reason = 'NOTHING_TO_RESET';
            if (!$reason) {
                // A single insert enforces both actor and remote-account lifetime
                // uniqueness, including concurrent operations on different users.
                $consumed = DB::table('faka_traffic_reset_consumptions')->insertOrIgnore(['client_id'=>$client,'benefit_code'=>$body['benefit_code'],
                    'actor_ref'=>$body['actor_ref'],'user_id'=>$body['user_id'],'operation_id'=>$body['operation_id'],'created_at'=>now(),'updated_at'=>now()]);
                if ($consumed !== 1) $reason = 'BENEFIT_ALREADY_CONSUMED';
            }
            if ($reason) {
                $query()->update(['status'=>'failed','error_code'=>$reason,'completed_at'=>now(),'updated_at'=>now()]);
                return ['operation'=>$this->rowResult($query()->first())];
            }
            // Nested native transaction, core logs, counters, consumption and
            // operation success all roll back together on false/exception/crash.
            $this->native->reset($user);
            $user->refresh(); $after = $this->snapshot($user);
            if ($after['u'] !== '0' || $after['d'] !== '0' || (int) $before['reset_count'] === PHP_INT_MAX
                || (int) $after['reset_count'] !== (int) $before['reset_count'] + 1) throw new \RuntimeException('native reset was not applied');
            foreach (['plan_id','transfer_enable','expired_at'] as $key) {
                if ($before[$key] !== $after[$key]) throw new \RuntimeException('native changed subscription');
            }
            $query()->update(['status'=>'succeeded','before_json'=>json_encode($before, JSON_THROW_ON_ERROR),
                'after_json'=>json_encode($after, JSON_THROW_ON_ERROR),'completed_at'=>now(),'updated_at'=>now()]);
            return ['operation'=>$this->rowResult($query()->first())];
        });
    }
    private function result(string $id, string $hash, string $status): array {
        return ['operation_id'=>$id,'payload_hash'=>$hash,'status'=>$status,'error_code'=>null,'before'=>null,'after'=>null,'completed_at'=>null];
    }
    private function rowResult(object $row): array {
        return ['operation_id'=>$row->operation_id,'payload_hash'=>$row->payload_hash,'status'=>$row->status,'error_code'=>$row->error_code,
            'before'=>$row->before_json ? json_decode($row->before_json, true, 16, JSON_THROW_ON_ERROR) : null,
            'after'=>$row->after_json ? json_decode($row->after_json, true, 16, JSON_THROW_ON_ERROR) : null,
            'completed_at'=>$row->completed_at ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row->completed_at . ' UTC')) : null];
    }
}
