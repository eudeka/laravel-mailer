<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider;

use EmailProvider\EmailProvider\Console\Commands\EmailProviderCommand;
use Illuminate\Support\ServiceProvider;

class EmailProviderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-provider.php', 'email-provider');

        $this->app->singleton(EmailProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/email-provider.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-provider');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'email-provider');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/email-provider.php' => config_path('email-provider.php'),
        ], ['email-provider', 'email-provider-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/email-provider'),
        ], ['email-provider', 'email-provider-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/email-provider'),
        ], ['email-provider', 'email-provider-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/email-provider'),
        ], ['email-provider', 'email-provider-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['email-provider', 'email-provider-migrations']);

        $this->commands([
            EmailProviderCommand::class,
        ]);
    }
}
