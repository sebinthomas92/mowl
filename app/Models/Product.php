<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Product extends Model
{
    protected $fillable = ['brand_id', 'name', 'price', 'summary'];

    private static ?bool $archiveAvailable = null;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** Production runs migrations as a separate step; archiving stays hidden until the column exists. */
    public static function archiveAvailable(): bool
    {
        return self::$archiveAvailable ??= Schema::hasColumn('products', 'archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    public function campaignPacks(): HasMany
    {
        return $this->hasMany(CampaignPack::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function resourceLinks(): HasMany
    {
        return $this->hasMany(ProductResourceLink::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProductFact::class);
    }

    public function hubShares(): HasMany
    {
        return $this->hasMany(ProductHubShare::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * The product photo shown in the hero and the client hub: the first image whose file still exists.
     * Older imports can point at files that are gone, which used to render as a broken image.
     */
    public function coverImage(): ?MediaAsset
    {
        $candidates = $this->mediaAssets
            ->filter(fn (MediaAsset $asset) => str_starts_with((string) $asset->mime_type, 'image/') && $asset->status !== 'failed')
            ->sortBy('id')->take(4)->values();
        if ($candidates->isEmpty()) {
            return null;
        }
        $key = 'product-cover:'.$this->id.':'.$candidates->pluck('id')->implode('-');
        $id = Cache::remember($key, now()->addMinutes(10), function () use ($candidates) {
            foreach ($candidates as $asset) {
                try {
                    if (Storage::disk($asset->disk)->exists($asset->path)) {
                        return $asset->id;
                    }
                } catch (Throwable) {
                    // A disk that cannot be reached counts as missing.
                }
            }

            return 0;
        });

        return $candidates->firstWhere('id', $id);
    }
}
