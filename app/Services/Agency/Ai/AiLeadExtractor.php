<?php

namespace App\Services\Agency\Ai;

/**
 * Optional AI step for agency texts the rules couldn't parse cleanly.
 * Implementations read config('leads.ai.api_key' / 'leads.ai.model') and are bound in AppServiceProvider by AI_PROVIDER.
 */
interface AiLeadExtractor
{
    /**
     * @return list<array{name?: ?string, phone?: ?string, city?: ?string}>|null null = unavailable or unsure (rules result is kept)
     */
    public function extract(string $text): ?array;
}
