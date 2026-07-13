<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_review_approve_lock_comment_share_and_export_a_version(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('requestReview')
            ->set('commentSection', 'Meta copy')->set('commentBody', 'Use this after the pricing check.')
            ->call('addComment')
            ->set('reviewNote', 'Approved for the buyer handoff.')
            ->call('approveVersion')
            ->call('approveSource')
            ->call('createShare')
            ->assertSet('shareUrl', fn (?string $url) => str_contains((string) $url, '/shared/campaign-packs/'));

        $version = $pack->fresh()->versions()->firstOrFail();
        $this->assertSame('approved', $version->review_status);
        $this->assertSame($owner->id, $version->reviewed_by_user_id);
        $this->assertDatabaseCount('campaign_pack_version_comments', 1);
        $this->assertDatabaseCount('campaign_pack_shares', 1);
        $this->assertNotNull($pack->sourceSnapshot->fresh()->approved_at);
        $this->assertSame(5, $workspace->auditEvents()->count());
        $this->actingAs($owner)->get(route('campaign-packs.export', [$pack, $version, 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('campaign-packs.share', $pack->fresh()->shares()->firstOrFail()->token))->assertOk()->assertSee('MARKETING OWL');
    }

    public function test_member_cannot_approve_or_share_a_campaign_pack(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);

        Livewire::actingAs($member)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('approveVersion')
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Review Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'Review Brand']);
        $product = $brand->products()->create(['name' => 'Review Product']);
        $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/review', 'content_hash' => hash('sha256', 'review'), 'status' => 'ready']);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Review Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => ['direction' => ['title' => 'A direction', 'summary' => 'A summary'], 'product_truth' => ['name' => 'Review Product', 'price' => '', 'source' => $source->url, 'verified_facts' => []], 'audiences' => [], 'benefits' => [], 'meta' => ['primary_text' => 'Primary', 'headlines' => [], 'descriptions' => []], 'hooks' => [], 'script' => [], 'captions' => [], 'shot_log' => []], 'evidence' => [], 'compliance_flags' => []]);

        return [$owner, $workspace, $pack];
    }
}
