<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately NOT using WithoutModelEvents: User/SubscriptionInstance
    // rely on `creating` hooks to generate uuid/token, so seeding needs
    // model events enabled.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Safe production default: help articles only. No account, sellable
        // plan, node, supplier, or payment record is created implicitly.
        $this->call(KnowledgeSeeder::class);
    }
}
