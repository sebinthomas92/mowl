<?php

namespace App\Services\Pages\Document;

/**
 * The section library of the page builder. Each type declares its fields; the editor builds its
 * settings forms from them, the sanitizer enforces them, the AI writes only the fields that carry an
 * "ai" brief, and the renderer reads them.
 *
 * Field types: text, textarea, url, image, images, select, toggle, icon, rating, list (repeatable items
 * with their own fields). Every string is plain text; the renderer escapes it.
 */
final class Sections
{
    /** Library groups, in the order the "Add section" panel shows them. */
    public const GROUPS = [
        'hero' => 'Top of page',
        'story' => 'Story and benefits',
        'proof' => 'Proof',
        'offer' => 'Offer and action',
        'info' => 'Information',
        'media' => 'Images and video',
        'layout' => 'Layout',
    ];

    /** Section backgrounds, resolved from the theme. */
    public const BACKGROUNDS = ['default' => 'Page', 'surface' => 'Light', 'soft' => 'Tint', 'accent' => 'Accent', 'dark' => 'Dark'];

    /**
     * Icons a section can show, by key. Pages render them as emoji, which every store theme and email
     * client shows without loading anything.
     */
    public const ICONS = [
        'check' => '✔️', 'sparkle' => '✨', 'star' => '⭐', 'heart' => '❤️', 'bolt' => '⚡', 'leaf' => '🌿',
        'plant' => '🌱', 'drop' => '💧', 'sun' => '☀️', 'moon' => '🌙', 'fire' => '🔥', 'gem' => '💎',
        'gift' => '🎁', 'truck' => '🚚', 'box' => '📦', 'return' => '↩️', 'lock' => '🔒', 'shield' => '🛡️',
        'clock' => '⏱️', 'calendar' => '📅', 'award' => '🏆', 'medal' => '🥇', 'thumb' => '👍', 'smile' => '😊',
        'hands' => '🙌', 'muscle' => '💪', 'brain' => '🧠', 'eye' => '👀', 'target' => '🎯', 'puzzle' => '🧩',
        'tools' => '🛠️', 'ruler' => '📏', 'feather' => '🪶', 'magic' => '🪄', 'home' => '🏠', 'travel' => '✈️',
        'book' => '📖', 'phone' => '📱', 'battery' => '🔋', 'recycle' => '♻️', 'globe' => '🌍', 'water' => '🌊',
        'cup' => '☕', 'music' => '🎵', 'camera' => '📷', 'paw' => '🐾', 'baby' => '👶', 'chat' => '💬',
        'money' => '💰', 'tag' => '🏷️', 'chart' => '📈', 'bulb' => '💡', 'rocket' => '🚀', 'party' => '🎉',
    ];

    private static ?array $all = null;

    /** @return array<string, array> type => definition */
    public static function all(): array
    {
        return self::$all ??= self::define();
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** The definition of one field of a type, or of a list's item field ("items.title"). */
    public static function field(string $type, string $path): ?array
    {
        $fields = self::get($type)['fields'] ?? [];
        $parts = explode('.', $path);
        foreach ($parts as $index => $key) {
            $field = collect($fields)->firstWhere('key', $key);
            if (! $field) {
                return null;
            }
            if ($index === count($parts) - 1) {
                return $field;
            }
            $fields = $field['fields'] ?? [];
        }

        return null;
    }

    /** Default settings of a type: every field's default, lists with their default items. */
    public static function defaults(string $type): array
    {
        $settings = [];
        foreach (self::get($type)['fields'] ?? [] as $field) {
            $settings[$field['key']] = self::fieldDefault($field);
        }

        return $settings;
    }

    public static function fieldDefault(array $field): mixed
    {
        return match ($field['type']) {
            'list' => array_map(fn (array $item) => $item, $field['default'] ?? []),
            'images' => [],
            'image' => null,
            'toggle' => (bool) ($field['default'] ?? false),
            'rating' => (int) ($field['default'] ?? 5),
            'select' => $field['default'] ?? array_key_first($field['options']),
            default => (string) ($field['default'] ?? ''),
        };
    }

    private static function define(): array
    {
        $background = fn (string $default = 'default') => ['key' => 'background', 'type' => 'select', 'label' => 'Background', 'options' => self::BACKGROUNDS, 'default' => $default];
        $button = [
            ['key' => 'button_label', 'type' => 'text', 'label' => 'Button label', 'max' => 40, 'placeholder' => 'Buy now',
                'ai' => 'Short action label for the buy button, 1 to 4 words. No prices, discounts or urgency unless confirmed.'],
            ['key' => 'button_url', 'type' => 'url', 'label' => 'Button link', 'help' => 'Leave empty to use the page\'s buy link.'],
        ];
        $iconField = ['key' => 'icon', 'type' => 'icon', 'label' => 'Icon', 'default' => 'check',
            'ai' => 'Icon key that fits the item.'];

        return [
            'announcement' => [
                'label' => 'Announcement bar', 'group' => 'hero', 'icon' => 'ti-speakerphone',
                'description' => 'A thin strip above the page for one short message.',
                'fields' => [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Message', 'max' => 120, 'required' => true, 'placeholder' => 'One short message',
                        'ai' => 'One short store message (under 70 characters) using only confirmed facts, e.g. a confirmed offer or delivery promise. Use an empty string when nothing is confirmed.'],
                    ['key' => 'link', 'type' => 'url', 'label' => 'Link', 'help' => 'Optional.'],
                    $background('dark'),
                ],
            ],

            'product_hero' => [
                'label' => 'Product hero', 'group' => 'hero', 'icon' => 'ti-layout-sidebar-right',
                'description' => 'Gallery, title, price, benefit bullets and the buy button.',
                'fields' => [
                    ['key' => 'images', 'type' => 'images', 'label' => 'Images', 'max' => 6, 'help' => 'The first image is the large one.'],
                    ['key' => 'image_side', 'type' => 'select', 'label' => 'Gallery side', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
                    ['key' => 'badge', 'type' => 'text', 'label' => 'Badge', 'max' => 40, 'placeholder' => 'e.g. New',
                        'ai' => 'Optional 1 to 3 word label above the title, such as the product category or what it is for. No rankings, awards or "best seller" claims. Empty string if nothing fits.'],
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 120, 'required' => true, 'placeholder' => 'Product name',
                        'ai' => 'The product name, optionally with a 2 to 5 word descriptor. Under 60 characters.'],
                    ['key' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle', 'max' => 160, 'placeholder' => 'What it does for the buyer',
                        'ai' => 'One benefit-led line that says what the product does for the buyer. Under 90 characters.'],
                    ['key' => 'show_rating', 'type' => 'toggle', 'label' => 'Show rating from the Reviews section', 'default' => true],
                    ['key' => 'description', 'type' => 'textarea', 'label' => 'Description', 'max' => 600, 'placeholder' => 'Two or three sentences about the product',
                        'ai' => 'Two or three short sentences describing the product and who it is for, from the source facts only. Under 320 characters.'],
                    ['key' => 'price', 'type' => 'text', 'label' => 'Price', 'max' => 40, 'placeholder' => 'e.g. ₹699', 'help' => 'From confirmed Knowledge. Keep it the same as the store.'],
                    ['key' => 'compare_price', 'type' => 'text', 'label' => 'Compare-at price', 'max' => 40, 'help' => 'Optional. Only a real former price; the saving is worked out for you.'],
                    ['key' => 'bullets', 'type' => 'list', 'label' => 'Benefit bullets', 'item_label' => 'Bullet', 'min' => 0, 'max' => 6, 'fields' => [
                        ['key' => 'text', 'type' => 'text', 'label' => 'Text', 'max' => 100, 'required' => true,
                            'ai' => 'One concrete benefit or feature in 3 to 8 words, supported by the sources.'],
                    ], 'ai' => '3 to 5 bullets.'],
                    ...$button,
                    ['key' => 'note', 'type' => 'text', 'label' => 'Note under the button', 'max' => 140, 'placeholder' => 'e.g. Ships in 2 days',
                        'ai' => 'Optional reassurance line under the button using only confirmed facts (payment, delivery or returns). Empty string when none are confirmed.'],
                    ['key' => 'details', 'type' => 'list', 'label' => 'Expandable details', 'item_label' => 'Detail', 'min' => 0, 'max' => 5, 'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 60, 'required' => true, 'ai' => 'Short tab title such as "Product details" or "What is in the box".'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 800, 'required' => true, 'ai' => 'The detail text, from source facts only.'],
                    ], 'ai' => '1 to 3 items. Never invent shipping, returns or warranty terms.'],
                    $background(),
                ],
            ],

            'benefit_strip' => [
                'label' => 'Benefit strip', 'group' => 'story', 'icon' => 'ti-layout-navbar',
                'description' => 'A coloured band of short benefits with icons.',
                'fields' => [
                    ['key' => 'items', 'type' => 'list', 'label' => 'Benefits', 'item_label' => 'Benefit', 'min' => 2, 'max' => 6, 'fields' => [
                        $iconField,
                        ['key' => 'text', 'type' => 'text', 'label' => 'Text', 'max' => 40, 'required' => true, 'ai' => 'Benefit in 1 to 3 words.'],
                    ], 'ai' => '4 or 5 items.'],
                    $background('accent'),
                ],
            ],

            'feature' => [
                'label' => 'Image and text', 'group' => 'story', 'icon' => 'ti-layout-columns',
                'description' => 'An image beside a heading, text and points.',
                'fields' => [
                    ['key' => 'image', 'type' => 'image', 'label' => 'Image'],
                    ['key' => 'image_side', 'type' => 'select', 'label' => 'Image side', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow', 'max' => 40, 'ai' => 'Optional 1 to 3 word label above the heading.'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Heading',
                        'ai' => 'Benefit-led heading, under 60 characters.'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 700, 'placeholder' => 'A short paragraph',
                        'ai' => 'One or two short sentences that expand the heading using source facts. Under 260 characters.'],
                    ['key' => 'points', 'type' => 'list', 'label' => 'Points', 'item_label' => 'Point', 'min' => 0, 'max' => 5, 'fields' => [
                        $iconField,
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 60, 'required' => true, 'ai' => 'Point in 2 to 6 words.'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 240, 'ai' => 'Optional one short sentence.'],
                    ], 'ai' => '0 to 3 points.'],
                    ['key' => 'points_style', 'type' => 'select', 'label' => 'Points style', 'options' => ['list' => 'List', 'cards' => 'Cards'], 'default' => 'list'],
                    ...array_map(fn (array $field) => $field + ($field['key'] === 'button_label' ? ['default' => ''] : []), $button),
                    $background(),
                ],
            ],

            'benefits' => [
                'label' => 'Benefits grid', 'group' => 'story', 'icon' => 'ti-layout-grid',
                'description' => 'Three or four benefits with icons, side by side.',
                'fields' => [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow', 'max' => 40, 'ai' => 'Optional 1 to 3 word label.'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Why you will love it',
                        'ai' => 'Section heading, under 50 characters.'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300, 'ai' => 'Optional one sentence under the heading.'],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Benefits', 'item_label' => 'Benefit', 'min' => 2, 'max' => 8, 'fields' => [
                        $iconField,
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 60, 'required' => true, 'ai' => 'Benefit in 2 to 5 words.'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 240, 'ai' => 'One sentence, under 120 characters.'],
                    ], 'ai' => '3 or 4 items.'],
                    ['key' => 'style', 'type' => 'select', 'label' => 'Style', 'options' => ['cards' => 'Cards', 'plain' => 'Plain'], 'default' => 'cards'],
                    $background(),
                ],
            ],

            'numbered' => [
                'label' => 'Numbered reasons', 'group' => 'story', 'icon' => 'ti-list-numbers',
                'description' => 'A numbered list of reasons, with an optional image.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Reasons to choose it',
                        'ai' => 'Heading such as "4 reasons to choose X", under 60 characters. The number must match the items.'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300, 'ai' => 'Optional one sentence.'],
                    ['key' => 'image', 'type' => 'image', 'label' => 'Image'],
                    ['key' => 'image_side', 'type' => 'select', 'label' => 'Image side', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Reasons', 'item_label' => 'Reason', 'min' => 2, 'max' => 7, 'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 80, 'required' => true, 'ai' => 'Reason in 2 to 6 words.'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300, 'ai' => 'One or two short sentences.'],
                    ], 'ai' => '3 to 5 items.'],
                    $background(),
                ],
            ],

            'steps' => [
                'label' => 'How it works', 'group' => 'story', 'icon' => 'ti-route',
                'description' => 'Numbered steps from opening the box to everyday use.',
                'fields' => [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow', 'max' => 40, 'ai' => 'Optional 1 to 3 word label.'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'How it works',
                        'ai' => 'Heading, under 50 characters.'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300, 'ai' => 'Optional one sentence.'],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Steps', 'item_label' => 'Step', 'min' => 2, 'max' => 5, 'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 60, 'required' => true, 'ai' => 'Step in 2 to 5 words.'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 240, 'ai' => 'One sentence describing how to use the product, from the sources.'],
                    ], 'ai' => '3 steps.'],
                    $background(),
                ],
            ],

            'comparison' => [
                'label' => 'Comparison', 'group' => 'proof', 'icon' => 'ti-table',
                'description' => 'Your product against the usual alternative, row by row.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Why it stands out',
                        'ai' => 'Heading, under 50 characters.'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 400, 'ai' => 'Optional one or two sentences.'],
                    ['key' => 'us_label', 'type' => 'text', 'label' => 'Your column', 'max' => 40, 'required' => true, 'ai' => 'Short product name, 1 to 3 words.'],
                    ['key' => 'them_label', 'type' => 'text', 'label' => 'Other column', 'max' => 40, 'required' => true, 'default' => 'Others',
                        'ai' => 'The generic alternative, such as "Others" or "Ordinary stands". Never a competitor brand name.'],
                    ['key' => 'rows', 'type' => 'list', 'label' => 'Rows', 'item_label' => 'Row', 'min' => 2, 'max' => 8, 'fields' => [
                        ['key' => 'label', 'type' => 'text', 'label' => 'Feature', 'max' => 60, 'required' => true, 'ai' => 'A feature the product has according to the sources, 1 to 4 words.'],
                        ['key' => 'us', 'type' => 'toggle', 'label' => 'Yours has it', 'default' => true],
                        ['key' => 'them', 'type' => 'toggle', 'label' => 'Others have it', 'default' => false],
                    ], 'ai' => '4 or 5 rows.'],
                    ...array_map(fn (array $field) => $field + ($field['key'] === 'button_label' ? ['default' => ''] : []), $button),
                    $background(),
                ],
            ],

            'reviews' => [
                'label' => 'Customer reviews', 'group' => 'proof', 'icon' => 'ti-message-star',
                'description' => 'Real reviews from your customers. Never write them yourself.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'What customers say', 'required' => true],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Reviews', 'item_label' => 'Review', 'min' => 0, 'max' => 12, 'fields' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'max' => 60, 'required' => true],
                        ['key' => 'rating', 'type' => 'rating', 'label' => 'Rating', 'default' => 5],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Review', 'max' => 600, 'required' => true],
                        ['key' => 'detail', 'type' => 'text', 'label' => 'Detail', 'max' => 60, 'help' => 'e.g. Verified buyer, Pune'],
                        ['key' => 'image', 'type' => 'image', 'label' => 'Photo'],
                    ]],
                    $background('surface'),
                ],
            ],

            'stats' => [
                'label' => 'Results in numbers', 'group' => 'proof', 'icon' => 'ti-chart-donut',
                'description' => 'Two to four numbers you can back up, with where they come from.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'The numbers'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Numbers', 'item_label' => 'Number', 'min' => 1, 'max' => 4, 'fields' => [
                        ['key' => 'value', 'type' => 'text', 'label' => 'Number', 'max' => 12, 'required' => true, 'placeholder' => 'e.g. 94%'],
                        ['key' => 'label', 'type' => 'text', 'label' => 'What it measures', 'max' => 120, 'required' => true],
                    ]],
                    ['key' => 'source', 'type' => 'text', 'label' => 'Source', 'max' => 200, 'help' => 'Where the numbers come from, e.g. "Survey of 120 customers, 2026".'],
                    $background('soft'),
                ],
            ],

            'logos' => [
                'label' => 'Logo strip', 'group' => 'proof', 'icon' => 'ti-badges',
                'description' => 'Logos of press, partners or certifications you really have.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 60, 'default' => 'As featured in'],
                    ['key' => 'images', 'type' => 'images', 'label' => 'Logos', 'max' => 8],
                    $background(),
                ],
            ],

            'guarantee' => [
                'label' => 'Guarantee', 'group' => 'offer', 'icon' => 'ti-shield-check',
                'description' => 'Your returns or satisfaction promise, when the store really offers one.',
                'fields' => [
                    ['key' => 'icon', 'type' => 'icon', 'label' => 'Icon', 'default' => 'shield'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Our promise',
                        'ai' => 'Heading for the store\'s confirmed returns or satisfaction policy. Empty string when the sources confirm no such policy.'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 500,
                        'ai' => 'The confirmed policy in plain words. Empty string when none is confirmed.'],
                    ...$button,
                    $background('accent'),
                ],
            ],

            'cta' => [
                'label' => 'Call to action', 'group' => 'offer', 'icon' => 'ti-click',
                'description' => 'A closing banner with the price and the buy button.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'placeholder' => 'Ready to try it?',
                        'ai' => 'Closing heading that invites the purchase, under 50 characters. No fake urgency.'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 400, 'ai' => 'Optional one sentence.'],
                    ['key' => 'show_price', 'type' => 'toggle', 'label' => 'Show the price', 'default' => true],
                    ...$button,
                    ['key' => 'image', 'type' => 'image', 'label' => 'Image', 'help' => 'Optional, shown beside the text.'],
                    $background('dark'),
                ],
            ],

            'faq' => [
                'label' => 'FAQ', 'group' => 'info', 'icon' => 'ti-help-circle',
                'description' => 'Questions buyers ask, with answers that open on click.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'default' => 'Frequently asked questions',
                        'ai' => 'Heading, under 40 characters.'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading', 'max' => 300, 'ai' => 'Optional one sentence.'],
                    ['key' => 'items', 'type' => 'list', 'label' => 'Questions', 'item_label' => 'Question', 'min' => 1, 'max' => 10, 'fields' => [
                        ['key' => 'question', 'type' => 'text', 'label' => 'Question', 'max' => 160, 'required' => true, 'ai' => 'A question a buyer would ask.'],
                        ['key' => 'answer', 'type' => 'textarea', 'label' => 'Answer', 'max' => 800, 'required' => true,
                            'ai' => 'The answer from source facts only, one to three sentences. Never invent shipping, returns, warranty or safety terms; ask questions the sources can answer.'],
                    ], 'ai' => '4 to 6 questions.'],
                    ['key' => 'contact', 'type' => 'text', 'label' => 'Contact line', 'max' => 200, 'help' => 'Optional, e.g. how to reach you with other questions.'],
                    ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['split' => 'Heading beside', 'stacked' => 'Heading above'], 'default' => 'split'],
                    $background(),
                ],
            ],

            'specs' => [
                'label' => 'Product details', 'group' => 'info', 'icon' => 'ti-list-details',
                'description' => 'A table of confirmed product facts.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Product details', 'required' => true],
                    ['key' => 'rows', 'type' => 'list', 'label' => 'Rows', 'item_label' => 'Row', 'min' => 1, 'max' => 14, 'fields' => [
                        ['key' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 60, 'required' => true],
                        ['key' => 'value', 'type' => 'textarea', 'label' => 'Value', 'max' => 400, 'required' => true],
                    ]],
                    $background('surface'),
                ],
            ],

            'rich_text' => [
                'label' => 'Text', 'group' => 'info', 'icon' => 'ti-align-left',
                'description' => 'A heading and paragraphs, for your story or anything else.',
                'fields' => [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow', 'max' => 40, 'ai' => 'Optional 1 to 3 word label.'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 140, 'placeholder' => 'Heading', 'ai' => 'Heading, under 60 characters.'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 3000, 'placeholder' => 'Write a few paragraphs. Leave a blank line between them.',
                        'ai' => 'Two short paragraphs separated by a blank line, from the sources.'],
                    ['key' => 'align', 'type' => 'select', 'label' => 'Alignment', 'options' => ['center' => 'Centre', 'left' => 'Left'], 'default' => 'center'],
                    $background(),
                ],
            ],

            'image' => [
                'label' => 'Image', 'group' => 'media', 'icon' => 'ti-photo',
                'description' => 'One large image with an optional caption.',
                'fields' => [
                    ['key' => 'image', 'type' => 'image', 'label' => 'Image', 'required' => true],
                    ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 200],
                    ['key' => 'width', 'type' => 'select', 'label' => 'Width', 'options' => ['contained' => 'Contained', 'wide' => 'Wide'], 'default' => 'contained'],
                    $background(),
                ],
            ],

            'gallery' => [
                'label' => 'Image gallery', 'group' => 'media', 'icon' => 'ti-layout-board',
                'description' => 'A grid of product or lifestyle images.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'ai' => 'Optional heading, under 40 characters.'],
                    ['key' => 'images', 'type' => 'images', 'label' => 'Images', 'max' => 12],
                    ['key' => 'columns', 'type' => 'select', 'label' => 'Columns', 'options' => ['2' => '2', '3' => '3', '4' => '4'], 'default' => '3'],
                    $background(),
                ],
            ],

            'video' => [
                'label' => 'Video', 'group' => 'media', 'icon' => 'ti-player-play',
                'description' => 'A YouTube or Vimeo video, opened from a cover image.',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120],
                    ['key' => 'url', 'type' => 'url', 'label' => 'Video link', 'required' => true, 'help' => 'A YouTube or Vimeo address.'],
                    ['key' => 'image', 'type' => 'image', 'label' => 'Cover image', 'help' => 'YouTube videos use their own cover when empty.'],
                    ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 200],
                    $background(),
                ],
            ],

            'divider' => [
                'label' => 'Spacer', 'group' => 'layout', 'icon' => 'ti-separator-horizontal',
                'description' => 'Empty space, with an optional line.',
                'fields' => [
                    ['key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'], 'default' => 'medium'],
                    ['key' => 'line', 'type' => 'toggle', 'label' => 'Show a line', 'default' => false],
                    $background(),
                ],
            ],
        ];
    }
}
