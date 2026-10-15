<?php

namespace Tests\Feature;

use App\Livewire\ClientReview;
use App\Livewire\ContentBrowser;
use App\Livewire\ReviewQueue;
use App\Models\CampaignPack;
use App\Models\ContentClientLink;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ContentItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pack_splits_into_items_and_syncing_again_changes_nothing(): void
    {
        [, , $pack] = $this->fixture();
        $library = app(ContentLibrary::class);

        $library->syncPack($pack);
        $library->syncPack($pack);

        $items = $pack->contentItems()->orderBy('id')->get();
        $this->assertSame(['positioning', 'ranked_angles', 'creative_routes.0', 'creative_routes.1', 'creative_routes.2', 'offers', 'marketing_hub'], $items->pluck('section')->all());
        $this->assertTrue($items->every(fn (ContentItem $item) => $item->status === 'draft' && $item->versions()->count() === 1));
        $this->assertSame(['copy', 'copy', 'video', 'video', 'video', 'copy', 'copy'], $items->pluck('type')->all());
    }

    public function test_approving_every_item_approves_the_pack_version_and_keeps_exports_working(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);

        foreach ($pack->contentItems()->get() as $item) {
            $library->submit($item->latestVersion, $owner);
        }
        $this->assertSame('review', $pack->fresh()->status);

        $first = $pack->contentItems()->orderBy('id')->firstOrFail();
        Livewire::actingAs($owner)->withQueryParams(['item' => $first->id])->test(ReviewQueue::class)
            ->assertSee('Approve v1')
            ->call('approve')
            ->assertHasNoErrors();
        foreach ($pack->contentItems()->where('status', 'review')->get() as $item) {
            $library->approve($item->latestVersion, $owner);
        }

        $pack->refresh();
        $version = $pack->versions()->firstOrFail();
        $this->assertSame('approved', $pack->status);
        $this->assertSame(1, $pack->versions()->count());
        $this->assertSame('approved', $version->review_status);
        $this->actingAs($owner)->get(route('campaign-packs.export', [$pack, $version, 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_edited_section_is_assembled_into_a_new_approved_pack_version(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();

        Livewire::actingAs($owner)->withQueryParams(['item' => $positioning->id])->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('showTab', 'edit')
            ->set('fields.value_proposition', 'An edited, directly supported proposition.')
            ->call('saveDraft')
            ->call('submit')
            ->assertSet('flash', 'Positioning v1 submitted for review.');

        foreach ($pack->contentItems()->get() as $item) {
            $item->status === 'review' || $library->submit($item->latestVersion, $owner);
            $library->approve($item->fresh()->latestVersion, $owner);
        }

        $pack->refresh();
        $assembled = $pack->versions()->where('version', 2)->firstOrFail();
        $this->assertSame('assembled', $assembled->generator);
        $this->assertSame('approved', $assembled->review_status);
        $this->assertSame('An edited, directly supported proposition.', $assembled->content['positioning']['value_proposition']);
        $this->assertSame(2, $pack->current_version);

        $library->syncWorkspace($workspace);
        $this->assertSame(1, $positioning->versions()->count());
    }

    public function test_members_cannot_decide_and_requested_changes_need_a_comment(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $offers = $pack->contentItems()->where('section', 'offers')->firstOrFail();
        $library->submit($offers->latestVersion, $member);

        Livewire::actingAs($member)->test(ReviewQueue::class)->call('approve')->assertForbidden();

        Livewire::actingAs($owner)->test(ReviewQueue::class)
            ->call('requestChanges')
            ->assertHasErrors(['comment' => 'required'])
            ->set('comment', 'Lead with the value, not the discount.')
            ->call('requestChanges')
            ->assertHasNoErrors();

        $this->assertSame('changes', $offers->fresh()->status);
        $this->assertSame('rejected', $pack->fresh()->status);
        $this->assertDatabaseHas('content_comments', ['body' => 'Lead with the value, not the discount.', 'author_role' => 'owner']);
        $this->assertSame(1, $member->notifications()->count());

        $revision = $library->revise($offers->fresh(), $member);
        $this->assertSame(2, $revision->version);
        $this->assertSame('draft', $offers->fresh()->status);
    }

    public function test_a_price_that_differs_from_confirmed_knowledge_blocks_approval(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $pack->product->facts()->create(['workspace_id' => $workspace->id, 'key' => 'price', 'confirmed_value' => 'USD 39', 'status' => 'ok', 'confirmed_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $version = $pack->contentItems()->where('section', 'positioning')->firstOrFail()->latestVersion;
        $library->saveDraft($version, ['value_proposition' => 'Now only $49 for a better desk.']);

        $check = collect($library->checks($version->fresh()))->firstWhere('blocking', true);
        $this->assertSame('Price 49 differs from confirmed Knowledge (USD 39)', $check['label']);

        $this->expectException(ValidationException::class);
        $library->approve($version->fresh(), $owner);
    }

    public function test_a_shipping_threshold_or_struck_out_price_beside_the_product_price_is_not_a_wrong_price(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $pack->sourceSnapshot->update([
            'approved_by_user_id' => $owner->id, 'approved_at' => now(),
            'extracted_content' => 'Kindle Stand ₹750₹699 Inclusive of all taxes. Free shipping on orders over ₹499. '.str_repeat('Care notes. ', 60).'You may also like: Cloud Organiser ₹899',
            'extracted_truth' => ['price' => '699.00', 'currency' => 'INR'],
        ]);
        $pack->product->facts()->create(['workspace_id' => $workspace->id, 'key' => 'price', 'confirmed_value' => 'INR 699.00', 'status' => 'ok', 'confirmed_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $version = $pack->contentItems()->where('section', 'positioning')->firstOrFail()->latestVersion;
        $priceCheck = fn () => collect($library->checks($version->fresh()))->first(fn (array $check) => str_starts_with($check['label'], 'Price'));

        $library->saveDraft($version, ['value_proposition' => 'Now INR 699, down from ₹750, with free shipping over INR 499.']);
        $this->assertTrue($priceCheck()['ok'], $priceCheck()['label']);

        $library->saveDraft($version->fresh(), ['value_proposition' => 'Now only ₹899.']);
        $this->assertSame('Price 899 differs from confirmed Knowledge (INR 699.00)', $priceCheck()['label']);

        // The struck-out amount stated as the price is still a wrong price.
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Was great value. Now only ₹750.']);
        $this->assertSame('Price 750 differs from confirmed Knowledge (INR 699.00)', $priceCheck()['label']);
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Free shipping, ₹750 only.']);
        $this->assertSame('Price 750 differs from confirmed Knowledge (INR 699.00)', $priceCheck()['label']);
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Get it for ₹750 MRP, now ₹699, free shipping on orders over ₹499.']);
        $this->assertTrue($priceCheck()['ok'], $priceCheck()['label']);
        // The copy must say what the page says about the amount, within one field.
        foreach (['Free shipping over ₹750.', 'Save ₹750 today.', 'Was ₹499, now ₹699.'] as $wrong) {
            $library->saveDraft($version->fresh(), ['value_proposition' => $wrong]);
            $this->assertFalse($priceCheck()['ok'], $wrong);
        }
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Free shipping', 'brand_position' => '₹750 only']);
        $this->assertSame('Price 750 differs from confirmed Knowledge (INR 699.00)', $priceCheck()['label']);
        $library->saveDraft($version->fresh(), ['value_proposition' => '₹750 (MRP), now ₹699.', 'brand_position' => 'Quiet design for readers.']);
        $this->assertTrue($priceCheck()['ok'], $priceCheck()['label']);

        // Wording from a live pack.
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Launch price of INR 699.00 with complimentary shipping (order value exceeds INR 499 threshold). The price of INR 699.00 exceeds the complimentary shipping threshold of INR 499.00. Free delivery over INR 499.']);
        $this->assertTrue($priceCheck()['ok'], $priceCheck()['label']);

        // A page that never shows the product's price ties no other amount on it to the product.
        $pack->sourceSnapshot->update(['extracted_content' => 'Kindle Stand. Was ₹899. You may also like: Cloud Organiser ₹750', 'extracted_truth' => []]);
        $library->saveDraft($version->fresh(), ['value_proposition' => 'Now ₹899.']);
        $this->assertSame('Price 899 differs from confirmed Knowledge (INR 699.00)', $priceCheck()['label']);
    }

    public function test_a_named_client_link_shows_only_review_items_and_records_decisions(): void
    {
        [$owner, $workspace, $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();
        $offers = $pack->contentItems()->where('section', 'offers')->firstOrFail();
        $library->submit($positioning->latestVersion, $owner);
        $library->submit($offers->latestVersion, $owner);

        $url = Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->set('clientName', 'Priya at Northline')
            ->call('createClientLink')
            ->assertHasNoErrors()
            ->get('newLinkUrl');
        $token = basename((string) $url);
        $this->assertDatabaseHas('content_client_links', ['client_name' => 'Priya at Northline', 'token_hash' => hash('sha256', $token)]);
        auth()->logout();

        $this->get($url)->assertOk()->assertSee('Review for Priya at Northline')->assertSee('Approve v1')->assertDontSee('Ranked angles');

        Livewire::test(ClientReview::class, ['token' => $token])
            ->call('approve', $positioning->id)
            ->assertHasNoErrors()
            ->call('requestChanges', $offers->id)
            ->assertHasErrors(['comments.'.$offers->id => 'required'])
            ->set('comments.'.$offers->id, 'Please drop the bundle line.')
            ->call('requestChanges', $offers->id)
            ->assertHasNoErrors();

        $this->assertSame('approved', $positioning->fresh()->status);
        $this->assertSame('Priya at Northline', $positioning->fresh()->latestVersion->reviewed_by_name);
        $this->assertSame('changes', $offers->fresh()->status);
        $this->assertDatabaseHas('content_comments', ['author_name' => 'Priya at Northline', 'author_role' => 'client']);

        ContentClientLink::query()->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();
        ContentClientLink::query()->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->get($url)->assertNotFound();
    }

    public function test_only_owners_create_client_links(): void
    {
        [, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);

        Livewire::actingAs($member)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->assertDontSee('Client review links')
            ->set('clientName', 'Someone')
            ->call('createClientLink')
            ->assertForbidden();
    }

    public function test_library_review_and_content_pages_render_and_fall_back_before_the_migration(): void
    {
        [$owner, , $pack] = $this->fixture();

        $this->actingAs($owner)->get(route('library.index'))->assertOk()->assertSee('Channel copy');
        $this->actingAs($owner)->get(route('review.index'))->assertOk()->assertSee('The queue is clear');
        $this->actingAs($owner)->get(route('products.show', [$pack->product, 'tab' => 'content']))->assertOk()->assertSee('Ranked angles');
        $this->actingAs($owner)->get(route('campaign-packs.show', $pack))->assertOk()->assertSee('each reviewed on its own');

        Schema::rename('content_items', 'pending_content_items');
        app()->forgetInstance('content-library.available');
        try {
            $this->actingAs($owner)->get(route('campaign-packs.show', $pack))->assertOk()->assertSee('HUMAN REVIEW')->assertDontSee('each reviewed on its own');
            $this->actingAs($owner)->get(route('products.show', [$pack->product, 'tab' => 'overview']))->assertOk();
        } finally {
            Schema::rename('pending_content_items', 'content_items');
        }
    }

    public function test_other_workspaces_cannot_see_or_open_items(): void
    {
        [, , $pack] = $this->fixture();
        app(ContentLibrary::class)->syncPack($pack);
        [$outsider] = $this->fixture('Outside');
        $item = $pack->contentItems()->firstOrFail();

        Livewire::actingAs($outsider)->test(ContentBrowser::class)->assertDontSee('Review Product')->call('select', $item->id)->assertNotFound();
        Livewire::actingAs($outsider)->test(ContentBrowser::class, ['productId' => $pack->product_id])->assertNotFound();
    }

    public function test_an_edit_that_adds_an_unsupported_claim_blocks_approval(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();
        $library->saveDraft($positioning->latestVersion, ['value_proposition' => 'Clinically proven to cut eye strain by 40%.']);

        $check = collect($library->checks($positioning->fresh()->latestVersion))->first(fn (array $check) => str_starts_with($check['label'], 'Edited text'));
        $this->assertFalse($check['ok']);
        $this->assertTrue($check['blocking']);
        $this->assertStringContainsString('clinically', $check['label']);
        $this->assertStringContainsString('40', $check['label']);

        try {
            $library->approve($positioning->fresh()->latestVersion, $owner);
            $this->fail('An unsupported edited claim was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Edited text adds a claim', $exception->errors()['decision'][0]);
        }
        $this->assertSame('draft', $positioning->fresh()->status);

        // A plain rewording, and a variation that keeps it, stay approvable.
        $library->saveDraft($positioning->fresh()->latestVersion, ['value_proposition' => 'A clear, directly supported reason to choose this product.']);
        $this->assertTrue(collect($library->checks($positioning->fresh()->latestVersion))->firstWhere('label', 'Edited text adds no unsupported claims')['ok']);
        $library->approve($positioning->fresh()->latestVersion, $owner);

        $variation = $library->variation($positioning->fresh(), $owner);
        $library->saveDraft($variation->latestVersion, ['value_proposition' => 'The best reading lamp, guaranteed.']);
        $this->expectException(ValidationException::class);
        $library->approve($variation->fresh()->latestVersion, $owner);
    }

    public function test_a_source_whose_content_changes_loses_its_approval(): void
    {
        [$owner, , $pack] = $this->fixture();
        $source = $pack->sourceSnapshot;
        $source->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);

        $source->update(['fetched_at' => now(), 'status' => 'ready', 'extracted_content' => $source->extracted_content]);
        $this->assertNotNull($source->fresh()->approved_at, 'An unchanged refetch keeps the approval.');

        $source->update(['content_hash' => hash('sha256', 'changed'), 'extracted_content' => 'Now 50% off, clinically proven.', 'fetched_at' => now()]);
        $this->assertNull($source->fresh()->approved_at);
        $this->assertNull($source->fresh()->approved_by_user_id);

        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $this->expectException(ValidationException::class);
        $library->approve($pack->contentItems()->where('section', 'positioning')->firstOrFail()->latestVersion, $owner);
    }

    public function test_opening_an_older_pack_creates_its_items(): void
    {
        [$owner, , $pack] = $this->fixture();
        $this->assertSame(0, $pack->contentItems()->count());

        $this->actingAs($owner)->get(route('campaign-packs.show', $pack))->assertOk()->assertSee('7 items, each reviewed on its own');
        $this->assertSame(7, $pack->contentItems()->count());
    }

    private function fixture(string $name = 'Review'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => "{$name} Agency"]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => "{$name} Brand"])->products()->create(['name' => "{$name} Product", 'summary' => 'A directly supported review product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/'.strtolower($name),
            'content_hash' => hash('sha256', $name),
            'status' => 'ready',
            'extracted_content' => 'A directly supported review product fact.',
            'extracted_truth' => ['description' => 'A directly supported review product fact.'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$name} Pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $pack];
    }
}
