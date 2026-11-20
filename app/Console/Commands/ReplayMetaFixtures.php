<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMetaWebhook;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Console\Command;

class ReplayMetaFixtures extends Command
{
    protected $signature = 'meta:replay
        {fixtures?* : Fixture names without .json (default: all in tests/fixtures/meta)}
        {--queue : Push to the queue instead of processing immediately}';

    protected $description = 'Mock mode: replay Meta webhook JSON fixtures through the real processing pipeline';

    public function handle(): int
    {
        if (config('leads.mode') === 'live') {
            $this->error('meta:replay only runs in APP_MODE=mock.');

            return self::FAILURE;
        }

        $dir = base_path('tests/fixtures/meta');
        $names = $this->argument('fixtures') ?: array_map(fn ($f) => basename($f, '.json'), glob("{$dir}/*.json"));

        foreach ($names as $name) {
            $file = "{$dir}/{$name}.json";
            if (! is_file($file)) {
                $this->warn("Fixture not found: {$name}");

                continue;
            }

            [$leads, $messages] = [Lead::count(), Message::count()];
            $payload = self::load($file);
            $this->option('queue') ? ProcessMetaWebhook::dispatch($payload) : ProcessMetaWebhook::dispatchSync($payload);

            $this->line(sprintf('%-28s %s', $name, $this->option('queue')
                ? 'queued'
                : sprintf('leads +%d, messages +%d', Lead::count() - $leads, Message::count() - $messages)));
        }

        return self::SUCCESS;
    }

    /** Fixtures use "__NOW__" (seconds) / "__NOW_MS__" so replayed messages are fresh (keeps the 24h reply window open). */
    public static function load(string $file): array
    {
        $json = strtr(file_get_contents($file), [
            '__NOW_MS__' => (string) now()->getTimestampMs(),
            '__NOW__' => (string) now()->getTimestamp(),
        ]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
