<?php

namespace Plugin\FakaBridge\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtocolV2Receiver
{
    public function __construct(protected FakaBridgeConfig $config) {}

    public function receive(Request $request): array
    {
        $this->config->assertReady();
        $headers = [];
        foreach (['version', 'timestamp', 'nonce', 'signature'] as $key) {
            $name = $key === 'version' ? 'x-monexus-signature-version' : 'x-monexus-' . $key;
            $values = $request->headers->all($name);
            if (count($values) !== 1 || !is_string($values[0])) {
                throw new ProtocolV2Exception('SIGNATURE_INVALID', 401);
            }
            $headers[$key] = $values[0];
        }
        $raw = $request->getContent();
        if (strlen($raw) > 8192) {
            throw new ProtocolV2Exception('BODY_TOO_LARGE', 413);
        }
        if (!preg_match('~^application/json(?:;\s*charset=utf-8)?$~iD', $request->header('content-type', ''))
            || $request->headers->has('content-encoding')) {
            throw new ProtocolV2Exception('CONTENT_TYPE_INVALID', 415);
        }
        if ($headers['version'] !== '2' || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $headers['timestamp'])
            || (int) $headers['timestamp'] > 9007199254740991) {
            throw new ProtocolV2Exception('SIGNATURE_INVALID', 401);
        }
        $nowMs = (int) floor(microtime(true) * 1000);
        $secret = $this->config->hmacSecret();
        if (!SignedProtocolV2::verify($request->getRealMethod(), $request->getRequestUri(),
            (int) $headers['timestamp'], $headers['nonce'], $raw, $headers['signature'], $secret, $nowMs)) {
            throw new ProtocolV2Exception('SIGNATURE_INVALID', 401);
        }

        // One shared database table across web workers; no process-local cache.
        // Retain longer than the full earliest-to-latest acceptance interval.
        $id = hash('sha256', hash('sha256', $secret) . ':' . $headers['nonce']);
        try {
            $inserted = DB::table('faka_protocol_nonces')->insertOrIgnore([
                'id' => $id, 'expires_at_ms' => $nowMs + 2 * SignedProtocolV2::WINDOW_MS + 1000,
            ]);
            // Bounded, indexed cleanup; deleting only fully expired envelopes.
            $expired = DB::table('faka_protocol_nonces')->where('expires_at_ms', '<', $nowMs)
                ->orderBy('expires_at_ms')->limit(100)->pluck('id');
            if ($expired->isNotEmpty()) DB::table('faka_protocol_nonces')->whereIn('id', $expired)->delete();
        } catch (\Throwable) {
            throw new ProtocolV2Exception('REPLAY_STORE_UNAVAILABLE', 503);
        }
        if ($inserted !== 1) throw new ProtocolV2Exception('REQUEST_REPLAYED', 409);

        try {
            $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolV2Exception('REQUEST_INVALID', 400);
        }
        if (!$body instanceof \stdClass) throw new ProtocolV2Exception('REQUEST_INVALID', 400);
        return (array) $body;
    }
}
