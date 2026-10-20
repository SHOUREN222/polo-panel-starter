<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant branding overrides. A 分站 stores its own site_name / logo /
 * theme color / support contact here; Setting::get falls back to the central
 * (platform) value for anything not overridden. Only branding keys land here
 * (Setting::BRANDING_KEYS) — platform settings stay central.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
