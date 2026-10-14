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

    /** @var list<string> */
    public array $palette = [];

    public $logo = null;

    public function mount(?Brand $brand = null): void
    {
        $workspace = $this->currentWorkspace();
        if (! $brand?->exists) {
            abort_if($workspace->brands()->count() >= config('campaigns.brand_limit'), 403, 'This workspace has reached its brand limit.');
            $this->palette = ['#D9381E'];

            return;
        }

        abort_unless($brand->workspace_id === $workspace->id, 404);
        $this->brandId = $brand->id;
        $this->name = $brand->name;
        $this->website = (string) $brand->website;
        $this->voice = (string) ($brand->voice ?? '');
        $this->defaultAudience = (string) ($brand->default_audience ?? '');
        $this->palette = array_map('strtoupper', $brand->colors());
    }

    public function addColor(): void
    {
        if (count($this->palette) < self::MAX_COLORS) {
            $this->palette[] = '#FFFFFF';
        }
    }

    public function removeColor(int $index): void
    {
        unset($this->palette[$index]);
        $this->palette = array_values($this->palette);
    }

    public function save()
    {
        $this->palette = array_values(array_map(fn ($color) => strtoupper(trim((string) $color)), $this->palette));
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'voice' => ['nullable', 'string', 'max:1000'],
            'defaultAudience' => ['nullable', 'string', 'max:500'],
            'palette' => ['array', 'max:'.self::MAX_COLORS],
            'palette.*' => ['required', 'regex:/^#[0-9A-F]{6}$/'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/webp', 'max:5120'],
        ], [
            'palette.*.regex' => 'Use a six-digit hex colour, such as #D9381E.',
            'palette.*.required' => 'Use a six-digit hex colour, such as #D9381E.',
        ]);

        $workspace = $this->currentWorkspace();
        $attributes = [
            'name' => trim($data['name']),
            'website' => filled($data['website']) ? trim($data['website']) : null,
            'primary_color' => $data['palette'][0] ?? null,
        ];
        if (Brand::profileAvailable()) {
            $attributes += [
                'voice' => filled($data['voice']) ? trim($data['voice']) : null,
                'default_audience' => filled($data['defaultAudience']) ? trim($data['defaultAudience']) : null,
                'palette' => $data['palette'] ?: null,
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

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $brand = $this->brandId ? $workspace->brands()->withCount('products')->find($this->brandId) : null;

        return view('livewire.brand-editor', [
            'workspace' => $workspace,
            'brand' => $brand,
            'profileAvailable' => Brand::profileAvailable(),
            'validPalette' => array_values(array_filter($this->palette, fn ($color) => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color))),
        ])->layout('components.layouts.app');
    }
}
