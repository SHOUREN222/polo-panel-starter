<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Announcements are platform infrastructure info (node maintenance, service
 * changes) that every customer needs to see regardless of which 分站
 * subdomain they're on — always read/written from the central DB, even
 * inside a tenant context. No 分站-specific announcements.
 */
class Notice extends Model
{
    use CentralConnection;

    protected $fillable = ['title', 'content', 'show', 'sort'];

    protected function casts(): array
    {
        return ['show' => 'boolean'];
    }
}
