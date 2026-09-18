<?php

namespace App\Providers;

use App\Services\RetrievalConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Unit tests boot with intentionally partial config and cover
        // validation directly; every other entry point validates eagerly.
        if (! $this->app->runningUnitTests()) {
            RetrievalConfig::validate();
        }
    }
}
