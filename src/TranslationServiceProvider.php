<?php

namespace danhorrors\Translations;

use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(TranslationService::class, function () {
            return new TranslationService();
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \danhorrors\Translations\Commands\ExportTranslations::class,
                \danhorrors\Translations\Commands\ImportTranslations::class,
                \danhorrors\Translations\Commands\ExportMissingTranslations::class,
                \danhorrors\Translations\Commands\ExportUnusedTranslations::class,
            ]);
        }
        
        // Load routes and views from the package.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'translations');
    }
}
