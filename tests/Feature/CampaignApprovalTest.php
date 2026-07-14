<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
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
            ->call('approveSource')
            ->set('reviewNote', 'Approved for the buyer handoff.')
            ->call('approveVersion')
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
        $this->actingAs($owner)->get(route('campaign-packs.export', [$pack, $version, 'voiceover']))->assertOk()->assertHeader('content-disposition', 'attachment; filename="review-pack-v1-voiceover.csv"')->assertSee('pace_wpm');
        $this->actingAs($owner)->get(route('campaign-packs.export', [$pack, $version, 'captions']))->assertOk()->assertHeader('content-disposition', 'attachment; filename="review-pack-v1-captions.csv"')->assertSee('caption');
        $this->actingAs($owner)->get(route('campaign-packs.export', [$pack, $version, 'shot-plan']))->assertOk()->assertHeader('content-disposition', 'attachment; filename="review-pack-v1-shot-plan.csv"')->assertSee('camera_framing');
        $this->get(route('campaign-packs.share', $pack->fresh()->shares()->firstOrFail()->token))->assertOk()->assertSee('MARKETING OWL');
    }

    public function test_a_broad_origin_source_cannot_be_approved_as_a_specific_handmade_claim(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update([
            'extracted_content' => 'Products are made, sourced, or packed in India.',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $version = $pack->versions()->firstOrFail();
        $version->update(['evidence' => [[
            'id' => 'claim-origin',
            'claim' => 'Handmade in India',
            'source' => $pack->sourceSnapshot->url,
            'excerpt' => 'Products are made, sourced, or packed in India.',
            'status' => 'too_specific_for_evidence',
        ]]]);

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('approveVersion')
            ->assertHasErrors(['reviewNote']);

        $this->assertSame('draft', $version->fresh()->review_status);
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
        $product = $brand->products()->create(['name' => 'Review Product', 'summary' => 'A directly supported review product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/review',
            'content_hash' => hash('sha256', 'review'),
            'status' => 'ready',
            'extracted_content' => 'A directly supported review product fact.',
            'extracted_truth' => ['description' => 'A directly supported review product fact.'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Review Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $pack];
    }
}
