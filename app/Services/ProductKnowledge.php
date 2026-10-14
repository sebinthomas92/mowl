<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFact;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product knowledge: one row per fact with what the source says and what the team confirmed.
 *
 * A refreshed source never overwrites a confirmed value. When the source starts saying something
 * different, the fact becomes a conflict until someone accepts the source or keeps their value.
 */
class ProductKnowledge
{
    /** Fact keys in display order. "product" names the products column a confirmed value is written back to. */
    public const FACTS = [
        'name' => ['label' => 'Name', 'sourced' => true, 'product' => 'name', 'max' => 160],
        'price' => ['label' => 'Price', 'sourced' => true, 'product' => 'price', 'max' => 40],
        'description' => ['label' => 'Description', 'sourced' => true, 'product' => 'summary', 'max' => 1000],
        'availability' => ['label' => 'Availability', 'sourced' => true, 'max' => 80],
        'audience' => ['label' => 'Audience', 'sourced' => false, 'max' => 500, 'placeholder' => 'Who buys this?'],
        'benefits' => ['label' => 'Benefits', 'sourced' => false, 'max' => 1000, 'placeholder' => 'One benefit per line'],
        'differentiators' => ['label' => 'Differentiators', 'sourced' => false, 'max' => 1000, 'placeholder' => 'What makes it different from alternatives?'],
    ];

    private static ?bool $available = null;

    /** Production runs migrations as a separate step; callers skip knowledge until the table exists. */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('product_facts');
    }

    /** Every fact for the product, in display order, creating missing rows from the latest source. */
    public function facts(Product $product): Collection
    {
        if ($product->facts()->count() < count(self::FACTS)) {
            $snapshot = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();
            $this->syncFromSource($product, $snapshot);
        }

        $order = array_flip(array_keys(self::FACTS));

        return $product->facts()->with('confirmedBy')->get()
            ->filter(fn (ProductFact $fact) => isset($order[$fact->key]))
            ->sortBy(fn (ProductFact $fact) => $order[$fact->key])
            ->values();
    }

    /**
     * Record what a source says. Returns the keys that are now in conflict with a confirmed value.
     *
     * @return list<string>
     */
    public function syncFromSource(Product $product, ?SourceSnapshot $snapshot): array
    {
        $values = $snapshot ? $this->sourceValues($snapshot->extracted_truth ?? []) : [];
        $workspaceId = $product->brand()->value('workspace_id');
        $conflicts = [];

        DB::transaction(function () use ($product, $snapshot, $values, $workspaceId, &$conflicts) {
            $existing = $product->facts()->lockForUpdate()->get()->keyBy('key');

            foreach (self::FACTS as $key => $definition) {
                $new = $values[$key] ?? null;
                $fact = $existing->get($key);

                if (! $fact) {
                    $saved = $definition['sourced'] && isset($definition['product']) ? $this->clean($product->{$definition['product']}) : null;
                    $confirmed = $saved ?? $new;
                    $status = $confirmed === null ? 'missing' : ($new !== null && $saved !== null && ! $this->same($new, $saved, $key) ? 'conflict' : 'ok');
                    $product->facts()->create([
                        'workspace_id' => $workspaceId,
                        'source_snapshot_id' => $snapshot?->id,
                        'key' => $key,
                        'source_value' => $new,
                        'confirmed_value' => $confirmed,
                        'status' => $status,
                        'source_checked_at' => $snapshot ? now() : null,
                    ]);
                    if ($status === 'conflict') {
                        $conflicts[] = $key;
                    }

                    continue;
                }

                if (! $definition['sourced'] || ! $snapshot) {
                    continue;
                }

                $updates = ['source_snapshot_id' => $snapshot->id, 'source_checked_at' => now()];
                if ($new !== null) {
                    $updates['source_value'] = $new;
                    if ($fact->status !== 'excluded') {
                        if ($fact->confirmed_value === null) {
                            $updates += ['confirmed_value' => $new, 'status' => 'ok'];
                        } elseif ($this->same($new, $fact->confirmed_value, $key)) {
                            $updates['status'] = 'ok';
                        } elseif ($fact->source_value === null || ! $this->same($new, $fact->source_value, $key) || $fact->status === 'conflict') {
                            $updates['status'] = 'conflict';
                            $conflicts[] = $key;
                        }
                    }
                }
                $fact->update($updates);
            }
        });

        return $conflicts;
    }

    public function confirm(ProductFact $fact, string $value, User $user): void
    {
        $value = $this->clean($value);
        $fact->update([
            'confirmed_value' => $value,
            'status' => $value === null ? 'missing' : 'ok',
            'confirmed_by_user_id' => $user->id,
            'confirmed_at' => now(),
        ]);

        $column = self::FACTS[$fact->key]['product'] ?? null;
        if ($column && ($value !== null || $column !== 'name')) {
            $fact->product->update([$column => $value]);
        }
    }

    public function acceptSource(ProductFact $fact, User $user): void
    {
        abort_if($fact->source_value === null, 422);
        $this->confirm($fact, $fact->source_value, $user);
    }

    public function keepConfirmed(ProductFact $fact, User $user): void
    {
        abort_unless($fact->status === 'conflict', 422);
        $fact->update(['status' => 'ok', 'confirmed_by_user_id' => $user->id, 'confirmed_at' => now()]);
    }

    public function exclude(ProductFact $fact): void
    {
        abort_if($fact->key === 'name', 422);
        $fact->update(['status' => 'excluded']);
    }

    public function restore(ProductFact $fact): void
    {
        abort_unless($fact->status === 'excluded', 422);
        $fact->update(['status' => $fact->confirmed_value === null ? 'missing' : 'ok']);
    }

    /**
     * Apply confirmed knowledge to the structured truth a generator receives:
     * confirmed values win over the raw page, excluded facts are removed.
     */
    public function overlay(Product $product, array $truth): array
    {
        $facts = $product->facts()->get()->keyBy('key');

        foreach (self::FACTS as $key => $definition) {
            $fact = $facts->get($key);
            if (! $fact || ! $definition['sourced']) {
                continue;
            }
            if ($fact->status === 'excluded') {
                unset($truth[$key]);
                if ($key === 'price') {
                    unset($truth['currency']);
                }

                continue;
            }
            if ($fact->confirmed_value !== null) {
                $truth[$key] = $fact->confirmed_value;
                if ($key === 'price') {
                    unset($truth['currency']);
                }
            }
        }

        return $truth;
    }

    /**
     * Everything a generator receives, with confirmed knowledge applied and excluded facts scrubbed
     * from every input: the product record, the structured truth, the page description and the raw text.
     *
     * @return array{0: Product, 1: array}
     */
    public function prepare(Product $product, array $page): array
    {
        $facts = $product->facts()->get()->keyBy('key');
        $rawTruth = $page['product_truth'] ?? [];
        $page['product_truth'] = $this->overlay($product, $rawTruth);
        $subject = clone $product;

        foreach ($facts->where('status', 'excluded') as $fact) {
            $column = self::FACTS[$fact->key]['product'] ?? null;
            if ($column && $column !== 'name') {
                $subject->setAttribute($column, null);
            }
            if ($fact->key === 'description') {
                unset($page['description']);
            }
            $values = array_filter([$fact->source_value, $fact->confirmed_value, is_scalar($rawTruth[$fact->key] ?? null) && mb_strlen((string) $rawTruth[$fact->key]) >= 4 ? (string) $rawTruth[$fact->key] : null, $fact->key === 'price' ? $product->price : null, $fact->key === 'description' ? $product->summary : null],
                fn ($value) => is_string($value) && mb_strlen(trim($value)) >= 2);
            foreach (['content', 'description'] as $field) {
                if (isset($page[$field]) && is_string($page[$field])) {
                    $page[$field] = str_ireplace(array_map('trim', $values), '', $page[$field]);
                }
            }
        }

        $team = [];
        foreach (self::FACTS as $key => $definition) {
            $fact = $facts->get($key);
            if (! $definition['sourced'] && $fact && $fact->status !== 'excluded' && $fact->confirmed_value !== null) {
                $team[$key] = $fact->confirmed_value;
            }
        }
        unset($page['team_knowledge']);
        if ($team) {
            $page['team_knowledge'] = $team;
        }

        return [$subject, $page];
    }

    /** A short fingerprint of the confirmed knowledge, for generation cache keys. */
    public function fingerprint(Product $product): string
    {
        return hash('sha256', json_encode($product->facts()->orderBy('key')->get(['key', 'confirmed_value', 'status'])->toArray()));
    }

    /** @return array<string, string> */
    public function sourceValues(array $truth): array
    {
        $price = (string) ($truth['price'] ?? '');
        if ($price !== '' && filled($truth['currency'] ?? null) && ! preg_match('/[^\d.,\s]/u', $price)) {
            $price = $truth['currency'].' '.$price;
        }
        $availability = $this->clean($truth['availability'] ?? null);
        if ($availability !== null) {
            $availability = (string) str(preg_replace('#^https?://schema\.org/#i', '', $availability))->headline();
        }

        return array_filter([
            'name' => $this->clean($truth['name'] ?? null),
            'price' => $this->clean($price),
            'description' => $this->clean($truth['description'] ?? null),
            'availability' => $availability,
        ], fn ($value) => $value !== null);
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function same(string $a, string $b, string $key): bool
    {
        // "$54.00", "USD 54" and "54.00" are the same price.
        if ($key === 'price' && preg_match('/\d/', $a) && preg_match('/\d/', $b)) {
            $amount = fn (string $value) => (float) str_replace(',', '', preg_replace('/[^\d.,]/', '', $value));

            return abs($amount($a) - $amount($b)) < 0.005;
        }
        $normalize = fn (string $value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));

        return $normalize($a) === $normalize($b);
    }
}
