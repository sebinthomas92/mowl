<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Livewire\ContentBrowser;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignPackSafetyValidator;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QaWordingFixTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = 'creative_routes.0.platform_assets.whatsapp_status.description_lines.1';

    public function test_a_hidden_unsupported_line_is_located_and_removed_in_one_click(): void
    {
        [$owner, $pack] = $this->fixture();

        $component = Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack]);
        $issue = collect($component->viewData('qaIssues'))->firstWhere('path', self::PATH);
        $this->assertSame('guaranteed', $issue['term']);
        $this->assertSame('7-day returns', $issue['fixed']);
        $this->assertStringStartsWith('Video route 1 › Platform assets › Whatsapp status › Description lines', $issue['where']);
        $this->assertStringContainsString('field=platform_assets__whatsapp_status__description_lines', $issue['edit_url']);
        $component->assertSee('Remove “guaranteed”');

        $component->call('removeUnsupportedWording', self::PATH, 'guaranteed');

        $latest = $pack->fresh()->versions()->orderByDesc('version')->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame('qa_fix', $latest->generator);
        $this->assertSame('draft', $latest->review_status);
        $this->assertSame('7-day returns', data_get($latest->content, self::PATH));
        $this->assertSame(2, $pack->fresh()->current_version);
        $this->assertNull(collect($component->viewData('qaIssues'))->firstWhere('path', self::PATH));
        $item = $pack->contentItems()->where('section', 'creative_routes.0')->first();
        $this->assertSame('7-day returns', $item->latestVersion->body['platform_assets']['whatsapp_status']['description_lines'][1]);
    }

    public function test_only_a_flagged_term_on_the_latest_version_can_be_removed(): void
    {
        [$owner, $pack, $workspace] = $this->fixture();

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('removeUnsupportedWording', 'creative_routes.0.platform_assets.whatsapp_status.description_lines.0', 'details')
            ->assertStatus(422);

        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        Livewire::actingAs($reviewer)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('removeUnsupportedWording', self::PATH, 'guaranteed')
            ->assertForbidden();

        $this->assertSame(1, $pack->versions()->count());
    }

    public function test_the_edit_link_opens_the_item_on_the_highlighted_field(): void
    {
        [$owner, $pack] = $this->fixture();
        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack]);
        $item = $pack->contentItems()->where('section', 'creative_routes.0')->first();

        Livewire::actingAs($owner)
            ->withQueryParams(['item' => $item->id, 'field' => 'platform_assets__whatsapp_status__description_lines'])
            ->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->assertSet('detailTab', 'edit')
            ->assertSet('fields.platform_assets__whatsapp_status__description_lines', "Explore the product page for verified details.\n7-day returns guaranteed")
            ->assertSeeHtml('field is-focus');
    }

    public function test_removing_a_term_tidies_the_line_or_declines(): void
    {
        $this->assertSame('7-day returns.', CampaignPackSafetyValidator::withoutTerm('7-day returns guaranteed.', 'guaranteed'));
        $this->assertSame('Fit for every desk', CampaignPackSafetyValidator::withoutTerm('Guaranteed fit for every desk', 'guaranteed'));
        $this->assertNull(CampaignPackSafetyValidator::withoutTerm('Handmade in Jaipur', 'made in'));
        $this->assertNull(CampaignPackSafetyValidator::withoutTerm('Guaranteed', 'guaranteed'));
        $this->assertNull(CampaignPackSafetyValidator::withoutTerm('Naturally bright', 'natural'));
        $this->assertSame('Shop the stand', CampaignPackSafetyValidator::withoutTerm('Shop the cruelty‑free stand', 'cruelty-free'));
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'QA Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'QA Brand']);
        $product = $brand->products()->create(['name' => 'QA Stand', 'summary' => 'A stand with 7-day returns.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/stand', 'content_hash' => hash('sha256', 'stand'), 'status' => 'ready',
            'extracted_content' => 'A stand with 7-day returns.', 'extracted_truth' => ['description' => 'A stand with 7-day returns.'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'QA Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $content = $result->content;
        $content['creative_routes'][0]['platform_assets']['whatsapp_status']['description_lines'][] = '7-day returns guaranteed';
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $pack, $workspace];
    }
}
