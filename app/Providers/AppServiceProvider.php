<?php

namespace App\Providers;

use App\Services\Agency\Ai\AiLeadExtractor;
use App\Services\Agency\Ai\NullAiLeadExtractor;
use Illuminate\Support\Facades\Log;
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
        //
    }
}
