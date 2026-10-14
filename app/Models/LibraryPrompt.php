<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryPrompt extends Model
{
    protected $fillable = [
        'collection', 'source_key', 'pack', 'category', 'title', 'body', 'tier', 'position', 'credit', 'source_url',
        'video_url', 'poster_url', 'video_path', 'poster_path',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
