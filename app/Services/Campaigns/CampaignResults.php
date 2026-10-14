<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignResult;
use App\Models\StoreConnection;
use App\Models\User;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\WooCommerceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Results a campaign measured: imports, the summary the Results cards show, and next-experiment
 * proposals drawn from those numbers. Generation usage is a production cost and never appears here.
 */
class CampaignResults
{
    public function __construct(private ResultsCsv $csv, private WooCommerceStore $woo) {}

    public function importCsv(Campaign $campaign, string $contents, string $label, User $actor, ?string $currency = null, ?string $attribution = null): CampaignResult
    {
        $this->assertLaunched($campaign);
        $parsed = $this->csv->parse($contents);
        // Only days inside the campaign dates count; a month-long export would otherwise skew conversion.
        $from = CarbonImmutable::parse($campaign->starts_on ?? $campaign->launched_at)->format('Y-m-d');
        $to = $campaign->ends_on?->format('Y-m-d');
        $inside = array_values(array_filter($parsed['daily'], fn (array $day) => $day['date'] >= $from && ($to === null || $day['date'] <= $to)));
        if (! $inside) {
            throw ValidationException::withMessages(['csv' => 'None of these days fall inside the campaign dates ('.CarbonImmutable::parse($from)->format('j M Y').($to ? ' to '.CarbonImmutable::parse($to)->format('j M Y') : ' onwards').').']);
        }
        $parsed = ResultsCsv::summarize($inside) + ['currency' => $parsed['currency']];

        $result = $campaign->results()->create([
            'workspace_id' => $campaign->workspace_id,
            'source' => 'csv',
            'source_label' => mb_strimwidth(trim($label) !== '' ? trim($label) : 'Pasted CSV', 0, 200, '…'),
            'window_start' => $parsed['start'],
            'window_end' => $parsed['end'],
            'currency' => $currency ?: $parsed['currency'],
            'attribution' => $attribution !== null && trim($attribution) !== '' ? mb_strimwidth(trim($attribution), 0, 80, '…') : null,
            'visits' => $parsed['visits'],
            'orders' => $parsed['orders'],
            'revenue' => $parsed['revenue'],
            'daily' => $parsed['daily'],
            'imported_by_user_id' => $actor->id,
        ]);
        app(CampaignPlanner::class)->notify($campaign, 'results_imported', $actor);

        return $result;
    }

    /** Paid WooCommerce orders containing the product, from launch (or the start date) to today or the end date. */
    public function importStoreOrders(Campaign $campaign, User $actor): CampaignResult
    {
        $this->assertLaunched($campaign);
        $store = $this->orderStore($campaign);
        if (! $store) {
            throw ValidationException::withMessages(['store' => 'Connect a WooCommerce store in Settings to pull orders. The sandbox store does not take orders.']);
        }
        $start = CarbonImmutable::parse($campaign->starts_on ?? $campaign->launched_at)->startOfDay();
        $end = CarbonImmutable::parse(min($campaign->ends_on?->format('Y-m-d') ?? now()->format('Y-m-d'), now()->format('Y-m-d')));
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['store' => 'The campaign starts on '.$start->format('j M Y').'. Pull orders once it has started.']);
        }

        $product = $campaign->product()->with('sourceSnapshots')->firstOrFail();
        try {
            $match = $this->woo->findProduct($store, $this->slugs($product->sourceSnapshots->pluck('url')->merge($product->sourceSnapshots->pluck('canonical_url'))), $product->name);
            if (! $match) {
                throw ValidationException::withMessages(['store' => "{$store->name} has no product that matches {$product->name} by its page address or name."]);
            }
            $orders = $this->woo->productOrders($store, $match['id'], $start->format('Y-m-d'), $end->format('Y-m-d'));
        } catch (StoreException $exception) {
            throw ValidationException::withMessages(['store' => $exception->getMessage()]);
        }

        $daily = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $daily[] = ['date' => $key, 'visits' => null, 'orders' => $orders['days'][$key]['orders'] ?? 0, 'revenue' => round($orders['days'][$key]['revenue'] ?? 0, 2)];
        }

        $result = $campaign->results()->create([
            'workspace_id' => $campaign->workspace_id,
            'source' => 'store',
            'source_label' => mb_strimwidth("{$store->name} · {$match['name']}".($orders['truncated'] ? ' (first 2,000 orders)' : ''), 0, 200, '…'),
            'window_start' => $start->format('Y-m-d'),
            'window_end' => $end->format('Y-m-d'),
            'currency' => $orders['currency'],
            'attribution' => 'All store orders for the product',
            'visits' => null,
            'orders' => array_sum(array_column($daily, 'orders')),
            'revenue' => round(array_sum(array_column($daily, 'revenue')), 2),
            'daily' => $daily,
            'imported_by_user_id' => $actor->id,
        ]);
        app(CampaignPlanner::class)->notify($campaign, 'results_imported', $actor);

        return $result;
    }

    /** The WooCommerce store orders are read from: the campaign page's store, else the brand's first one. */
    public function orderStore(Campaign $campaign): ?StoreConnection
    {
        $page = $campaign->page()->with('storeConnection')->first();
        if ($page?->storeConnection && $this->sellsOnline($page->storeConnection)) {
            return $page->storeConnection;
        }
        if (! PageBuilder::available()) {
            return null;
        }

        return app(PageBuilder::class)->stores($campaign->product()->firstOrFail())->first(fn (StoreConnection $store) => $this->sellsOnline($store));
    }

    /**
     * Each metric comes from the newest import that reports it, so a store pull (orders) and an
     * analytics CSV (visits) combine into a conversion rate.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Campaign $campaign, ?Collection $results = null): ?array
    {
        $results ??= $campaign->results()->get();
        if ($results->isEmpty()) {
            return null;
        }
        $pick = fn (string $metric) => $results->sortByDesc('id')->first(fn (CampaignResult $result) => $result->{$metric} !== null);
        $from = ['visits' => $pick('visits'), 'orders' => $pick('orders'), 'revenue' => $pick('revenue')];
        $used = collect($from)->filter()->unique('id')->values();
        $visits = $from['visits']?->visits;
        $orders = $from['orders']?->orders;
        $conversion = null;
        if ($from['visits'] && $from['orders']) {
            [$sessions, $sales] = [$visits, $orders];
            if ($from['visits']->id !== $from['orders']->id) {
                // Different imports: compare only the days both of them cover.
                $ordersByDay = collect($from['orders']->daily)->filter(fn (array $day) => $day['orders'] !== null)->pluck('orders', 'date');
                $common = collect($from['visits']->daily)->filter(fn (array $day) => $day['visits'] !== null && $ordersByDay->has($day['date']));
                [$sessions, $sales] = [$common->sum('visits'), $common->sum(fn (array $day) => $ordersByDay[$day['date']])];
            }
            $conversion = $sessions > 0 ? round($sales / $sessions * 100, 2) : null;
        }
        $start = $used->min(fn (CampaignResult $result) => $result->window_start->format('Y-m-d'));
        $end = $used->max(fn (CampaignResult $result) => $result->window_end->format('Y-m-d'));
        $seriesFrom = $from['visits'] ?? $from['orders'];
        $seriesMetric = $from['visits'] ? 'visits' : 'orders';

        return [
            'visits' => $visits,
            'orders' => $orders,
            'revenue' => $from['revenue']?->revenue,
            'currency' => $from['revenue']?->currency,
            'conversion' => $conversion,
            'baseline' => $campaign->baseline_rate,
            'start' => CarbonImmutable::parse($start),
            'end' => CarbonImmutable::parse($end),
            'days' => CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1,
            'sources' => $used->map(fn (CampaignResult $result) => $result->source_label)->all(),
            'attribution' => $used->pluck('attribution')->filter()->unique()->implode(' · ') ?: null,
            'imported_at' => $used->max('created_at'),
            'mixed' => $used->count() > 1,
            'series' => [
                'label' => $seriesMetric === 'visits' ? 'Daily visits' : 'Daily orders',
                'points' => collect($seriesFrom?->daily ?? [])->map(fn (array $day) => ['date' => $day['date'], 'value' => (float) ($day[$seriesMetric] ?? 0)])->values()->all(),
            ],
        ];
    }

    /**
     * Next-experiment proposals worked out from the summary by fixed rules, so every reason quotes
     * the campaign's own numbers. They stay proposals until someone saves one.
     *
     * @return array<int, array{title: string, reason: string, hypothesis: string, baseline: ?string}>
     */
    public function proposals(Campaign $campaign, ?array $summary): array
    {
        if (! $summary) {
            return [];
        }
        $proposals = [];
        $rate = fn (float $value) => rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
        $observed = $summary['conversion'] !== null
            ? $rate($summary['conversion'])." conversion ({$campaign->name})"
            : ($summary['orders'] !== null ? number_format($summary['orders'])." orders in {$summary['days']} days ({$campaign->name})" : null);

        if ($summary['conversion'] !== null && $summary['baseline'] !== null) {
            [$conversion, $baseline] = [$summary['conversion'], $summary['baseline']];
            if ($conversion >= $baseline * 1.1) {
                $proposals[] = ['title' => 'Keep the creative, test the offer', 'reason' => "Conversion was {$rate($conversion)} against a {$rate($baseline)} baseline. Keep what worked and change one thing: the offer.", 'hypothesis' => "A stronger offer lifts conversion above {$rate($conversion)}."];
            } elseif ($conversion <= $baseline * 0.9) {
                $proposals[] = ['title' => 'Lead with a different angle', 'reason' => "Conversion was {$rate($conversion)}, below the {$rate($baseline)} baseline. Change the lead message before changing the offer.", 'hypothesis' => "A different lead message brings conversion back above {$rate($baseline)}."];
            } else {
                $proposals[] = ['title' => 'Change one element to break the tie', 'reason' => "Conversion was {$rate($conversion)} against a {$rate($baseline)} baseline, within 10%, so this run did not separate them.", 'hypothesis' => "Changing the hero alone moves conversion more than 10% from {$rate($baseline)}."];
            }
        } elseif ($summary['conversion'] !== null) {
            $proposals[] = ['title' => 'Repeat with a recorded baseline', 'reason' => "Conversion was {$rate($summary['conversion'])}, but the plan had no baseline rate to compare it with.", 'hypothesis' => "The same content converts at {$rate($summary['conversion'])} or better again."];
        }

        $sample = $summary['visits'] ?? null;
        if (($sample !== null && $sample < 1000) || ($sample === null && $summary['orders'] !== null && $summary['orders'] < 30)) {
            $proposals[] = ['title' => 'Run longer before judging', 'reason' => ($sample !== null ? number_format($sample).' visits' : number_format((int) $summary['orders']).' orders')." in {$summary['days']} days is a small sample. Repeat with the same content for longer.", 'hypothesis' => 'The result holds with at least '.($sample !== null ? '1,000 visits' : '30 orders').'.'];
        }

        if ($campaign->audience) {
            $proposals[] = ['title' => 'Narrow the audience line', 'reason' => 'Aim the same content at one segment of “'.Str::limit($campaign->audience, 80).'” to see who it works for.', 'hypothesis' => 'A single-segment audience line converts better than the broad one.'];
        }

        return array_map(fn (array $proposal) => $proposal + ['baseline' => $observed], array_slice($proposals, 0, 3));
    }

    public function saveProposal(Campaign $campaign, int $index, User $actor): Campaign
    {
        $proposal = $this->proposals($campaign, $this->summary($campaign))[$index] ?? null;
        if (! $proposal) {
            throw ValidationException::withMessages(['proposal' => 'That proposal is no longer available. Reload the campaign.']);
        }
        $existing = $campaign->followUps()->where('name', $proposal['title'])->first();

        return $existing ?? app(CampaignPlanner::class)->plan($campaign->product, $actor, $campaign, [
            'name' => $proposal['title'], 'hypothesis' => $proposal['hypothesis'],
            'baseline' => $proposal['baseline'], 'baseline_rate' => CampaignPlanner::rateIn($proposal['baseline']),
        ]);
    }

    public function deleteResult(Campaign $campaign, int $resultId): void
    {
        $campaign->results()->whereKey($resultId)->delete();
    }

    private function sellsOnline(StoreConnection $store): bool
    {
        return $store->driver === 'woocommerce' && $store->has_woocommerce;
    }

    /** Slugs from product page addresses: /product/fold-reading-stand/ → fold-reading-stand. */
    private function slugs(Collection $urls): array
    {
        return $urls->filter()->map(function (string $url) {
            $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

            return $segments ? Str::slug(urldecode(end($segments))) : null;
        })->filter()->unique()->values()->all();
    }

    private function assertLaunched(Campaign $campaign): void
    {
        if ($campaign->isPlanned()) {
            throw ValidationException::withMessages(['csv' => 'Results are imported after launch.']);
        }
    }
}
