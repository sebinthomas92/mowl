<?php

/*
 * Workflows catalog (design: "Workflows" page).
 *
 * Workflows that run inside MarketingOwl are listed here and link to the
 * screen that runs them. Prompt packs and agent skills live as Markdown files
 * in `library_path` (one file per workflow, frontmatter + "## " prompts) so
 * new prompts are added as data, never in a view.
 */

return [

    'library_path' => resource_path('workflows'),

    // Public home of the prompt packs and skills (Help and Workflows link here).
    'source_url' => 'https://prompt-showcase.pages.dev/',
    'source_name' => 'Marketing Owl prompt showcase',

    // Where copies of library preview videos and posters are kept (fetched on first view).
    'media_disk' => env('WORKFLOW_MEDIA_DISK', env('BANNER_STORAGE_DISK', env('CAMPAIGN_MEDIA_DISK', 'local'))),

    // Licensed or openly licensed collections stored in library_prompts, keyed by `collection`.
    // credit/source_url describe the collection; each prompt can carry its own credit and source.
    // mirror_previews: false shows previews straight from their source instead of copying them.
    'collections' => [
        'motionsites' => [
            'source_url' => 'https://prompt-showcase.pages.dev/',
            'source_label' => 'See it on the prompt showcase',
        ],
        'opus-videos' => [
            'credit' => 'From yihui-dev/awesome-opus5-5-videos (MIT licence). Each prompt credits its creator.',
            'source_url' => 'https://github.com/yihui-dev/awesome-opus5-5-videos',
            'source_label' => 'See the collection on GitHub',
            'mirror_previews' => false,
        ],
    ],

    'categories' => [
        // Motion videos are rendered video pieces; motion animations run live in a website or UI.
        'motion' => 'Motion videos',
        'animation' => 'Motion animations',
        'web' => 'Website design',
        'image' => 'Images',
        'copy' => 'Copy & pages',
        'research' => 'Research & reports',
    ],

    // run: runs here · download: copy or download for your own agent · coming: listed, not released.
    'availability' => [
        'run' => ['tone' => 'ok', 'label' => 'Run here'],
        'download' => ['tone' => '', 'label' => 'Copy or download'],
        'coming' => ['tone' => 'warn', 'label' => 'Not released yet'],
    ],

    'hosted' => [
        [
            'slug' => 'launch-pack',
            'name' => 'Launch pack',
            'category' => 'copy',
            'availability' => 'run',
            'scope' => 'Product',
            'needs' => 'A product with confirmed facts and a brand voice',
            'gives' => 'Positioning, campaign routes, shot lists, captions and banners as reviewable content items',
            'action' => ['route' => 'campaign-packs.create', 'label' => 'Start a launch pack'],
            'gradient' => 0,
        ],
        [
            'slug' => 'landing-page',
            'name' => 'Landing page',
            'category' => 'copy',
            'availability' => 'run',
            'scope' => 'Product',
            'needs' => 'Approved content on a product',
            'gives' => 'A page draft bound to approved content, published to your store and verified',
            'action' => ['route' => 'products.index', 'label' => 'Choose a product, then open Pages'],
            'gradient' => 4,
        ],
        [
            'slug' => 'product-reveal-video',
            'name' => 'Product reveal video',
            'category' => 'motion',
            'availability' => 'coming',
            'scope' => 'Product',
            'needs' => '3 or more product images, the offer, the brand palette',
            'gives' => 'A 15 to 30 second clip in 9:16 and 16:9',
            'gradient' => 1,
        ],
        [
            'slug' => 'monthly-report',
            'name' => 'Monthly report',
            'category' => 'research',
            'availability' => 'coming',
            'scope' => 'Workspace',
            'needs' => 'At least one results source',
            'gives' => 'A client-ready monthly report',
            'gradient' => 5,
        ],
    ],

];
