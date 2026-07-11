<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessingCacheEntry extends Model
{
    protected $fillable = ['cache_key', 'stage', 'content_hash', 'provider', 'model', 'payload', 'usage', 'expires_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'usage' => 'array', 'expires_at' => 'datetime'];
    }
}
