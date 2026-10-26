<?php

namespace Plugin\FakaBridge\Services;

/** Cross-language primitive. Nonce persistence and authorization belong to the receiver. */
class SignedProtocolV2
{
    public const WINDOW_MS = 300000;

    public static function sign(string $audience, string $method, string $path, int $timestampMs, string $nonce, string $rawBody, string $secret): string
    {
        $prefix = match ($audience) {
            'bot' => '/api/bot/v2/xboard/',
            'xboard' => '/plugin/faka-bridge/v2/',
            default => '',
        };
        if (PHP_INT_SIZE < 8 || $secret === '' || $prefix === '' || $method !== 'POST'
            || !str_starts_with($path, $prefix) || strlen($path) > 200
            || !preg_match('~^(?:/[a-z0-9-]+)+$~D', $path)
            || $timestampMs < 0 || $timestampMs > 9007199254740991
            || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Invalid protocol v2 envelope');
        }
        $payload = json_encode(['monexus-hmac-v2', $audience, $method, $path, $timestampMs,
            $nonce, hash('sha256', $rawBody)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $method, string $path, int $timestampMs, string $nonce, string $rawBody, string $signature, string $secret, int $nowMs): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || abs($nowMs - $timestampMs) > self::WINDOW_MS) {
            return false;
        }
        try {
            return hash_equals(self::sign('xboard', $method, $path, $timestampMs, $nonce, $rawBody, $secret), $signature);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
