<?php

use App\Models\RelayNode;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Static-ISP relay went from "主力 + one optional 备用" (flat Settings) to N
 * relay nodes (this table). Reality identity (keys / sni) stays SHARED in
 * Settings — every relay node must present the same one so a customer's uuid
 * fails over between them without the onip IP changing. This migration also
 * back-fills the two existing settings-based nodes so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('label');                 // 主力 / 备用 / 节点3 …
            $table->unsignedInteger('priority')->default(0); // lower = tried first by the client
            $table->boolean('enabled')->default(true);
            $table->string('node_host');             // customer-facing host
            $table->unsignedInteger('node_port')->default(8446);
            $table->string('ssh_host');
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_password')->nullable(); // encrypted cast on the model
            $table->string('ssh_key_path')->nullable();
            $table->string('config_path')->default('/etc/xray-relay/config.json');
            $table->string('reload_cmd')->default('systemctl restart xray-relay');
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });

        $this->backfillFromSettings();
    }

    private function backfillFromSettings(): void
    {
        $now = now();

        // 主力: present whenever an SSH host was configured.
        $primarySsh = (string) Setting::get('relay_ssh_host', '');
        if ($primarySsh !== '') {
            DB::table('relay_nodes')->insert([
                'label' => '主力',
                'priority' => 0,
                'enabled' => true,
                'node_host' => (string) Setting::get('relay_node_host', ''),
                'node_port' => (int) Setting::get('relay_node_port', 8446),
                'ssh_host' => $primarySsh,
                'ssh_port' => (int) Setting::get('relay_ssh_port', 22),
                'ssh_user' => (string) Setting::get('relay_ssh_user', 'root'),
                // Stored plaintext here; the model re-encrypts on next save. The
                // model reads it through the 'encrypted' cast, which tolerates a
                // legacy plaintext value on first read only if we pre-encrypt.
                // Simplivity: write via the model below instead.
                'ssh_password' => null,
                'ssh_key_path' => (string) Setting::get('relay_ssh_key_path', '') ?: null,
                'config_path' => (string) Setting::get('relay_config_path', '/etc/xray-relay/config.json'),
                'reload_cmd' => (string) Setting::get('relay_reload_cmd', 'systemctl restart xray-relay'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 备用: only when it was enabled AND had a host + SSH target.
        $backupSsh = (string) Setting::get('relay_backup_ssh_host', '');
        $backupHost = (string) Setting::get('relay_backup_node_host', '');
        if (Setting::getBool('relay_backup_enabled', false) && $backupSsh !== '' && $backupHost !== '') {
            DB::table('relay_nodes')->insert([
                'label' => '备用',
                'priority' => 1,
                'enabled' => true,
                'node_host' => $backupHost,
                'node_port' => (int) Setting::get('relay_backup_node_port', (int) Setting::get('relay_node_port', 8446)),
                'ssh_host' => $backupSsh,
                'ssh_port' => (int) Setting::get('relay_backup_ssh_port', 22),
                'ssh_user' => (string) Setting::get('relay_backup_ssh_user', 'root'),
                'ssh_password' => null,
                'ssh_key_path' => (string) Setting::get('relay_backup_ssh_key_path', '') ?: null,
                'config_path' => (string) Setting::get('relay_backup_config_path', Setting::get('relay_config_path', '/etc/xray-relay/config.json')),
                'reload_cmd' => (string) Setting::get('relay_backup_reload_cmd', Setting::get('relay_reload_cmd', 'systemctl restart xray-relay')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Passwords are re-written (encrypted) via the model, since the
        // 'encrypted' cast can't be produced with a raw DB insert.
        foreach (RelayNode::all() as $node) {
            if ($node->label === '主力') {
                $pw = (string) Setting::get('relay_ssh_password', '');
            } else {
                $pw = (string) Setting::get('relay_backup_ssh_password', '');
            }
            if ($pw !== '') {
                $node->ssh_password = $pw; // encrypted by the cast on save
                $node->save();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_nodes');
    }
};
