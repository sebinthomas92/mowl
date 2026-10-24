<?php

namespace App\Livewire\Hooks;

use App\Livewire\CampaignWorkspace;
use App\Livewire\ClientLink;
use App\Livewire\ClientPortal;
use App\Livewire\ConciergeIndex;
use App\Livewire\ContentBrowser;
use App\Livewire\InboxDrawer;
use App\Livewire\ProductCampaigns;
use App\Livewire\ProductHub;
use App\Livewire\ReviewQueue;
use App\Livewire\StoreConnections;
use App\Livewire\TeamIndex;
use App\Livewire\WorkspaceSettings;
use App\Support\Membership;
use Livewire\ComponentHook;

/**
 * Reviewers and clients are deny-by-default for Livewire actions: they may only call the browsing and
 * review actions listed here, so a new create or edit action is closed to them until it is added on purpose.
 * Owners and contributors are unaffected; owner-only actions keep their own checks.
 */
class EnforceRoleOnActions extends ComponentHook
{
    /** Components that authorize every action themselves (owner checks, review tokens, concierge emails). */
    private const SELF_GUARDED = [ClientLink::class, ClientPortal::class, ConciergeIndex::class, StoreConnections::class, TeamIndex::class, WorkspaceSettings::class];

    private const REVIEWER = [
        ReviewQueue::class => ['select', 'approve', 'requestChanges', 'approveSource'],
        ContentBrowser::class => ['select', 'showTab', 'filter', 'clearFilters', 'addComment'],
        CampaignWorkspace::class => ['selectVersion', 'addComment', 'approveVersion', 'rejectVersion', 'approveSource'],
        ProductHub::class => ['cancelEdit'],
        ProductCampaigns::class => ['show', 'cancelEditing'],
        InboxDrawer::class => ['open', 'markAllRead'],
    ];

    public function call($method, $params, $returnEarly)
    {
        // Magic actions ($set, $refresh, $toggle) only change public properties; actions read them back through scoped queries.
        if (str_starts_with((string) $method, '$') || in_array($this->component::class, self::SELF_GUARDED, true)) {
            return;
        }
        $membership = Membership::current();
        if (! $membership || $membership->canCreate()) {
            return;
        }
        $allowed = $membership->isReviewer() ? (self::REVIEWER[$this->component::class] ?? []) : [];
        abort_unless(in_array($method, $allowed, true), 403, 'Your role cannot do this.');
    }
}
