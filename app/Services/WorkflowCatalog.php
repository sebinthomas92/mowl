<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Reads the Workflows catalog: hosted workflows from config/workflows.php and
 * prompt packs / agent skills from Markdown files in the library folder.
 *
 * A library file starts with a frontmatter block of `key: value` lines and
 * holds one prompt per "## " heading. Text before the first heading is the
 * workflow's description.
 */
class WorkflowCatalog
{
    private ?Collection $workflows = null;

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return $this->workflows ??= collect(config('workflows.hosted', []))
            ->map(fn (array $workflow) => $this->normalize($workflow + ['prompts' => [], 'kind' => 'workflow']))
            ->concat($this->library())
            ->sortBy(fn (array $workflow) => [array_search($workflow['category'], array_keys($this->categories()), true), $workflow['order'], $workflow['name']])
            ->values();
    }

    public function find(?string $slug): ?array
    {
        return $slug === null ? null : $this->all()->firstWhere('slug', $slug);
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        return config('workflows.categories', []);
    }

    /** The library file behind a prompt pack or skill, for download. */
    public function path(array $workflow): ?string
    {
        return $workflow['file'] ?? null;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function library(): Collection
    {
        $directory = config('workflows.library_path');

        if (! $directory || ! File::isDirectory($directory)) {
            return collect();
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->map(fn ($file) => $this->parse($file->getPathname()))
            ->filter()
            ->values();
    }

    private function parse(string $path): ?array
    {
        $raw = str_replace("\r\n", "\n", File::get($path));

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $parts)) {
            return null;
        }

        $meta = [];
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $match)) {
                $meta[$match[1]] = trim($match[2], " \"'");
            }
        }

        $sections = preg_split('/^## +(.+)$/m', $parts[2], -1, PREG_SPLIT_DELIM_CAPTURE);
        $description = trim(array_shift($sections));
        $prompts = [];
        foreach (array_chunk($sections, 2) as [$title, $body]) {
            $prompts[] = ['title' => trim($title), 'body' => trim($body ?? '')];
        }

        if (empty($meta['name']) || $prompts === []) {
            return null;
        }

        return $this->normalize($meta + [
            'slug' => pathinfo($path, PATHINFO_FILENAME),
            'availability' => 'download',
            'description' => $description,
            'prompts' => $prompts,
            'file' => $path,
        ]);
    }

    private function normalize(array $workflow): array
    {
        $category = array_key_exists($workflow['category'] ?? '', $this->categories()) ? $workflow['category'] : 'copy';
        $availability = $workflow['availability'] ?? 'download';

        return array_merge([
            'kind' => 'prompt pack',
            'scope' => 'Product',
            'needs' => null,
            'gives' => null,
            'length' => null,
            'description' => null,
            'source_url' => null,
            'action' => null,
            'order' => 100,
        ], $workflow, [
            'slug' => Str::slug($workflow['slug']),
            'category' => $category,
            'category_label' => $this->categories()[$category],
            'availability' => array_key_exists($availability, config('workflows.availability')) ? $availability : 'download',
            'gradient' => (int) ($workflow['gradient'] ?? crc32($workflow['slug']) % 6),
            'order' => (int) ($workflow['order'] ?? 100),
        ]);
    }
}
