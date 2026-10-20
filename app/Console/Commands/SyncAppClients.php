<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppClientFromGithub;
use App\Models\AppClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-clients')]
#[Description('Check GitHub clients and quarantine new release assets for review')]
class SyncAppClients extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $clients = AppClient::where('source_type', AppClient::SOURCE_GITHUB)
            ->whereNotNull('github_repo')
            ->whereNotNull('asset_pattern')
            ->get();

        foreach ($clients as $client) {
            SyncAppClientFromGithub::dispatch($client->id);
        }

        $this->info(sprintf('Dispatched quarantine checks for %d GitHub-sourced clients.', $clients->count()));
    }
}
