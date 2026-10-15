<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Brand;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create or edit a brand: the voice, default audience and palette every product of the brand inherits.
 */
class BrandEditor extends Component
{
    use InteractsWithWorkspace;
    use WithFileUploads;

    public const MAX_COLORS = 5;

    #[Locked]
    public ?int $brandId = null;

    public string $name = '';

    public string $website = '';

    public string $voice = '';

    public string $defaultAudience = '';

    /**
     * Palette colours keyed by a stable id ("c1", "c2", ...), in display order. Rows bind to
     * colors.{id}, so removing a colour never shifts another row's binding, and a late update
     * from a removed row can be recognised and dropped.
     *
     * @var array<string, string>
     */
    public array $colors = [];

    #[Locked]
    public int $nextColor = 0;

    /** @var list<string> */
    #[Locked]
    public array $removedColors = [];

    public $logo = null;

    public function mount(?Brand $brand = null): void
    {
        $workspace = $this->currentWorkspace();
        if (! $brand?->exists) {
            abort_if($workspace->brands()->count() >= config('campaigns.brand_limit'), 403, 'This workspace has reached its brand limit.');
            $this->setPalette(['#D9381E']);

            return;
        }

        abort_unless($brand->workspace_id === $workspace->id, 404);
        $this->brandId = $brand->id;
        $this->name = $brand->name;
        $this->website = (string) $brand->website;
        $this->voice = (string) ($brand->voice ?? '');
        $this->defaultAudience = (string) ($brand->default_audience ?? '');
        $this->setPalette(array_map('strtoupper', $brand->colors()));
    }

    public function addColor(): void
    {
        if (count($this->colors) < self::MAX_COLORS) {
            $this->colors['c'.(++$this->nextColor)] = '#FFFFFF';
        }
    }

    public function removeColor(string $id): void
    {
        if (array_key_exists($id, $this->colors)) {
            unset($this->colors[$id]);
            $this->removedColors[] = $id;
            $this->resetValidation('colors.'.$id);
        }
    }

    /**
     * Colour inputs send updates on a debounce, so one can arrive after its row was removed. Livewire
     * has already written it by now; drop it so a removed colour never comes back, and keep every
     * value a plain string under an id this editor issued.
     */
    public function updatedColors(mixed $value, ?string $key = null): void
    {
        $this->colors = array_filter(
            array_map(fn ($color) => is_scalar($color) ? (string) $color : '', $this->colors),
            fn ($id) => $this->issuedColor($id),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<string> */
    public function palette(): array
    {
        return array_values(array_map(fn ($color) => is_scalar($color) ? strtoupper(trim((string) $color)) : '', $this->colors));
    }

    public function save()
    {
        $this->colors = array_combine(array_keys($this->colors), $this->palette());
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'voice' => ['nullable', 'string', 'max:1000'],
            'defaultAudience' => ['nullable', 'string', 'max:500'],
            'colors' => ['array', 'max:'.self::MAX_COLORS],
            'colors.*' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/webp', 'max:5120'],
        ], [
            'colors.max' => 'A brand can have up to '.self::MAX_COLORS.' colours.',
            'colors.*.regex' => 'Use a six-digit hex colour, such as #D9381E.',
            'colors.*.required' => 'Use a six-digit hex colour, such as #D9381E.',
            'colors.*.string' => 'Use a six-digit hex colour, such as #D9381E.',
        ]);
        $palette = array_values($data['colors'] ?? []);

        $workspace = $this->currentWorkspace();
        $attributes = [
            'name' => trim($data['name']),
            'website' => filled($data['website']) ? trim($data['website']) : null,
            'primary_color' => $palette[0] ?? null,
        ];
        if (Brand::profileAvailable()) {
            $attributes += [
                'voice' => filled($data['voice']) ? trim($data['voice']) : null,
                'default_audience' => filled($data['defaultAudience']) ? trim($data['defaultAudience']) : null,
                // An empty list (not null) records that the palette was cleared on purpose.
                'palette' => $palette,
            ];
        }

        if ($this->brandId) {
            $brand = $workspace->brands()->findOrFail($this->brandId);
            $brand->update($attributes);
            $event = 'brand_updated';
        } else {
            if ($workspace->brands()->count() >= config('campaigns.brand_limit')) {
                throw ValidationException::withMessages(['name' => 'This workspace has reached its brand limit.']);
            }
            $brand = $workspace->brands()->create($attributes);
            $event = 'brand_created';
        }

        if ($this->logo) {
            $disk = config('campaigns.banners.disk');
            $extension = strtolower($this->logo->extension() ?: 'png');
            $path = $this->logo->storeAs("brand-assets/{$workspace->id}/{$brand->id}", 'banner-logo.'.$extension, $disk);
            if ($brand->banner_logo_path && ($brand->banner_logo_path !== $path || $brand->banner_logo_disk !== $disk)) {
                Storage::disk($brand->banner_logo_disk)->delete($brand->banner_logo_path);
            }
            $brand->update(['banner_logo_disk' => $disk, 'banner_logo_path' => $path, 'banner_logo_mime_type' => $this->logo->getMimeType()]);
            $this->logo = null;
        }

        $workspace->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => $event, 'subject_type' => Brand::class, 'subject_id' => $brand->id,
            'metadata' => ['fields' => array_keys($attributes), 'has_logo' => (bool) $brand->banner_logo_path],
        ]);

        session()->flash('status', "{$brand->name} saved. Its products use the new voice, audience and palette from the next launch pack.");

        return $this->redirectRoute('brands.index', navigate: true);
    }

    private function issuedColor(int|string $id): bool
    {
        return is_string($id) && preg_match('/^c[1-9]\d*$/', $id) && (int) substr($id, 1) <= $this->nextColor
            && ! in_array($id, $this->removedColors, true);
    }

    /** @param list<string> $palette */
    private function setPalette(array $palette): void
    {
        $this->colors = [];
        foreach (array_slice($palette, 0, self::MAX_COLORS) as $color) {
            $this->colors['c'.(++$this->nextColor)] = $color;
        }
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $brand = $this->brandId ? $workspace->brands()->withCount('products')->find($this->brandId) : null;

        return view('livewire.brand-editor', [
            'workspace' => $workspace,
            'brand' => $brand,
            'profileAvailable' => Brand::profileAvailable(),
            'validPalette' => array_values(array_filter($this->palette(), fn ($color) => preg_match('/^#[0-9A-F]{6}$/', $color))),
        ])->layout('components.layouts.app');
    }
}
