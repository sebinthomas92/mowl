<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentComment extends Model
{
    protected $fillable = ['content_version_id', 'workspace_id', 'user_id', 'author_name', 'author_role', 'body'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'content_version_id');
    }
}
