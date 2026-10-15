<?php

namespace Tests\Feature;

use App\Livewire\WorkflowIndex;
use App\Models\LibraryPrompt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LibraryPreviews;
use App\Services\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private string $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = storage_path('framework/testing/workflows-'.uniqid());
        File::ensureDirectoryExists($this->library);
        File::put($this->library.'/kinetic-title.md', <<<'MD'
---
name: Kinetic title
category: motion
kind: skill
length: 0:12
needs: A headline and brand fonts
gives: An animated title sequence
source_url: https://prompt-showcase.pages.dev/
---
Animated type for product launches.

## Title reveal
Animate the headline "{{headline}}" letter by letter.

## Outro
Hold the logo for 2 seconds.
MD);
        File::put($this->library.'/hero-scroll.md', "---\nname: Hero scroll animation\ncategory: web\n---\n## Scroll\nFade the hero on scroll.\n");
        File::put($this->library.'/broken.md', "No frontmatter here\n## Prompt\nIgnored.\n");
        config(['workflows.library_path' => $this->library]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->library);
        parent::tearDown();
    }

    public function test_catalog_reads_prompt_files_and_hosted_workflows(): void
    {
        $catalog = app(WorkflowCatalog::class);

        $kinetic = $catalog->find('kinetic-title');
        $this->assertSame('Motion videos', $kinetic['category_label']);
        $this->assertSame('download', $kinetic['availability']);
        $this->assertSame('Animated type for product launches.', $kinetic['description']);
        $this->assertSame(['Title reveal', 'Outro'], array_column($kinetic['prompts'], 'title'));
        $this->assertSame('Animate the headline "{{headline}}" letter by letter.', $kinetic['prompts'][0]['body']);
        $this->assertSame('Website design', $catalog->find('hero-scroll')['category_label']);
        $this->assertNull($catalog->find('broken'));
        $this->assertSame('run', $catalog->find('launch-pack')['availability']);
    }

    public function test_workflows_page_lists_prompts_and_filters_by_category(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get(route('workflows.index'))
            ->assertOk()
            ->assertSee('Workflows')
            ->assertSee('Kinetic title')
            ->assertSee('Launch pack')
            ->assertSee(route('help.index'), false);

        Livewire::actingAs($user)->test(WorkflowIndex::class)
            ->call('setCategory', 'web')
            ->assertSee('Hero scroll animation')
            ->assertDontSee('Kinetic title')
            ->call('select', 'hero-scroll')
            ->assertSee('Fade the hero on scroll.')
            ->assertSee('Copy prompt')
            ->assertSee(route('workflows.download', 'hero-scroll'), false)
            ->call('setCategory', 'nonsense')
            ->assertSet('category', 'all');
    }

    public function test_selected_workflow_shows_its_prompts_and_run_action(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->withQueryParams(['workflow' => 'kinetic-title'])->test(WorkflowIndex::class)
            ->assertSee('Title reveal')
            ->assertSee('Hold the logo for 2 seconds.');

        Livewire::actingAs($user)->test(WorkflowIndex::class)
            ->call('select', 'launch-pack')
            ->assertSee('Start a launch pack')
            ->assertSee(route('campaign-packs.create'), false);
    }

    public function test_download_returns_the_prompt_file_only_for_library_workflows(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)->get(route('workflows.download', 'kinetic-title'))->assertOk();
        $this->assertStringContainsString('attachment; filename="kinetic-title.md"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('## Title reveal', $response->getContent());

        $this->actingAs($user)->get(route('workflows.download', 'launch-pack'))->assertNotFound();
        $this->actingAs($user)->get(route('workflows.download', 'missing'))->assertNotFound();
        auth()->logout();
        $this->get(route('workflows.download', 'kinetic-title'))->assertRedirect(route('login'));
    }

    public function test_help_page_links_guides_and_the_prompt_library(): void
    {
        $this->actingAs($this->member())->get(route('help.index'))
            ->assertOk()
            ->assertSee('Guides and resources')
            ->assertSee(route('products.create'), false)
            ->assertSee('2</span> prompt packs and skills', false)
            ->assertSee(config('workflows.source_url'), false);
    }

    public function test_shipped_prompt_library_parses(): void
    {
        config(['workflows.library_path' => resource_path('workflows')]);
        $files = collect(File::glob(resource_path('workflows/*.md')));
        $catalog = new WorkflowCatalog;

        foreach ($files as $file) {
            $this->assertNotNull($catalog->find(pathinfo($file, PATHINFO_FILENAME)), basename($file).' did not parse');
        }
        $this->assertTrue(true);
    }

    public function test_import_command_loads_a_licensed_collection_as_packs(): void
    {
        $file = $this->library.'/export.json';
        File::put($file, json_encode([
            ['id' => 'a1', 'title' => 'Aurora hero', 'category' => 'Hero Section', 'tags' => ['premium'], 'body' => "Build a hero.\n## Layout\nTwo columns."],
            ['id' => 'a2', 'title' => 'Plain hero', 'category' => 'Hero Section', 'tags' => ['free'], 'body' => 'Build a plain hero.'],
            ['id' => 'b1', 'title' => 'Agency site', 'category' => 'Agency', 'tags' => ['free'], 'body' => 'Build an agency site.'],
            ['id' => 'x', 'title' => '', 'body' => 'skipped'],
        ]));

        $this->artisan('workflows:import-prompts', ['file' => $file])
            ->expectsOutput('3 prompts imported into 2 packs.')
            ->assertSuccessful();

        $hero = app(WorkflowCatalog::class)->find('motionsites-hero-section');
        $this->assertSame('Hero Section', $hero['name']);
        $this->assertSame('Website design', $hero['category_label']);
        $this->assertSame('2 prompts, 1 premium.', $hero['description']);
        $this->assertSame(['Aurora hero', 'Plain hero'], array_column($hero['prompts'], 'title'));
        $this->assertStringContainsString('MotionSites.ai', $hero['credit']);

        // Re-importing updates by id and drops prompts the export no longer has.
        File::put($file, json_encode([['id' => 'a1', 'title' => 'Aurora hero v2', 'category' => 'Hero Section', 'body' => 'New body.', 'credit' => 'Prompt by @ana']]));
        $this->artisan('workflows:import-prompts', ['file' => $file])->assertSuccessful();
        $this->assertSame(1, LibraryPrompt::count());
        $this->assertSame('free', LibraryPrompt::first()->tier);
        $this->assertSame('Prompt by @ana', LibraryPrompt::first()->credit);
        File::put($file, json_encode([['id' => 'm1', 'title' => 'Orbit', 'category' => 'UI motion', 'body' => 'Animate.', 'library_category' => 'animation'], ['id' => 'm2', 'title' => 'Reel', 'category' => 'Reels', 'body' => 'Render.']]));
        $this->artisan('workflows:import-prompts', ['file' => $file, '--collection' => 'opus-videos', '--category' => 'motion'])->assertSuccessful();
        $this->assertSame(['animation', 'motion'], LibraryPrompt::where('collection', 'opus-videos')->orderBy('source_key')->pluck('category')->all());
        $this->artisan('workflows:import-prompts', ['file' => $file, '--category' => 'nope'])->assertFailed();
        $this->artisan('workflows:import-prompts', ['file' => $this->library.'/missing.json'])->assertFailed();
    }

    public function test_database_packs_show_premium_tags_credit_and_download(): void
    {
        LibraryPrompt::create(['collection' => 'motionsites', 'source_key' => '1', 'pack' => 'Landing Page', 'title' => 'Glass landing', 'body' => "Intro\n## Section\nDetails", 'tier' => 'premium', 'position' => 1, 'credit' => 'From the MotionSites.ai collection, licensed for Marketing Owl']);
        $user = $this->member();

        Livewire::actingAs($user)->withQueryParams(['workflow' => 'motionsites-landing-page'])->test(WorkflowIndex::class)
            ->assertSee('Glass landing')
            ->assertSee('Premium')
            ->assertSee('licensed for Marketing Owl');

        $download = $this->actingAs($user)->get(route('workflows.download', 'motionsites-landing-page'))->assertOk()->getContent();
        $this->assertStringContainsString("## Glass landing\nIntro\n### Section", $download);
        $this->assertStringContainsString('credit: From the MotionSites.ai collection', $download);
    }

    public function test_prompt_previews_are_copied_to_storage_and_served_in_ranges(): void
    {
        Storage::fake('previews');
        config(['workflows.media_disk' => 'previews']);
        $video = str_repeat('v', 5000);
        Http::fake([
            'cdn.example.com/hero.mp4' => Http::response($video, 200, ['Content-Type' => 'video/mp4']),
            'cdn.example.com/hero.jpg' => Http::response('jpg', 200, ['Content-Type' => 'image/jpeg']),
            'cdn.example.com/page.html' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $file = $this->library.'/export.json';
        File::put($file, json_encode([
            ['id' => 'a1', 'title' => 'Aurora hero', 'category' => 'Hero Section', 'body' => 'Build a hero.', 'video_url' => 'https://cdn.example.com/hero.mp4', 'poster_url' => 'https://cdn.example.com/hero.jpg'],
            ['id' => 'a2', 'title' => 'Plain hero', 'category' => 'Hero Section', 'body' => 'Build a plain hero.', 'video_url' => 'https://cdn.example.com/page.html', 'poster_url' => 'http://insecure.example.com/x.jpg'],
        ]));
        $this->artisan('workflows:import-prompts', ['file' => $file])->assertSuccessful();
        [$aurora, $plain] = [LibraryPrompt::where('source_key', 'a1')->first(), LibraryPrompt::where('source_key', 'a2')->first()];
        $this->assertNull($plain->poster_url);
        $user = $this->member();

        $hero = app(WorkflowCatalog::class)->find('motionsites-hero-section');
        $this->assertSame(route('workflows.preview', [$aurora->id, 'poster']), $hero['poster']);
        Livewire::actingAs($user)->withQueryParams(['workflow' => 'motionsites-hero-section'])->test(WorkflowIndex::class)
            ->assertSee(route('workflows.preview', [$aurora->id, 'video']), false);

        $this->actingAs($user)->get(route('workflows.preview', [$aurora->id, 'video']))
            ->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Accept-Ranges', 'bytes');
        $path = $aurora->fresh()->video_path;
        Storage::disk('previews')->assertExists($path);

        $partial = $this->actingAs($user)->withHeader('Range', 'bytes=100-199')->get(route('workflows.preview', [$aurora->id, 'video']));
        $partial->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-199/5000')->assertHeader('Content-Length', '100');
        $this->assertSame(str_repeat('v', 100), $partial->streamedContent());
        $this->actingAs($user)->withHeader('Range', 'bytes=9000-')->get(route('workflows.preview', [$aurora->id, 'video']))->assertStatus(416);
        $this->flushHeaders();

        // Served from the stored copy without fetching again (one size check, one download).
        Http::assertSentCount(2);
        $this->actingAs($user)->get(route('workflows.preview', [$aurora->id, 'poster']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($user)->get(route('workflows.preview', [$plain->id, 'video']))->assertNotFound();
        $this->actingAs($user)->get(route('workflows.preview', [$plain->id, 'poster']))->assertNotFound();

        // A changed source URL drops the stored copy so it is fetched again.
        File::put($file, json_encode([['id' => 'a1', 'title' => 'Aurora hero', 'category' => 'Hero Section', 'body' => 'Build a hero.', 'video_url' => 'https://cdn.example.com/hero-v2.mp4']]));
        $this->artisan('workflows:import-prompts', ['file' => $file])->assertSuccessful();
        $this->assertNull($aurora->fresh()->video_path);
        $this->assertNull($aurora->fresh()->poster_url);

        auth()->logout();
        $this->get(route('workflows.preview', [$aurora->id, 'video']))->assertRedirect(route('login'));
    }

    public function test_previews_the_app_cannot_serve_are_shown_from_the_source(): void
    {
        Storage::fake('previews');
        config(['workflows.media_disk' => 'previews']);
        Http::fake([
            'cdn.example.com/big.gif' => Http::response(str_repeat('g', LibraryPreviews::MAX_BYTES['poster'] + 1), 200, ['Content-Type' => 'image/gif']),
            'cdn.example.com/huge.gif' => Http::response('', 200, ['Content-Type' => 'image/gif', 'Content-Length' => (string) (5 * 1024 * 1024)]),
            'cdn.example.com/gone.gif' => Http::response('', 404),
            '*' => Http::response('unexpected', 500),
        ]);
        $big = LibraryPrompt::create(['collection' => 'motionsites', 'source_key' => '1', 'pack' => 'Hero', 'title' => 'Big', 'body' => 'b', 'poster_url' => 'https://cdn.example.com/big.gif', 'video_url' => 'https://stream.mux.com/abc.m3u8']);
        $gone = LibraryPrompt::create(['collection' => 'motionsites', 'source_key' => '2', 'pack' => 'Hero', 'title' => 'Gone', 'body' => 'b', 'poster_url' => 'https://cdn.example.com/gone.gif']);
        $huge = LibraryPrompt::create(['collection' => 'motionsites', 'source_key' => '3', 'pack' => 'Hero', 'title' => 'Huge', 'body' => 'b', 'poster_url' => 'https://cdn.example.com/huge.gif']);
        Storage::disk('previews')->put('library/motionsites/old.gif', str_repeat('o', LibraryPreviews::MAX_BYTES['poster'] + 1));
        $old = LibraryPrompt::create(['collection' => 'motionsites', 'source_key' => '4', 'pack' => 'Hero', 'title' => 'Old', 'body' => 'b', 'poster_url' => 'https://cdn.example.com/old.gif', 'poster_path' => 'library/motionsites/old.gif']);
        $user = $this->member();

        // Too large by its size header: marked without downloading it. A copy over the cap is not served.
        $this->actingAs($user)->get(route('workflows.preview', [$huge->id, 'poster']))->assertRedirect('https://cdn.example.com/huge.gif');
        $this->actingAs($user)->get(route('workflows.preview', [$old->id, 'poster']))->assertRedirect('https://cdn.example.com/old.gif');
        $this->assertSame(LibraryPreviews::REMOTE, $huge->fresh()->poster_path);
        $this->assertSame(LibraryPreviews::REMOTE, $old->fresh()->poster_path);
        Http::assertSentCount(1);

        $this->actingAs($user)->get(route('workflows.preview', [$big->id, 'poster']))->assertRedirect('https://cdn.example.com/big.gif');
        $this->actingAs($user)->get(route('workflows.preview', [$big->id, 'poster']))->assertRedirect('https://cdn.example.com/big.gif');
        $this->actingAs($user)->get(route('workflows.preview', [$gone->id, 'poster']))->assertRedirect('https://cdn.example.com/gone.gif');
        $this->actingAs($user)->get(route('workflows.preview', [$big->id, 'video']))->assertNotFound();
        $this->assertSame(LibraryPreviews::REMOTE, $big->fresh()->poster_path);
        $this->assertSame(LibraryPreviews::REMOTE, $gone->fresh()->poster_path);
        Http::assertSentCount(5);

        // A storage failure shows the preview from its source instead of breaking the page.
        $this->mock(LibraryPreviews::class, fn ($mock) => $mock->shouldReceive('path')->andThrow(new \RuntimeException('storage down')));
        $this->actingAs($user)->get(route('workflows.preview', [$gone->id, 'poster']))->assertRedirect('https://cdn.example.com/gone.gif');
        $this->app->forgetInstance(LibraryPreviews::class);

        Livewire::actingAs($user)->withQueryParams(['workflow' => 'motionsites-hero'])->test(WorkflowIndex::class)->assertSet('category', 'web');

        $hero = app(WorkflowCatalog::class)->find('motionsites-hero');
        $this->assertNull($hero['prompts'][0]['video']);
        $this->assertSame(route('workflows.preview', [$big->id, 'poster']), $hero['prompts'][0]['poster']);
    }

    public function test_open_collections_credit_each_prompt_and_show_previews_from_the_source(): void
    {
        Http::fake();
        foreach (['ana' => 'Orbit intro', 'ben' => 'Logo reveal'] as $author => $title) {
            LibraryPrompt::create(['collection' => 'opus-videos', 'source_key' => $author, 'pack' => 'UI motion animations', 'category' => 'animation', 'title' => $title, 'body' => "Animate {$title}.", 'credit' => "Prompt by @{$author}", 'source_url' => "https://x.com/{$author}/status/1", 'poster_url' => "https://media.example.com/{$author}.webp"]);
        }
        $user = $this->member();

        $pack = app(WorkflowCatalog::class)->find('opus-videos-ui-motion-animations');
        $this->assertSame('Motion animations', $pack['category_label']);
        $this->assertStringContainsString('awesome-opus5-5-videos', $pack['credit']);
        $this->assertSame('https://github.com/yihui-dev/awesome-opus5-5-videos', $pack['source_url']);

        Livewire::actingAs($user)->withQueryParams(['workflow' => 'opus-videos-ui-motion-animations'])->test(WorkflowIndex::class)
            ->assertSee('Prompt by @ana')
            ->assertSee('Prompt by @ben')
            ->assertSee('https://x.com/ben/status/1', false)
            ->assertSee('See the collection on GitHub');

        $ana = LibraryPrompt::where('source_key', 'ana')->first();
        $this->actingAs($user)->get(route('workflows.preview', [$ana->id, 'poster']))->assertRedirect('https://media.example.com/ana.webp');
        Http::assertNothingSent();

        $download = $this->actingAs($user)->get(route('workflows.download', 'opus-videos-ui-motion-animations'))->getContent();
        $this->assertStringContainsString('_Prompt by @ana https://x.com/ana/status/1_', $download);
    }

    public function test_source_only_collections_ignore_old_copies_and_unsafe_links(): void
    {
        Storage::fake('previews');
        config(['workflows.media_disk' => 'previews']);
        Storage::disk('previews')->put('library/opus-videos/old-poster.webp', 'old copy');
        $prompt = LibraryPrompt::create(['collection' => 'opus-videos', 'source_key' => 'cara', 'pack' => 'UI motion animations', 'category' => 'animation', 'title' => 'Card flip', 'body' => 'Flip the card.', 'credit' => 'Prompt by @cara', 'source_url' => 'javascript:alert(1)', 'video_url' => 'https://media.example.com/cara.mp4', 'poster_url' => 'https://media.example.com/cara.webp', 'poster_path' => 'library/opus-videos/old-poster.webp']);
        LibraryPrompt::create(['collection' => 'opus-videos', 'source_key' => 'dev', 'pack' => 'UI motion animations', 'category' => 'animation', 'title' => 'Tab slide', 'body' => 'Slide the tabs.', 'credit' => 'Prompt by @dev', 'poster_url' => 'javascript:alert(2)']);
        $user = $this->member();

        $pack = app(WorkflowCatalog::class)->find('opus-videos-ui-motion-animations');
        $this->assertNull($pack['prompts'][0]['video']);
        $this->actingAs($user)->get(route('workflows.preview', [$prompt->id, 'poster']))->assertRedirect('https://media.example.com/cara.webp');
        $this->assertSame(LibraryPreviews::REMOTE, $prompt->fresh()->poster_path);

        LibraryPrompt::where('source_key', 'dev')->update(['credit' => 'Prompt by @cara']);
        $pack = app(WorkflowCatalog::class)->find('opus-videos-ui-motion-animations');
        $this->assertStringContainsString('awesome-opus5-5-videos', $pack['credit']);
        $this->assertSame('Prompt by @cara', $pack['prompts'][0]['credit']);
        Livewire::actingAs($user)->withQueryParams(['workflow' => 'opus-videos-ui-motion-animations'])->test(WorkflowIndex::class)
            ->assertSee('Prompt by @cara')
            ->assertDontSee('javascript:', false);
        $dev = LibraryPrompt::where('source_key', 'dev')->first();
        $this->actingAs($user)->get(route('workflows.preview', [$dev->id, 'poster']))->assertNotFound();
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Hollow Lane']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return $user;
    }
}
