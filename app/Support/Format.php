<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Format
{
    /** WhatsApp-style list timestamp: "3:45 pm", "Yesterday", "Mon", "12 Nov". */
    public static function shortTime(?CarbonInterface $t): string
    {
        return match (true) {
            $t === null => '',
            $t->isToday() => $t->format('g:i a'),
            $t->isYesterday() => 'Yesterday',
            $t->gt(now()->subDays(6)->startOfDay()) => $t->format('D'),
            default => $t->format('d M'),
        };
    }

    /** 45 → "45m", 135 → "2h 15m", 1500 → "1d 1h". */
    public static function duration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $m = (int) round($minutes);

        return match (true) {
            $m < 60 => "{$m}m",
            $m < 1440 => intdiv($m, 60).'h '.($m % 60).'m',
            default => intdiv($m, 1440).'d '.intdiv($m % 1440, 60).'h',
        };
    }

    public static function dayLabel(CarbonInterface $t): string
    {
        return match (true) {
            $t->isToday() => 'Today',
            $t->isYesterday() => 'Yesterday',
            default => $t->format('D, d M Y'),
        };
    }
}
