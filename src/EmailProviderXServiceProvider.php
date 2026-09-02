<?php

declare(strict_types=1);

namespace EmailProviderX\EmailProviderX;

use Illuminate\Support\ServiceProvider;

class EmailProviderXServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmailProviderX::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
