<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\ContentItem;
use App\Services\ContentLibrary;
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
        $this->ensureOwner();
        $version = $this->item()->latestVersion;
        $library->approve($version, auth()->user(), null, $this->comment ?: null);
        $this->flash = "{$version->item->title} v{$version->version} approved. Nothing was published.";
        $this->comment = '';
        $this->itemId = null;
    }

    public function requestChanges(ContentLibrary $library): void
    {
        $this->ensureOwner();
        $this->validate(['comment' => ['required', 'string', 'max:2000']], ['comment.required' => 'Say what should change so the author can act on it.']);
        $version = $this->item()->latestVersion;
        $library->requestChanges($version, auth()->user(), $this->comment);
        $this->flash = "Changes requested on {$version->item->title} v{$version->version}. The team has been notified.";
        $this->comment = '';
        $this->itemId = null;
    }

    public function approveSource(): void
    {
        $this->ensureOwner();
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
        $queue = ContentItem::query()
            ->where('workspace_id', $workspace->id)
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
            'recent' => ContentItem::query()->where('workspace_id', $workspace->id)->whereIn('status', ['approved', 'changes'])->latest('updated_at')->limit(5)->get(),
            'isOwner' => $this->isOwner(),
        ])->layout('components.layouts.app');
    }

    private function item(): ContentItem
    {
        $item = ContentItem::query()->where('workspace_id', $this->currentWorkspace()->id)->with(['latestVersion', 'campaignPack.sourceSnapshot'])->find($this->itemId ?? $this->firstInQueue());
        abort_unless($item && $item->latestVersion, 404);

        return $item;
    }

    private function firstInQueue(): ?int
    {
        return ContentItem::query()->where('workspace_id', $this->currentWorkspace()->id)->where('status', 'review')->orderBy('updated_at')->value('id');
    }

    private function isOwner(): bool
    {
        return $this->currentWorkspace()->users()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists();
    }

    private function ensureOwner(): void
    {
        abort_unless($this->isOwner(), 403);
    }
}
