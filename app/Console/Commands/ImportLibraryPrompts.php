<?php

namespace App\Console\Commands;

use App\Models\LibraryPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Loads a licensed prompt collection (JSON array) into library_prompts.
 *
 * Each entry needs `title` and `body`; `id`, `category` (the source's own section, used as the pack),
 * `tags` (containing "premium" or "free"), `source_url`, `video_url` and `poster_url` are optional. Re-running updates entries
 * by id and removes ones the file no longer has.
 */
class ImportLibraryPrompts extends Command
{
    protected $signature = 'workflows:import-prompts
        {file : Path to the JSON export}
        {--collection=motionsites : Key that groups this collection}
        {--credit=From the MotionSites.ai collection, licensed for Marketing Owl : Attribution shown with every prompt}';

    protected $description = 'Import a licensed prompt collection into the Workflows prompt library';

    public function handle(): int
    {
        $entries = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($entries) || ! array_is_list($entries)) {
            $this->error('The file must contain a JSON array of prompts.');

            return self::FAILURE;
        }

        $collection = Str::slug($this->option('collection'));
        $positions = [];
        $keys = [];

        $previews = Schema::hasColumn('library_prompts', 'video_url');

        DB::transaction(function () use ($entries, $collection, $previews, &$positions, &$keys) {
            foreach ($entries as $index => $entry) {
                $title = trim((string) ($entry['title'] ?? ''));
                $body = trim((string) ($entry['body'] ?? ''));
                if ($title === '' || $body === '') {
                    continue;
                }

                $pack = trim((string) ($entry['category'] ?? '')) ?: 'Prompts';
                $key = (string) ($entry['id'] ?? $index);
                $tags = array_map('strtolower', (array) ($entry['tags'] ?? []));

                $prompt = LibraryPrompt::firstOrNew(['collection' => $collection, 'source_key' => $key]);
                $prompt->fill([
                    'pack' => Str::limit($pack, 117),
                    'category' => 'web',
                    'title' => Str::limit($title, 197),
                    'body' => $body,
                    'tier' => in_array('premium', $tags, true) ? 'premium' : 'free',
                    'position' => $positions[$pack] = ($positions[$pack] ?? 0) + 1,
                    'credit' => $this->option('credit'),
                    'source_url' => ($entry['source_url'] ?? null) ?: null,
                ]);
                if ($previews) {
                    foreach (['video', 'poster'] as $kind) {
                        $url = $this->mediaUrl($entry[$kind.'_url'] ?? null);
                        if ($url !== $prompt->{$kind.'_url'}) {
                            // A new source URL means the stored copy is stale; it is fetched again on next view.
                            $prompt->fill([$kind.'_url' => $url, $kind.'_path' => null]);
                        }
                    }
                }
                $prompt->save();
                $keys[] = $key;
            }

            LibraryPrompt::query()->where('collection', $collection)->whereNotIn('source_key', $keys)->delete();
        });

        $this->info(count($keys).' prompts imported into '.count($positions).' packs.');

        return self::SUCCESS;
    }

    private function mediaUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return str_starts_with($url, 'https://') && strlen($url) <= 500 ? $url : null;
    }
}
