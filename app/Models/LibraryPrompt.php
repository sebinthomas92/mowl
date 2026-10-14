<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryPrompt extends Model
{
    protected $fillable = [
        'collection', 'source_key', 'pack', 'category', 'title', 'body', 'tier', 'position', 'credit', 'source_url',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
