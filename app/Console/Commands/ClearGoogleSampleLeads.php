<?php

namespace App\Console\Commands;

use App\Services\Google\SampleGoogleLead;
use Illuminate\Console\Command;

class ClearGoogleSampleLeads extends Command
{
    protected $signature = 'google:clear-samples {--force : Do not ask for confirmation}';

    protected $description = 'Delete the sample Google leads (lead_id "sample-…"). Real leads are never touched.';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete all sample Google leads?')) {
            return self::FAILURE;
        }

        $this->info('Removed '.SampleGoogleLead::clear().' sample Google lead(s).');

        return self::SUCCESS;
    }
}
