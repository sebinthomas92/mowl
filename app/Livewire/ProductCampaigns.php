<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\WorkspaceCredit;
use App\Services\Campaigns\CampaignPlanner;
use App\Services\Campaigns\CampaignResults;
use App\Services\Pages\PageBuilder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The product's Campaigns and Results tabs: plan, launch with pinned versions, import and read results. */
class ProductCampaigns extends Component
{
    use InteractsWithWorkspace;

    public int $productId;

    /** campaigns: one campaign in detail; results: every campaign's results side by side. */
    public string $mode = 'campaigns';

    #[Url(as: 'campaign')]
    public ?int $campaignId = null;

    public bool $editing = false;

    public array $form = [];

    public bool $importing = false;

    public string $csv = '';

    public string $csvLabel = '';

    public string $csvCurrency = '';

    public string $csvAttribution = '';

    public ?string $flash = null;

    public function mount(int $productId, string $mode = 'campaigns'): void
    {
        $this->productId = $productId;
        $this->mode = $mode === 'results' ? 'results' : 'campaigns';
        abort_unless($this->product(), 404);
        if ($this->campaignId && ! $this->campaigns()->contains('id', $this->campaignId)) {
            $this->campaignId = null;
        }
    }

    public function plan(CampaignPlanner $planner): void
    {
        $campaign = $planner->plan($this->product(), auth()->user());
        $this->mode = 'campaigns';
        $this->campaignId = $campaign->id;
        $this->startEditing();
        $this->flash = 'Campaign planned with the approved content. Add a baseline and a start date before launch.';
    }

    public function show(int $id): void
    {
        $this->campaignId = $this->campaign($id)->id;
        $this->editing = false;
        $this->importing = false;
        $this->flash = null;
        $this->resetValidation();
    }

    public function startEditing(): void
    {
        $campaign = $this->campaign();
        $this->form = [
            'name' => $campaign->name, 'objective' => $campaign->objective, 'offer' => (string) $campaign->offer,
            'audience' => (string) $campaign->audience, 'starts_on' => $campaign->starts_on?->format('Y-m-d') ?? '',
            'ends_on' => $campaign->ends_on?->format('Y-m-d') ?? '', 'hypothesis' => (string) $campaign->hypothesis,
            'baseline' => (string) $campaign->baseline, 'traffic_source' => (string) $campaign->traffic_source,
            'page_id' => (string) ($campaign->page_id ?? ''),
            'items' => $campaign->deliverables()->pluck('content_item_id')->map(fn ($id) => (string) $id)->all(),
        ];
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(CampaignPlanner $planner): void
    {
        $campaign = $this->campaign();
        $pageIds = $this->pagesFor()->pluck('id')->all();
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.objective' => ['required', 'string', 'max:200'],
            'form.offer' => ['nullable', 'string', 'max:300'],
            'form.audience' => ['nullable', 'string', 'max:500'],
            'form.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'form.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:form.starts_on'],
            'form.hypothesis' => ['nullable', 'string', 'max:1000'],
            'form.baseline' => ['nullable', 'string', 'max:200'],
            'form.traffic_source' => ['nullable', 'string', 'max:200'],
            'form.page_id' => ['nullable', Rule::in(array_map('strval', $pageIds))],
            'form.items' => ['array'],
            'form.items.*' => ['integer'],
        ], ['form.ends_on.after_or_equal' => 'The end date must be on or after the start date.'], [
            'form.name' => 'name', 'form.objective' => 'objective', 'form.starts_on' => 'start date', 'form.ends_on' => 'end date',
            'form.traffic_source' => 'traffic source',
        ])['form'];
        $blank = fn (?string $value) => $value === null || trim($value) === '' ? null : trim($value);

        $planner->update($campaign, [
            'name' => trim($data['name']), 'objective' => trim($data['objective']), 'offer' => $blank($data['offer']),
            'audience' => $blank($data['audience']), 'starts_on' => $blank($data['starts_on']), 'ends_on' => $blank($data['ends_on']),
            'hypothesis' => $blank($data['hypothesis']), 'baseline' => $blank($data['baseline']),
            'traffic_source' => $blank($data['traffic_source']), 'page_id' => $blank($data['page_id']),
        ]);
        $planner->setDeliverables($campaign, array_map('intval', $data['items'] ?? []));
        $this->editing = false;
        $this->flash = 'Plan saved.';
    }

    public function launch(CampaignPlanner $planner): void
    {
        $campaign = $this->campaign();
        $planner->launch($campaign, auth()->user());
        $this->editing = false;
        $this->flash = "{$campaign->name} launched. Its content and page versions are pinned to this campaign.";
    }

    public function pause(CampaignPlanner $planner): void
    {
        $planner->pause($this->campaign());
        $this->flash = 'Campaign paused.';
    }

    public function resume(CampaignPlanner $planner): void
    {
        $planner->resume($this->campaign());
        $this->flash = 'Campaign resumed.';
    }

    public function complete(CampaignPlanner $planner): void
    {
        $planner->complete($this->campaign());
        $this->flash = 'Campaign marked completed. Its results stay here.';
    }

    public function deleteCampaign(CampaignPlanner $planner): void
    {
        $planner->delete($this->campaign());
        $this->campaignId = null;
        $this->editing = false;
        $this->flash = 'Planned campaign deleted.';
    }

    public function openImport(?int $id = null): void
    {
        if ($id) {
            $this->campaignId = $this->campaign($id)->id;
        }
        $this->importing = true;
        $this->reset(['csv', 'csvLabel', 'csvCurrency', 'csvAttribution']);
        $this->resetValidation();
    }

    public function importCsv(CampaignResults $results): void
    {
        $this->validate([
            'csv' => ['required', 'string', 'max:'.(2 * 1024 * 1024)],
            'csvLabel' => ['nullable', 'string', 'max:200'],
            'csvCurrency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'csvAttribution' => ['nullable', 'string', 'max:80'],
        ], ['csv.required' => 'Choose a CSV file or paste its rows.', 'csvCurrency.regex' => 'Use a three-letter code such as USD or INR.'], ['csvAttribution' => 'attribution']);
        $result = $results->importCsv($this->campaign(), $this->csv, $this->csvLabel, auth()->user(), $this->csvCurrency ? strtoupper($this->csvCurrency) : null, $this->csvAttribution);
        $this->importing = false;
        $this->reset(['csv', 'csvLabel', 'csvCurrency', 'csvAttribution']);
        $days = count($result->daily);
        $this->flash = "Imported {$days} ".str('day')->plural($days).' from '.$result->source_label.'.';
    }

    public function pullOrders(?int $id, CampaignResults $results): void
    {
        $campaign = $this->campaign($id);
        $this->campaignId = $campaign->id;
        $result = $results->importStoreOrders($campaign, auth()->user());
        $this->flash = 'Pulled '.number_format((int) $result->orders).' '.str('order')->plural((int) $result->orders).' from '.$result->source_label.'.';
    }

    public function deleteResult(int $resultId, CampaignResults $results): void
    {
        $results->deleteResult($this->campaign(), $resultId);
        $this->flash = 'Import removed.';
    }

    public function saveProposal(int $index, CampaignResults $results): void
    {
        $next = $results->saveProposal($this->campaign(), $index, auth()->user());
        $this->flash = "Saved “{$next->name}” as a planned campaign.";
    }

    public function render(CampaignPlanner $planner, CampaignResults $results)
    {
        $product = $this->product();
        $campaigns = $this->campaigns();
        $data = ['product' => $product, 'campaigns' => $campaigns];

        if ($this->mode === 'results') {
            $packIds = $product->campaignPacks()->pluck('id');
            $net = (int) WorkspaceCredit::query()->whereIn('campaign_pack_id', $packIds)->sum('amount');

            return view('livewire.product-campaigns', $data + [
                'summaries' => $campaigns->mapWithKeys(fn (Campaign $campaign) => [$campaign->id => $results->summary($campaign, $campaign->results)]),
                'creditsUsed' => max(0, -$net),
                'packCount' => $packIds->count(),
                'orderStores' => $campaigns->reject->isPlanned()->mapWithKeys(fn (Campaign $campaign) => [$campaign->id => $results->orderStore($campaign)]),
                'campaign' => null,
            ]);
        }

        $campaign = $this->campaignId ? $campaigns->firstWhere('id', $this->campaignId) : $campaigns->first();
        $this->campaignId = $campaign?->id;
        if ($campaign) {
            $campaign->load(['deliverables.contentItem', 'page', 'follows', 'followUps', 'launchedBy']);
            $summary = $results->summary($campaign, $campaign->results);
            $data += [
                'campaign' => $campaign,
                'readiness' => $planner->readiness($campaign),
                'approved' => $campaign->deliverables->mapWithKeys(fn ($deliverable) => [$deliverable->id => $planner->approvedVersion($deliverable->contentItem)]),
                'summary' => $summary,
                'proposals' => $results->proposals($campaign, $summary),
                'orderStore' => $campaign->isPlanned() ? null : $results->orderStore($campaign),
                'items' => $this->editing ? ContentItem::query()->where('product_id', $product->id)->orderByRaw("case when status = 'approved' then 0 else 1 end")->orderBy('title')->get(['id', 'title', 'type', 'status', 'current_version', 'banner_creative_id', 'campaign_pack_id']) : collect(),
                'pageOptions' => $this->editing ? $this->pagesFor() : collect(),
            ];
        }

        return view('livewire.product-campaigns', $data + ['campaign' => $campaign]);
    }

    private function product(): ?Product
    {
        return Product::query()->whereKey($this->productId)
            ->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id))
            ->with('brand')->first();
    }

    private function campaigns()
    {
        return Campaign::query()->where('product_id', $this->productId)->with('results')->orderByDesc('id')->get();
    }

    private function campaign(?int $id = null): Campaign
    {
        $campaign = Campaign::query()->where('product_id', $this->productId)->find($id ?? $this->campaignId);
        abort_unless($campaign && $this->product(), 404);

        return $campaign;
    }

    private function pagesFor()
    {
        return PageBuilder::available() ? Page::query()->where('product_id', $this->productId)->orderBy('name')->get(['id', 'name', 'live_version', 'draft_version']) : collect();
    }
}
