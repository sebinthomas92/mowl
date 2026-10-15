<?php

namespace App\Services;

use App\Models\LibraryPrompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reads the Workflows catalog: hosted workflows from config/workflows.php and
 * prompt packs / agent skills from Markdown files in the library folder.
 *
 * A library file starts with a frontmatter block of `key: value` lines and
 * holds one prompt per "## " heading. Text before the first heading is the
 * workflow's description. Licensed collections that may not be published in
 * the repository live in the library_prompts table, one pack per `pack`.
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
            ->concat($this->collections())
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

    /** Fills in prompt bodies that the catalog listing leaves out. */
    public function withBodies(array $workflow): array
    {
        $missing = array_filter(array_column($workflow['prompts'], 'id'));
        if ($missing === []) {
            return $workflow;
        }

        $bodies = LibraryPrompt::query()->whereKey($missing)->pluck('body', 'id');
        foreach ($workflow['prompts'] as $index => $prompt) {
            if (isset($prompt['id'])) {
                $workflow['prompts'][$index]['body'] = (string) $bodies->get($prompt['id'], '');
            }
        }

        return $workflow;
    }

    /** The pack as a Markdown file for an agent: the library file itself, or one built from its prompts. */
    public function markdown(array $workflow): ?string
    {
        if (! empty($workflow['file'])) {
            return File::get($workflow['file']);
        }

        if ($workflow['prompts'] === []) {
            return null;
        }

        $workflow = $this->withBodies($workflow);

        $lines = ['---', 'name: '.$workflow['name'], 'category: '.$workflow['category']];
        if ($workflow['credit']) {
            $lines[] = 'credit: '.$workflow['credit'];
        }
        $lines[] = '---';
        if ($workflow['description']) {
            $lines[] = $workflow['description'];
        }
        foreach ($workflow['prompts'] as $prompt) {
            $lines[] = '';
            $lines[] = '## '.$prompt['title'];
            $lines[] = preg_replace('/^## /m', '### ', $prompt['body']);
        }

        return implode("\n", $lines)."\n";
    }

    /** @return Collection<int, array<string, mixed>> */
    private function collections(): Collection
    {
        if (! Schema::hasTable('library_prompts')) {
            return collect();
        }

        $columns = ['id', 'collection', 'pack', 'category', 'title', 'tier', 'position', 'credit'];
        if (Schema::hasColumn('library_prompts', 'video_url')) {
            $columns = [...$columns, 'video_url', 'poster_url', 'video_path', 'poster_path'];
        }

        // Bodies are loaded only for the pack being shown (see withBodies()).
        return LibraryPrompt::query()
            ->select($columns)
            ->orderBy('collection')->orderBy('pack')->orderBy('position')->orderBy('id')
            ->get()
            ->groupBy(fn (LibraryPrompt $prompt) => $prompt->collection.'|'.$prompt->pack)
            ->map(function (Collection $prompts) {
                $first = $prompts->first();
                $premium = $prompts->where('tier', 'premium')->count();
                $previews = app(LibraryPreviews::class);
                $preview = fn (LibraryPrompt $prompt, string $kind) => ($prompt->{$kind.'_url'} || $prompt->{$kind.'_path'})
                    && ! ($kind === 'video' && ($prompt->video_path === LibraryPreviews::REMOTE || $previews->isStream($prompt->video_url)))
                    ? route('workflows.preview', [$prompt->id, $kind])
                    : null;
                $items = $prompts->map(fn (LibraryPrompt $prompt) => [
                    'id' => $prompt->id,
                    'title' => $prompt->title,
                    'body' => null,
                    'tier' => $prompt->tier,
                    'video' => $preview($prompt, 'video'),
                    'poster' => $preview($prompt, 'poster'),
                ])->values()->all();

                return $this->normalize([
                    'slug' => $first->collection.'-'.$first->pack,
                    'name' => $first->pack,
                    'category' => $first->category,
                    'availability' => 'download',
                    'description' => $prompts->count().' '.Str::plural('prompt', $prompts->count()).($premium ? ", {$premium} premium" : '').'.',
                    'credit' => $first->credit,
                    'source_url' => config('workflows.source_url'),
                    'poster' => collect($items)->pluck('poster')->filter()->first(),
                    'prompts' => $items,
                ]);
            })
            ->values();
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
            'credit' => null,
            'action' => null,
            'poster' => null,
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
