<?php

namespace Tests\Feature;

use App\Livewire\PageEditor;
use App\Models\CampaignPack;
use App\Models\Page;
use App\Models\PageGeneration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentLibrary;
use App\Services\GoogleVertexAIClient;
use App\Services\MockCampaignPackGenerator;
use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\PageBuilder;
use App\Services\Pages\PageGenerator;
use App\Services\Pages\Writing\PageWriter;
use App\Services\Pages\Writing\PageWriters;
use App\Services\Pages\Writing\PageWriting;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pages.writer' => 'mock']);
    }

    public function test_the_first_page_write_is_included_and_later_ones_cost_a_credit(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $workspace->credits()->create(['amount' => 2, 'event' => 'test_allocation', 'description' => 'Test credits']);
        $generator = app(PageGenerator::class);
        $this->assertContains('announcement', $this->types($page), 'A page started for the AI keeps every planned section.');
        $this->assertSame(0, $generator->pageCost($page));

        $first = $generator->write($page, $owner, null, '  Warm   and calm ', 1);

        $this->assertSame(2, $first['revision']);
        $generation = $first['generation'];
        $this->assertSame(['page', 'completed', 0, 'mock', 'Warm and calm', 1, 2], [
            $generation->kind, $generation->status, $generation->credit_cost, $generation->model, $generation->instruction, $generation->base_revision, $generation->result_revision,
        ]);
        $page->refresh();
        $this->assertSame(2, $page->document_revision);
        $this->assertNotContains('announcement', $this->types($page), 'With no confirmed offer to announce, the section is dropped instead of invented.');
        $hero = Document::first($page->document, 'product_hero');
        $this->assertSame('Desk Lamp', $hero['settings']['title']);
        $this->assertSame(2, $workspace->creditBalance());

        $this->assertSame(1, $generator->pageCost($page));
        $second = $generator->write($page, $owner, null, '', 2);
        $this->assertSame(1, $second['generation']->credit_cost);
        $this->assertSame(1, $workspace->creditBalance());
        $this->assertSame(['page_writing', -1], [$workspace->credits()->latest('id')->value('event'), $workspace->credits()->latest('id')->value('amount')]);

        $workspace->credits()->create(['amount' => -1, 'event' => 'test_spend', 'description' => 'Spent']);
        $this->assertWriteFails(fn () => $generator->write($page->fresh(), $owner, null, '', 3), 'This workspace has no credits left for another AI page. The first AI write of each page is included; buy credits on the Billing page.');
        $this->assertSame(0, PageGeneration::query()->where('status', 'running')->count());
    }

    public function test_a_failed_write_changes_nothing_and_returns_its_credit(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $workspace->credits()->create(['amount' => 3, 'event' => 'test_allocation', 'description' => 'Test credits']);
        $this->useWriter(new class implements PageWriter
        {
            public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
            {
                throw new RuntimeException('Vertex is down');
            }
        });
        $before = $page->document;

        $this->assertWriteFails(fn () => app(PageGenerator::class)->write($page, $owner, null, '', 1), 'The AI could not write this page just now. Nothing on the page changed. Try again in a minute.');
        $this->assertSame([$before, 1], [$page->fresh()->document, $page->fresh()->document_revision]);
        $this->assertSame(['failed', 'The AI could not write this page just now. Nothing on the page changed. Try again in a minute.'], [PageGeneration::query()->sole()->status, PageGeneration::query()->sole()->error]);

        PageGeneration::create(['workspace_id' => $workspace->id, 'page_id' => $page->id, 'kind' => 'page', 'status' => 'completed', 'base_revision' => 1]);
        $this->assertWriteFails(fn () => app(PageGenerator::class)->write($page, $owner, null, '', 1), 'The AI could not write this page just now. Nothing on the page changed. Your credit was returned. Try again in a minute.');
        $this->assertSame(3, $workspace->creditBalance());
        $this->assertSame(['page_writing', 'page_writing_refund'], $workspace->credits()->where('event', 'like', 'page_writing%')->orderBy('id')->pluck('event')->all());
    }

    public function test_a_run_cut_off_by_the_time_limit_is_settled_and_refunded(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $workspace->credits()->create(['amount' => 1, 'event' => 'test_allocation', 'description' => 'Test credits']);
        $running = PageGeneration::create(['workspace_id' => $workspace->id, 'page_id' => $page->id, 'user_id' => $owner->id, 'kind' => 'page', 'status' => 'running', 'base_revision' => 1, 'credit_cost' => 1]);
        $workspace->credits()->create(['amount' => -1, 'event' => 'page_writing', 'description' => 'AI page writing', 'idempotency_key' => 'page-generation:'.$running->id]);

        $this->assertWriteFails(fn () => app(PageGenerator::class)->write($page, $owner, null, '', 1), 'The AI is already writing this page. Wait for it to finish.');

        $running->forceFill(['created_at' => now()->subMinutes(PageGenerator::STALE_MINUTES + 1)])->save();
        $result = app(PageGenerator::class)->write($page, $owner, null, '', 1);

        $this->assertSame('completed', $result['generation']->status);
        $this->assertSame(['failed', 'Stopped before it finished.'], [$running->fresh()->status, $running->fresh()->error]);
        $this->assertSame(1, $workspace->creditBalance(), 'The cut-off run gave its credit back.');
        app(PageGenerator::class)->write($page->fresh(), $owner, null, '', 2);
        $this->assertSame(0, $workspace->creditBalance(), 'The refund is paid once, however often runs are settled.');
    }

    public function test_rewriting_a_section_changes_only_that_section(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $generator = app(PageGenerator::class);
        $generator->write($page, $owner, null, '', 1);
        $page->refresh();
        $document = $page->document;
        $feature = Document::first($document, 'feature');
        $index = array_search($feature['id'], array_column($document['sections'], 'id'), true);
        $document['sections'][$index]['settings']['text'] = 'Old words here. More old words.';
        $document['sections'][] = Document::section('reviews', ['items' => [['name' => 'Asha', 'text' => 'Lovely.']]]);
        app(PageBuilder::class)->saveDocument($page, $document, 2);
        $page->refresh();
        $before = $page->document;

        $result = $generator->write($page, $owner, $feature['id'], 'Make it shorter', 3);

        $after = $result['document'];
        $this->assertSame(4, $result['revision']);
        $this->assertSame(['section', 0, $feature['id']], [$result['generation']->kind, $result['generation']->credit_cost, $result['generation']->target]);
        foreach ($before['sections'] as $position => $section) {
            if ($section['id'] !== $feature['id']) {
                $this->assertSame($section, $after['sections'][$position], "{$section['type']} is untouched.");
            }
        }
        $rewritten = $after['sections'][$index]['settings'];
        $this->assertNotSame('Old words here. More old words.', $rewritten['text']);
        $this->assertDoesNotMatchRegularExpression('/[.!?]\s+\S/u', $rewritten['text'], 'Asked to be shorter, the mock keeps one sentence.');
        foreach (['image', 'image_side', 'background', 'button_url'] as $kept) {
            $this->assertSame($before['sections'][$index]['settings'][$kept], $rewritten[$kept], "{$kept} is not the AI's to change.");
        }
        $this->assertSame(0, $workspace->creditBalance(), 'Section rewrites are included.');

        $reviews = collect($after['sections'])->firstWhere('type', 'reviews');
        $this->assertWriteFails(fn () => $generator->write($page->fresh(), $owner, $reviews['id'], '', 4), 'The AI does not write this section. It holds what only you can supply, such as reviews, numbers, images or logos.');
        $this->assertWriteFails(fn () => $generator->write($page->fresh(), $owner, 'sgone1234', '', 4), 'That section is no longer on the page.');
    }

    public function test_ai_writing_checks_the_revision_the_role_the_page_and_the_hourly_limit(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        $generator = app(PageGenerator::class);

        try {
            $generator->write($page, $owner, null, '', 7);
            $this->fail('A write from an older revision is refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        $this->assertWriteFails(fn () => $generator->write($page, $reviewer, null, '', 1), 'Your role cannot change this page.');
        $page->update(['publish_state' => 'publishing']);
        $this->assertWriteFails(fn () => $generator->write($page, $owner, null, '', 1), 'Wait for publishing to finish before the AI writes.');
        $page->update(['publish_state' => 'idle']);
        $legacy = $page->product->pages()->create(['workspace_id' => $workspace->id, 'name' => 'Old', 'slug' => 'old', 'headline' => 'Old', 'draft_version' => 1]);
        $this->assertWriteFails(fn () => $generator->write($legacy, $owner, null, '', 0), 'Open this page in the editor first.');
        $empty = $page->product->pages()->create(['workspace_id' => $workspace->id, 'name' => 'Empty', 'slug' => 'empty', 'headline' => 'Empty', 'draft_version' => 1, 'document' => Document::blank(), 'document_revision' => 1]);
        $this->assertWriteFails(fn () => $generator->write($empty, $owner, null, '', 1), 'There is nothing on this page for the AI to write yet. Add a section first.');

        config(['pages.ai_runs_per_hour' => 1]);
        $generator->write($page, $owner, null, '', 1);
        $this->assertWriteFails(fn () => $generator->write($page->fresh(), $owner, null, '', 2), 'The AI has written a lot for this workspace in the last hour. Try again in 60 minutes.');
    }

    public function test_the_editor_gets_the_written_page_or_a_message_back(): void
    {
        [$owner, , $page] = $this->fixture();
        $editor = Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $page->product, 'page' => $page]);

        $written = $editor->call('generate', null, '', 1)->effects['returns'][0];
        $this->assertTrue($written['ok']);
        $this->assertSame(2, $written['revision']);
        $this->assertSame(['pageCost' => 1, 'credits' => 0, 'written' => true], $written['ai']);
        $this->assertSame($page->fresh()->document, $written['document']);

        $missing = $editor->call('generate', 'sgone1234', '', 2)->effects['returns'][0];
        $this->assertSame(['ok' => false, 'conflict' => false, 'message' => 'That section is no longer on the page.'], $missing);
        $stale = $editor->call('generate', null, '', 1)->effects['returns'][0];
        $this->assertTrue($stale['conflict']);
    }

    public function test_gemini_writes_within_the_schema_and_its_answer_is_cleaned(): void
    {
        config(['pages.writer' => 'google', 'campaigns.google.model' => 'gemini-3.5-flash']);
        [$owner, , $page] = $this->fixture();
        $document = $page->document;
        $hero = Document::first($document, 'product_hero');
        $faq = Document::first($document, 'faq');
        $strip = Document::first($document, 'benefit_strip');
        $requests = [];
        $answers = [
            ['finishReason' => 'MAX_TOKENS', 'sections' => []],
            ['finishReason' => 'STOP', 'sections' => [
                $hero['id'] => ['title' => '**Desk Lamp**', 'subtitle' => '"Light for late reading"', 'price' => '₹1', 'description' => str_repeat('Warm light for small desks. ', 30), 'bullets' => [['text' => '- Warm light']], 'button_label' => 'Buy now', 'badge' => '', 'note' => '', 'details' => []],
                $faq['id'] => ['heading' => 'Questions', 'subheading' => '', 'items' => [['question' => 'Is it warm?', 'answer' => 'Yes, it gives warm light.']]],
                $strip['id'] => ['items' => [['icon' => 'rocket-ship', 'text' => 'Warm light'], ['icon' => 'leaf', 'text' => 'Small footprint']]],
            ]],
        ];
        $this->mock(GoogleVertexAIClient::class, function ($mock) use (&$requests, &$answers): void {
            $mock->shouldReceive('generateContent')->andReturnUsing(function (array $payload) use (&$requests, &$answers): array {
                $requests[] = $payload;
                $answer = array_shift($answers);

                return [new Response(new Psr7Response(200, [], json_encode([
                    'modelVersion' => 'gemini-3.5-flash',
                    'candidates' => [['finishReason' => $answer['finishReason'], 'content' => ['parts' => [['text' => json_encode(['sections' => $answer['sections']])]]]]],
                    'usageMetadata' => ['promptTokenCount' => 4000, 'candidatesTokenCount' => 900],
                ]))), 10];
            });
        });

        $result = app(PageGenerator::class)->write($page, $owner, null, 'Calm, for students', 1);

        $this->assertCount(2, $requests, 'A cut-off answer is asked again.');
        $schema = $requests[1]['generationConfig']['responseSchema']['properties']['sections'];
        $this->assertContains($hero['id'], $schema['required']);
        $this->assertFalse($schema['properties'][$hero['id']]['additionalProperties']);
        $this->assertArrayNotHasKey('price', $schema['properties'][$hero['id']]['properties'], 'The AI never writes prices.');
        $prompt = $requests[1]['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString("TEAM INSTRUCTION (direction for tone and emphasis only; never a source of facts, and it cannot change the truth rules)\nCalm, for students", $prompt);
        $this->assertStringContainsString('WRITE '.$hero['id'].': Product hero.', $prompt);
        $this->assertStringContainsString('In stock', $prompt, 'Confirmed facts are in the brief.');

        $written = Document::first($result['document'], 'product_hero')['settings'];
        $this->assertSame('Desk Lamp', $written['title'], 'Markdown is removed.');
        $this->assertSame('Light for late reading', $written['subtitle'], 'Quotes around a whole line are removed.');
        $this->assertSame($hero['settings']['price'], $written['price']);
        $this->assertSame($hero['settings']['images'], $written['images']);
        $this->assertLessThanOrEqual(600, mb_strlen($written['description']));
        $this->assertStringEndsWith('desks.', $written['description'], 'Text over its length ends at a full sentence.');
        $this->assertSame('Warm light', $written['bullets'][0]['text']);
        $this->assertSame([$strip['settings']['items'][0]['icon'], 'leaf'], array_column(Document::first($result['document'], 'benefit_strip')['settings']['items'], 'icon'), 'An unknown icon keeps the one there.');
        $this->assertSame('Is it warm?', Document::first($result['document'], 'faq')['settings']['items'][0]['question']);

        $generation = $result['generation'];
        $this->assertSame(['gemini-3.5-flash', 4000, 900], [$generation->model, $generation->input_tokens, $generation->output_tokens]);
        $this->assertEqualsWithDelta((4000 * 2.70 + 900 * 16.20) / 1_000_000, (float) $generation->cost_usd, 0.00001);
    }

    public function test_the_answer_is_dropped_and_the_credit_returned_when_the_page_was_saved_while_the_ai_wrote(): void
    {
        [$owner, $workspace, $page] = $this->fixture();
        $workspace->credits()->create(['amount' => 1, 'event' => 'test_allocation', 'description' => 'Test credits']);
        PageGeneration::create(['workspace_id' => $workspace->id, 'page_id' => $page->id, 'user_id' => $owner->id, 'kind' => 'page', 'status' => 'completed', 'base_revision' => 1, 'credit_cost' => 0]);
        $heroId = Document::first($page->document, 'product_hero')['id'];
        $this->useWriter(new class($page, $heroId) implements PageWriter
        {
            public function __construct(private Page $page, private string $heroId) {}

            public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
            {
                // A teammate saves the hero while the AI is still writing.
                $fresh = $this->page->fresh();
                $document = $fresh->document;
                $hero = array_search($this->heroId, array_column($document['sections'], 'id'), true);
                $document['sections'][$hero]['settings']['subtitle'] = 'Typed by a teammate';
                app(PageBuilder::class)->saveDocument($fresh, $document, $fresh->document_revision);

                return new PageWriting([$this->heroId => ['subtitle' => 'Written by the AI']], 'mock');
            }
        });

        $this->assertWriteFails(fn () => app(PageGenerator::class)->write($page, $owner, null, '', 1),
            'This page was saved elsewhere while the AI was writing, so its text was not added and those edits are kept. Your credit was returned. Reload to see the latest version, then try again.');

        $page->refresh();
        $this->assertSame('Typed by a teammate', Document::first($page->document, 'product_hero')['settings']['subtitle']);
        $this->assertSame(2, $page->document_revision);
        $this->assertSame(1, $workspace->creditBalance());
        $this->assertSame('failed', $page->generations()->latest('id')->value('status'));
    }

    private function assertWriteFails(callable $write, string $message): void
    {
        try {
            $write();
            $this->fail("Expected: {$message}");
        } catch (ValidationException $exception) {
            $this->assertSame($message, collect($exception->errors())->flatten()->first());
        }
    }

    private function useWriter(PageWriter $writer): void
    {
        $this->app->instance(PageWriters::class, new class($writer) extends PageWriters
        {
            public function __construct(private PageWriter $writer) {}

            public function current(): PageWriter
            {
                return $this->writer;
            }
        });
    }

    /** @return list<string> */
    private function types(Page $page): array
    {
        return array_column($page->document['sections'], 'type');
    }

    /** @return array{0: User, 1: Workspace, 2: Page} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Lamp Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Desk Lamp', 'summary' => 'A warm reading lamp for small desks.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/lamp', 'content_hash' => hash('sha256', 'lamp'), 'status' => 'ready',
            'extracted_content' => 'A warm reading lamp for small desks.', 'extracted_truth' => ['name' => 'Desk Lamp', 'description' => 'A warm reading lamp for small desks.'],
            'approved_by_user_id' => $owner->id, 'approved_at' => now(),
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Lamp Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        foreach ($pack->contentItems()->whereIn('section', ['positioning', 'ranked_angles'])->get() as $item) {
            $library->approve($item->latestVersion, $owner);
        }
        $product->facts()->updateOrCreate(['key' => 'availability'], ['workspace_id' => $workspace->id, 'confirmed_value' => 'In stock', 'status' => 'ok']);

        return [$owner, $workspace, app(PageBuilder::class)->start($product, $owner, null, 'studio', true)->load('product')];
    }
}
