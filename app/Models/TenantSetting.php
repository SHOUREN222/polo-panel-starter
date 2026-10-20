<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A 分站's own branding override (default connection = the tenant DB). Written
 * only in tenant context, for keys in Setting::BRANDING_KEYS.
 */
class TenantSetting extends Model
{
    protected $fillable = ['key', 'value'];
}
