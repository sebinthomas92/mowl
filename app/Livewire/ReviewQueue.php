<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\ContentItem;
use App\Services\ContentLibrary;
use App\Support\Membership;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Every version awaiting a decision, with the previous version beside it and the automatic checks. */
class ReviewQueue extends Component
{
    use InteractsWithWorkspace;

    #[Url(as: 'item')]
    public ?int $itemId = null;

    public string $comment = '';

    public ?string $flash = null;

    public function mount(): void
    {
        app(ContentLibrary::class)->syncWorkspace($this->currentWorkspace());
    }

    public function select(int $id): void
    {
        $this->itemId = $id;
        $this->comment = '';
        $this->resetValidation();
    }

    public function approve(ContentLibrary $library): void
    {
        $this->ensureReviewer();
        $version = $this->item()->latestVersion;
        $library->approve($version, auth()->user(), null, $this->comment ?: null);
        $this->flash = "{$version->item->title} v{$version->version} approved. Nothing was published.";
        $this->comment = '';
        $this->itemId = null;
    }

    public function requestChanges(ContentLibrary $library): void
    {
        $this->ensureReviewer();
        $this->validate(['comment' => ['required', 'string', 'max:2000']], ['comment.required' => 'Say what should change so the author can act on it.']);
        $version = $this->item()->latestVersion;
        $library->requestChanges($version, auth()->user(), $this->comment);
        $this->flash = "Changes requested on {$version->item->title} v{$version->version}. The team has been notified.";
        $this->comment = '';
        $this->itemId = null;
    }

    public function approveSource(): void
    {
        $this->ensureReviewer();
        $source = $this->item()->campaignPack?->sourceSnapshot;
        abort_unless($source, 422);
        $source->update(['approved_by_user_id' => auth()->id(), 'approved_at' => now()]);
        $this->currentWorkspace()->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => 'source_snapshot_approved', 'subject_type' => $source::class, 'subject_id' => $source->id, 'metadata' => [],
        ]);
    }

    public function render(ContentLibrary $library)
    {
        $workspace = $this->currentWorkspace();
        $queue = $this->scoped()
            ->where('status', 'review')
            ->with(['latestVersion', 'product.brand', 'campaignPack'])
            ->orderBy('updated_at')
            ->get();
        $selected = $queue->firstWhere('id', $this->itemId) ?? $queue->first();
        $versions = $selected ? $selected->versions()->with('comments')->orderByDesc('version')->get() : collect();
        $current = $versions->first();
        $previous = $versions->skip(1)->first();
        $source = $selected?->campaignPack?->sourceSnapshot;

        return view('livewire.review-queue', [
            'workspace' => $workspace,
            'queue' => $queue,
            'selected' => $selected,
            'current' => $current,
            'previous' => $previous,
            'versions' => $versions,
            'checks' => $current ? $library->checks($current) : [],
            'sourceNeedsApproval' => $source && ! $source->approved_at,
            'recent' => $this->scoped()->whereIn('status', ['approved', 'changes'])->latest('updated_at')->limit(5)->get(),
            'canReview' => (bool) $this->membership()?->canReview(),
            'roleLabel' => $this->membership()?->roleLabel(),
        ])->layout('components.layouts.app')->title('Review');
    }

    private function item(): ContentItem
    {
        // Only items in the queue: a stale or hand-edited ?item= never decides on a draft or on a different item than the one shown.
        $item = $this->scoped()->where('status', 'review')->with(['latestVersion', 'campaignPack.sourceSnapshot'])->find($this->itemId ?? $this->firstInQueue())
            ?? $this->scoped()->where('status', 'review')->with(['latestVersion', 'campaignPack.sourceSnapshot'])->find($this->firstInQueue());
        abort_unless($item && $item->latestVersion, 404);

        return $item;
    }

    private function firstInQueue(): ?int
    {
        return $this->scoped()->where('status', 'review')->orderBy('updated_at')->value('id');
    }

    /** Content in the workspace, limited to the member's brand. */
    private function scoped()
    {
        $query = ContentItem::query()->where('workspace_id', $this->currentWorkspace()->id);

        return $this->membership()?->scopeThroughProduct($query) ?? $query->whereRaw('1 = 0');
    }

    private function membership(): ?Membership
    {
        return Membership::of(auth()->user(), $this->currentWorkspace());
    }

    private function ensureReviewer(): void
    {
        abort_unless($this->membership()?->canReview(), 403);
    }
}
