<?php

use Illuminate\Support\Facades\Schedule;

// Needs a cron entry: * * * * * php artisan schedule:run   (locally: php artisan schedule:work)
Schedule::command('leads:send-reminders')->everyThirtyMinutes()->withoutOverlapping();
