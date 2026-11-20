<?php

namespace App\Services\Agency\Ai;

/** AI_PROVIDER=none: the AI step is skipped. */
class NullAiLeadExtractor implements AiLeadExtractor
{
    public function extract(string $text): ?array
    {
        return null;
    }
}
