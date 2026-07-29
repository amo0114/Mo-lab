<?php

namespace Plugin\FakaBridge\Providers;

use Illuminate\Support\ServiceProvider;
use Plugin\FakaBridge\Services\FakaOrderService;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakaOrderService::class);
    }

    public function boot(): void
    {
        // 路由和视图由 PluginManager 自动加载
    }
}
