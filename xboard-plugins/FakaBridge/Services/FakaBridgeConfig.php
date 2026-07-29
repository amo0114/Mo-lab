<?php

namespace Plugin\FakaBridge\Services;

use App\Exceptions\ApiException;
use App\Models\Plugin;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginManager;

/**
 * Read FakaBridge runtime config from the enabled plugin instance / DB row.
 */
class FakaBridgeConfig
{
    public const CODE = 'faka_bridge';

    protected ?array $config = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        // Prefer in-memory plugin instance (already cast + setConfig by PluginManager)
        try {
            $plugins = app(PluginManager::class)->getEnabledPlugins();
            if (isset($plugins[self::CODE]) && $plugins[self::CODE] instanceof AbstractPlugin) {
                $cfg = $plugins[self::CODE]->getConfig();
                if (is_array($cfg) && $cfg !== []) {
                    return $this->config = $cfg;
                }
            }
        } catch (\Throwable) {
            // Fall through to DB
        }

        $row = Plugin::query()
            ->where('code', self::CODE)
            ->where('is_enabled', true)
            ->first();

        if (!$row) {
            throw new ApiException('插件未启用');
        }

        $this->config = json_decode($row->config ?? '{}', true) ?: [];

        return $this->config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function hmacSecret(): string
    {
        $secret = (string) $this->get('hmac_secret', '');
        if ($secret === '') {
            throw new ApiException('插件未配置 HMAC 密钥');
        }

        return $secret;
    }

    public function timeWindow(): int
    {
        return (int) $this->get('time_window', 300);
    }

    /**
     * Soft switch inside plugin config (in addition to is_enabled).
     */
    public function isBridgeEnabled(): bool
    {
        $enabled = $this->get('enabled', true);

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    public function autoCreateUser(): bool
    {
        return filter_var($this->get('auto_create_user', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function defaultGroupId(): ?int
    {
        $raw = $this->get('default_group_id', '');
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    public function assertReady(): void
    {
        $this->all();
        if (!$this->isBridgeEnabled()) {
            throw new ApiException('桥接已关闭');
        }
        $this->hmacSecret();
    }
}
