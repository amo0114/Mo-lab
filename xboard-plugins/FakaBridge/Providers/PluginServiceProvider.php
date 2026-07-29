<?php

namespace Plugin\FakaBridge\Providers;

use Illuminate\Support\ServiceProvider;
use Plugin\FakaBridge\Services\FakaBridgeConfig;
use Plugin\FakaBridge\Services\FakaOrderService;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakaBridgeConfig::class);
        $this->app->singleton(FakaOrderService::class);
    }

    public function boot(): void
    {
        // Routes / views loaded by PluginManager
    }
}
