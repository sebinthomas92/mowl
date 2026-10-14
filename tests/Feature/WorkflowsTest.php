<?php

namespace Tests\Feature;

use App\Livewire\WorkflowIndex;
use App\Models\LibraryPrompt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkflowCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
        $this->assertSame('Motion & video', $kinetic['category_label']);
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
        File::put($file, json_encode([['id' => 'a1', 'title' => 'Aurora hero v2', 'category' => 'Hero Section', 'body' => 'New body.']]));
        $this->artisan('workflows:import-prompts', ['file' => $file])->assertSuccessful();
        $this->assertSame(1, LibraryPrompt::count());
        $this->assertSame('free', LibraryPrompt::first()->tier);
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

    private function member(): User
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Hollow Lane']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return $user;
    }
}
