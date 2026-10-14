<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Brand extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'website', 'status', 'banner_logo_disk', 'banner_logo_path',
        'banner_logo_mime_type', 'primary_color', 'voice', 'default_audience', 'palette',
    ];

    private static ?bool $profileAvailable = null;

    protected function casts(): array
    {
        return ['palette' => 'array'];
    }

    /** Production runs migrations as a separate step; voice, audience and palette wait for their columns. */
    public static function profileAvailable(): bool
    {
        return self::$profileAvailable ??= Schema::hasColumn('brands', 'voice');
    }

    /**
     * The brand palette, primary colour first. Brands saved before palettes existed fall back to their primary colour.
     *
     * @return list<string>
     */
    public function colors(): array
    {
        $palette = array_values(array_filter((array) ($this->palette ?? []), fn ($color) => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)));

        return $palette ?: array_values(array_filter([$this->primary_color]));
    }

    /** Voice and default audience every product of the brand inherits, without empty values. */
    public function profile(): array
    {
        return array_filter([
            'voice' => filled($this->voice ?? null) ? trim($this->voice) : null,
            'default_audience' => filled($this->default_audience ?? null) ? trim($this->default_audience) : null,
            'palette' => $this->colors() ?: null,
        ]);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
