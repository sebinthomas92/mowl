<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CampaignActivity;
use App\Services\ContentLibrary;
use App\Services\Pages\PageBuilder;
use App\Services\ProductKnowledge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Planning a campaign, the checks that gate its launch, and the launch itself, which pins the exact
 * approved content versions and the live page version the campaign runs with.
 */
class CampaignPlanner
{
    /** Deliverables a new plan picks up from the product's approved content. */
    public const DEFAULT_DELIVERABLES = 6;

    /** Production runs migrations as a separate step; the Campaigns tab explains itself until the tables exist. */
    public static function available(): bool
    {
        $container = app();
        if ($container->bound('campaigns.available')) {
            return $container->make('campaigns.available');
        }
        $available = ContentLibrary::available() && Schema::hasTable('campaigns') && Schema::hasTable('campaign_results');
        if ($available && DB::getDriverName() === 'pgsql') {
            try {
                $available = (bool) DB::scalar("select has_table_privilege(current_user, 'campaigns', 'SELECT') and has_table_privilege(current_user, 'campaign_results', 'SELECT')");
            } catch (QueryException) {
                $available = false;
            }
        }
        $container->instance('campaigns.available', $available);

        return $available;
    }

    /** A planned campaign prefilled from Knowledge, the latest page and the product's approved content. */
    public function plan(Product $product, User $actor, ?Campaign $follows = null, array $overrides = []): Campaign
    {
        $product->loadMissing('brand');
        $page = PageBuilder::available() ? $product->pages()->orderByDesc('verified_at')->orderByDesc('id')->first() : null;

        return DB::transaction(function () use ($product, $actor, $follows, $overrides, $page) {
            $campaign = Campaign::create(array_merge([
                'workspace_id' => $product->brand->workspace_id,
                'product_id' => $product->id,
                'page_id' => $follows?->page_id ?? $page?->id,
                'follows_campaign_id' => $follows?->id,
                'name' => $follows ? 'Next: '.mb_strimwidth($follows->name, 0, 100, '…') : $product->name.' campaign',
                'status' => 'planned',
                'objective' => $follows?->objective ?? 'Launch the offer',
                'offer' => $follows?->offer ?? $this->defaultOffer($product),
                'audience' => $follows?->audience ?? $this->defaultAudience($product),
                'traffic_source' => $follows?->traffic_source,
                'created_by_user_id' => $actor->id,
            ], $overrides));

            $items = $follows
                ? $follows->deliverables()->pluck('content_item_id')
                : $product->contentItems()->where('status', 'approved')->orderByDesc('updated_at')->orderByDesc('id')->limit(self::DEFAULT_DELIVERABLES)->pluck('id');
            $this->writeDeliverables($campaign, $items->all());

            return $campaign;
        });
    }

    /** Edit the plan. Only planned campaigns change; a launched one keeps the context it ran with. */
    public function update(Campaign $campaign, array $data): void
    {
        if (array_key_exists('baseline', $data)) {
            $data['baseline_rate'] = self::rateIn($data['baseline']);
        }
        $this->whilePlanned($campaign, fn (Campaign $locked) => $locked->update($data));
    }

    /** @param  array<int, int>  $itemIds */
    public function setDeliverables(Campaign $campaign, array $itemIds): void
    {
        $ids = ContentItem::query()->where('product_id', $campaign->product_id)->whereIn('id', $itemIds)->pluck('id')->all();
        $this->whilePlanned($campaign, fn (Campaign $locked) => $this->writeDeliverables($locked, array_values(array_filter($itemIds, fn ($id) => in_array((int) $id, $ids, true)))));
    }

    /** @return array<int, array{key: string, ok: bool, label: string}> */
    public function readiness(Campaign $campaign): array
    {
        $deliverables = $campaign->deliverables()->with('contentItem')->get();
        $page = $campaign->page;
        $checks = [
            ['key' => 'deliverables', 'ok' => $deliverables->isNotEmpty() && $deliverables->every(fn (CampaignDeliverable $deliverable) => $this->approvedVersion($deliverable->contentItem) !== null), 'label' => 'All deliverables approved'],
        ];
        if ($page) {
            $checks[] = ['key' => 'page', 'ok' => $page->live_version !== null && $page->live_version === $page->draft_version, 'label' => 'Landing page live at the latest version'];
        }
        $checks[] = ['key' => 'baseline', 'ok' => trim((string) $campaign->baseline) !== '', 'label' => 'Baseline recorded'];
        $checks[] = ['key' => 'hypothesis', 'ok' => trim((string) $campaign->hypothesis) !== '', 'label' => 'Hypothesis written'];
        $checks[] = ['key' => 'dates', 'ok' => $campaign->starts_on !== null, 'label' => 'Start date set'];

        return $checks;
    }

    /** Launching records the context and pins versions. It does not start any ad spend. */
    public function launch(Campaign $campaign, User $actor): void
    {
        $missing = collect($this->readiness($campaign))->reject(fn (array $check) => $check['ok'])->pluck('label');
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['launch' => 'Before launch: '.mb_strtolower($missing->implode(', ')).'.']);
        }

        $launched = DB::transaction(function () use ($campaign, $actor) {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! $locked->isPlanned()) {
                return false;
            }
            foreach ($locked->deliverables()->with('contentItem')->get() as $deliverable) {
                $deliverable->update(['pinned_version' => $this->approvedVersion($deliverable->contentItem)]);
            }
            $locked->update([
                'status' => 'active', 'launched_at' => now(), 'launched_by_user_id' => $actor->id,
                'pinned_page_version' => $locked->page?->live_version,
            ]);

            return true;
        });
        $campaign->refresh();
        if ($launched) {
            $this->notify($campaign, 'launched', $actor);
        }
    }

    public function pause(Campaign $campaign): void
    {
        $this->move($campaign, ['active'], ['status' => 'paused']);
    }

    public function resume(Campaign $campaign): void
    {
        $this->move($campaign, ['paused'], ['status' => 'active']);
    }

    public function complete(Campaign $campaign): void
    {
        $this->move($campaign, ['active', 'paused'], ['status' => 'completed', 'completed_at' => now()]);
    }

    public function delete(Campaign $campaign): void
    {
        $this->whilePlanned($campaign, fn (Campaign $locked) => $locked->delete());
    }

    /** The newest approved version of a content item, or null when none is approved. */
    public function approvedVersion(?ContentItem $item): ?int
    {
        if (! $item) {
            return null;
        }
        $version = ContentVersion::query()->where('content_item_id', $item->id)->where('status', 'approved')->max('version');

        return $version === null ? null : (int) $version;
    }

    /** "1.4% page conversion (September)" → 1.4; null when the text states no percentage. */
    public static function rateIn(?string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*%/', (string) $text, $match)) {
            return null;
        }
        $rate = (float) str_replace(',', '.', $match[1]);

        return $rate >= 0 && $rate <= 100 ? round($rate, 3) : null;
    }

    public function notify(Campaign $campaign, string $event, ?User $actor): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $workspace = Workspace::find($campaign->workspace_id);
        $recipients = $workspace?->users()->when($actor, fn ($query) => $query->whereKeyNot($actor->id))->get() ?? collect();
        Notification::send($recipients, new CampaignActivity($event, $campaign));
    }

    private function writeDeliverables(Campaign $campaign, array $itemIds): void
    {
        $campaign->deliverables()->whereNotIn('content_item_id', $itemIds ?: [0])->delete();
        foreach (array_values(array_unique(array_map('intval', $itemIds))) as $position => $id) {
            $campaign->deliverables()->updateOrCreate(['content_item_id' => $id], ['position' => $position]);
        }
    }

    private function move(Campaign $campaign, array $from, array $to): void
    {
        $moved = DB::transaction(function () use ($campaign, $from, $to) {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($locked->status, $from, true)) {
                return false;
            }

            return $locked->update($to);
        });
        if (! $moved) {
            throw ValidationException::withMessages(['launch' => 'This campaign is '.mb_strtolower(Campaign::STATUSES[$campaign->fresh()->status][1] ?? 'changed').' now. Reload to see its current state.']);
        }
        $campaign->refresh();
    }

    /** Runs a plan change under a row lock, so a launch in another request can't slip in between. */
    private function whilePlanned(Campaign $campaign, callable $change): void
    {
        DB::transaction(function () use ($campaign, $change) {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! $locked->isPlanned()) {
                throw ValidationException::withMessages(['launch' => 'A launched campaign keeps the plan it ran with. Plan a next experiment to change it.']);
            }
            $change($locked);
        });
        if (Campaign::query()->whereKey($campaign->id)->exists()) {
            $campaign->refresh();
        }
    }

    private function defaultOffer(Product $product): ?string
    {
        $price = $this->fact($product, 'price') ?? $product->price;

        return $price ? 'Price '.$price : null;
    }

    private function defaultAudience(Product $product): ?string
    {
        return $this->fact($product, 'audience')
            ?? (ProductKnowledge::available() ? (app(ProductKnowledge::class)->brandProfile($product)['default_audience'] ?? null) : null);
    }

    private function fact(Product $product, string $key): ?string
    {
        if (! ProductKnowledge::available()) {
            return null;
        }
        $value = $product->facts()->where('key', $key)->where('status', 'ok')->value('confirmed_value');

        return is_string($value) && trim($value) !== '' ? mb_strimwidth(trim($value), 0, 280, '…') : null;
    }
}
