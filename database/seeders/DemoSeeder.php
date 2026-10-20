<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder is available only in local and testing environments.');
        }

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'is_admin' => true,
            'is_owner' => true,
        ]);

        $shared = Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'device_limit' => 3,
            'prices' => ['month' => 1500, 'quarter' => 4500, 'year' => 18000],
            'sell' => true,
            'sort' => 1,
        ]);

        Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500, 'quarter' => 16500, 'year' => 66000],
            'resource_profile' => [
                'resource_type' => 'static_isp',
                'provider' => 'stub',
                'country' => 'US',
                'city' => 'San Francisco',
            ],
            'sell' => true,
            'sort' => 2,
        ]);

        foreach ([['共享节点｜洛杉矶 01', '198.51.100.10'], ['共享节点｜洛杉矶 02', '198.51.100.11']] as [$name, $host]) {
            Server::create([
                'name' => $name,
                'group_id' => $shared->group_id,
                'host' => $host,
                'port' => 443,
                'rate' => 1.00,
                'show' => true,
            ]);
        }

        $this->call(KnowledgeSeeder::class);
    }
}
