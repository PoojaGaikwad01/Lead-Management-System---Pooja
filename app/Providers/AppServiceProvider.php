<?php

namespace App\Providers;

use App\Services\Agency\Ai\AiLeadExtractor;
use App\Services\Agency\Ai\NullAiLeadExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pluggable AI step for agency parsing. Add a provider by implementing AiLeadExtractor and mapping it here.
        $this->app->bind(AiLeadExtractor::class, function () {
            $provider = config('leads.ai.provider') ?: 'none';

            if ($provider !== 'none' && config('leads.mode') === 'live') {
                Log::warning("AI_PROVIDER={$provider} has no implementation yet; skipping the AI step.");
            }

            return new NullAiLeadExtractor;
        });
    }

    public function boot(): void
    {
        // Numeric ids only: on PostgreSQL a non-numeric id would be a query error (500) instead of a 404.
        foreach (['lead', 'user', 'branch', 'message'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        // Production is always served over HTTPS (Render terminates TLS at its proxy).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
