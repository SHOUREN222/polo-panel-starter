<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Support\DefaultEmailTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:seed-email-templates {--force : Overwrite existing rows too}')]
#[Description('Seed the built-in HTML email templates into the database so they are active immediately')]
class SeedEmailTemplates extends Command
{
    public function handle(): void
    {
        $seeded = 0;
        $skipped = 0;

        foreach (array_keys(EmailTemplate::LABELS) as $key) {
            $exists = EmailTemplate::where('key', $key)->exists();

            if ($exists && ! $this->option('force')) {
                $this->line("skip (already customized): {$key}");
                $skipped++;

                continue;
            }

            $default = DefaultEmailTemplates::for($key);
            EmailTemplate::updateOrCreate(['key' => $key], $default);
            $this->line("seeded: {$key}");
            $seeded++;
        }

        $this->info("Done. seeded={$seeded}, skipped={$skipped}.");
    }
}
