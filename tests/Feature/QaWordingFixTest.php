<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Livewire\ContentBrowser;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignPackSafetyValidator;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

        $this->assertSame(1, $pack->versions()->count());
        $item = $pack->contentItems()->where('section', 'creative_routes.0')->first();
        $this->assertSame('7-day returns', $item->latestVersion->body['platform_assets']['whatsapp_status']['description_lines'][1]);
        $this->assertSame('draft', $item->latestVersion->status);
        $this->assertNull(collect($component->viewData('qaIssues'))->firstWhere('path', self::PATH));
    }

    public function test_an_edited_output_clears_the_pack_check_and_unblocks_other_outputs(): void
    {
        [$owner, $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack]);
        $library = app(ContentLibrary::class);
        $positioning = $pack->contentItems()->where('section', 'positioning')->first();

        $route = $pack->contentItems()->where('section', 'creative_routes.0')->first();
        $this->assertStringContainsString('7-day returns guaranteed', collect($library->checks($route->latestVersion))->firstWhere('ok', false)['label']);

        $library->approve($positioning->latestVersion, $owner);
        $this->assertSame('approved', $positioning->fresh()->status);

        $library->saveDraft($route->latestVersion, ['platform_assets.whatsapp_status.description_lines' => "Explore the product page for verified details.\n7-day returns"]);
        $library->approve($route->fresh()->latestVersion, $owner);
        $this->assertSame('approved', $route->fresh()->status);

        $component = Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack]);
        $this->assertSame([], collect($component->viewData('qaIssues'))->where('type', 'claim')->all());
        $component->call('approveVersion')->assertHasErrors(['reviewNote']);
        $this->assertSame('draft', $pack->versions()->first()->review_status);
    }

    public function test_an_edited_output_that_adds_a_claim_still_blocks_its_own_approval(): void
    {
        [$owner, $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack]);
        $library = app(ContentLibrary::class);
        $positioning = $pack->contentItems()->where('section', 'positioning')->first();
        $field = collect($positioning->latestVersion->body)->filter(fn ($value) => is_string($value))->keys()->first();
        $library->saveDraft($positioning->latestVersion, [$field => 'Clinically proven and waterproof']);

        $this->expectException(ValidationException::class);
        $library->approve($positioning->fresh()->latestVersion, $owner);
    }

    public function test_text_outside_any_output_is_fixed_in_a_new_pack_version(): void
    {
        [$owner, $pack] = $this->fixture(fn (array $content) => data_set($content, 'product_truth.supported_benefits', ['Guaranteed sturdy build']) ? $content : $content);
        $path = 'product_truth.supported_benefits.0';

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->call('removeUnsupportedWording', $path, 'guaranteed');

        $latest = $pack->fresh()->versions()->orderByDesc('version')->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame('qa_fix', $latest->generator);
        $this->assertSame('Sturdy build', data_get($latest->content, $path));
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

    private function fixture(?\Closure $change = null): array
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
        if ($change) {
            $content = $change($content);
        }
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $pack, $workspace];
    }
}
