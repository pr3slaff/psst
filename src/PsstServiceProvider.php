<?php

namespace Pr3slaff\Psst;

use Illuminate\Support\ServiceProvider;

final class PsstServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/psst.php', 'psst');
        $this->app->singleton(Psst::class);
        $this->app->singleton(Metrics::class);
        $this->app->singleton(Transport::class);
    }
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/psst.php' => config_path('psst.php')], 'psst-config');
        if (!$this->app->routesAreCached()) { $this->app['router']->get('/psst-health', HealthController::class)->name('psst.health'); }
    }
}
