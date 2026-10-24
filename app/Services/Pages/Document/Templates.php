<?php

namespace App\Services\Pages\Document;

/**
 * Page templates: a look (theme) and a section plan. A new page starts from one, filled from the
 * product brief: confirmed Knowledge, approved launch pack copy, the product page and its photos.
 * The AI then rewrites every section from the same brief; without it, sections the brief cannot fill
 * are left out instead of shipping invented text.
 */
final class Templates
{
    public const ALL = [
        'studio' => [
            'label' => 'Studio', 'description' => 'Clean and balanced. A big gallery, benefits, a comparison and FAQ.',
            'theme' => ['palette' => 'coral', 'heading_font' => 'sans', 'body_font' => 'sans', 'heading_weight' => 800, 'heading_case' => 'normal', 'radius' => 16, 'button' => 'pill', 'spacing' => 'normal', 'width' => 1120],
            'sections' => [
                ['announcement', ['background' => 'dark']],
                ['product_hero', []],
                ['benefit_strip', ['background' => 'accent']],
                ['feature', ['image_side' => 'left']],
                ['benefits', ['background' => 'surface']],
                ['feature', ['image_side' => 'right', 'points_style' => 'cards']],
                ['comparison', []],
                ['faq', ['background' => 'surface']],
                ['cta', ['background' => 'dark']],
            ],
        ],
        'editorial' => [
            'label' => 'Editorial', 'description' => 'A magazine feel: serif headings, generous space, the story first.',
            'theme' => ['palette' => 'sand', 'heading_font' => 'elegant', 'body_font' => 'sans', 'heading_weight' => 600, 'heading_case' => 'normal', 'radius' => 4, 'button' => 'square', 'spacing' => 'airy', 'width' => 1120],
            'sections' => [
                ['product_hero', []],
                ['rich_text', ['background' => 'surface']],
                ['feature', ['image_side' => 'right']],
                ['numbered', ['image_side' => 'left']],
                ['gallery', ['columns' => '3']],
                ['specs', ['background' => 'surface']],
                ['faq', ['layout' => 'stacked']],
                ['cta', ['background' => 'surface']],
            ],
        ],
        'bold' => [
            'label' => 'Bold', 'description' => 'Loud and direct: capital headings, strong colour bands, fast to scan.',
            'theme' => ['palette' => 'sunset', 'heading_font' => 'condensed', 'body_font' => 'sans', 'heading_weight' => 800, 'heading_case' => 'upper', 'radius' => 10, 'button' => 'rounded', 'spacing' => 'compact', 'width' => 1120],
            'sections' => [
                ['announcement', ['background' => 'accent']],
                ['product_hero', []],
                ['benefit_strip', ['background' => 'dark']],
                ['feature', ['image_side' => 'left']],
                ['comparison', ['background' => 'surface']],
                ['steps', []],
                ['faq', ['background' => 'surface']],
                ['cta', ['background' => 'accent']],
            ],
        ],
        'soft' => [
            'label' => 'Soft', 'description' => 'Friendly and warm: rounded type and corners, gentle tints.',
            'theme' => ['palette' => 'blush', 'heading_font' => 'rounded', 'body_font' => 'rounded', 'heading_weight' => 700, 'heading_case' => 'normal', 'radius' => 24, 'button' => 'pill', 'spacing' => 'normal', 'width' => 1120],
            'sections' => [
                ['product_hero', ['background' => 'soft']],
                ['benefits', ['style' => 'plain']],
                ['feature', ['image_side' => 'right', 'background' => 'soft']],
                ['steps', []],
                ['faq', ['background' => 'soft', 'layout' => 'stacked']],
                ['cta', ['background' => 'accent']],
            ],
        ],
        'fresh' => [
            'label' => 'Fresh', 'description' => 'Light and natural: green accents, numbered reasons, an honest comparison.',
            'theme' => ['palette' => 'forest', 'heading_font' => 'geometric', 'body_font' => 'sans', 'heading_weight' => 700, 'heading_case' => 'normal', 'radius' => 18, 'button' => 'pill', 'spacing' => 'normal', 'width' => 1120],
            'sections' => [
                ['announcement', ['background' => 'soft']],
                ['product_hero', []],
                ['benefit_strip', ['background' => 'soft']],
                ['feature', ['image_side' => 'left']],
                ['numbered', ['background' => 'surface']],
                ['comparison', []],
                ['faq', []],
                ['cta', ['background' => 'dark']],
            ],
        ],
        'tech' => [
            'label' => 'Tech', 'description' => 'Precise and wide: specs up front, a comparison and setup steps.',
            'theme' => ['palette' => 'midnight', 'heading_font' => 'geometric', 'body_font' => 'sans', 'heading_weight' => 700, 'heading_case' => 'normal', 'radius' => 12, 'button' => 'rounded', 'spacing' => 'normal', 'width' => 1280],
            'sections' => [
                ['product_hero', []],
                ['benefits', ['background' => 'surface']],
                ['feature', ['image_side' => 'right']],
                ['specs', []],
                ['comparison', ['background' => 'surface']],
                ['steps', []],
                ['faq', []],
                ['cta', ['background' => 'dark']],
            ],
        ],
    ];

    private const ICON_CYCLE = ['check', 'sparkle', 'heart', 'bolt', 'leaf', 'star', 'gem', 'smile'];

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /** A template's theme, in the brand's colours when the brand has them. */
    public static function theme(string $key, ProductBrief $brief, bool $brandColors = true): array
    {
        $template = self::ALL[$key] ?? self::ALL['studio'];
        $palette = $brandColors && ($brand = Theme::brandPalette($brief->brand['palette'] ?? []))
            ? Theme::fromPalette('brand', $brand)
            : Theme::fromPalette($template['theme']['palette']);

        return Theme::sanitize($palette + $template['theme']);
    }

    /**
     * A new document from a template. With $forAi, every planned section stays (the AI fills what the
     * brief could not); without it, sections that would have nothing true to say are left out.
     */
    public static function document(string $key, ProductBrief $brief, bool $forAi = false, bool $brandColors = true): array
    {
        $template = self::ALL[$key] ?? self::ALL['studio'];
        $used = ['angles' => 0, 'photos' => 0];
        $sections = [];
        foreach ($template['sections'] as [$type, $overrides]) {
            $filled = self::fill($type, $brief, $used);
            if ($filled === null && ! $forAi) {
                continue;
            }
            $sections[] = Document::section($type, array_replace($filled ?? self::aiStart($type, $brief), $overrides));
        }

        return Document::sanitize([
            'theme' => self::theme($key, $brief, $brandColors),
            'buy_url' => $brief->buyUrl,
            'sections' => $sections,
        ]);
    }

    /** A section added from the library: filled from the brief where it can be, else ready to type into. */
    public static function starter(string $type, ProductBrief $brief, array $document = []): array
    {
        $used = ['angles' => 0, 'photos' => 0];
        foreach ($document['sections'] ?? [] as $section) {
            if (in_array($section['type'], ['feature', 'numbered'], true)) {
                $used['angles']++;
                $used['photos']++;
            }
        }

        return Document::section($type, self::fill($type, $brief, $used) ?? self::aiStart($type, $brief));
    }

    /**
     * Settings for a section from the brief alone, or null when the brief has nothing for it.
     *
     * @param  array{angles: int, photos: int}  $used  angles and photos earlier sections took
     */
    public static function fill(string $type, ProductBrief $brief, array &$used): ?array
    {
        $name = $brief->name();
        $photos = $brief->photos();
        $angles = $brief->angles();
        $benefits = $brief->lines('benefits');
        $differentiators = $brief->lines('differentiators');
        $short = fn (array $lines, int $max) => array_values(array_filter($lines, fn (string $line) => mb_strlen($line) <= $max));

        switch ($type) {
            case 'announcement':
                $offer = collect($brief->approved['offers']['offers'] ?? [])->map(fn ($offer) => trim((string) ($offer['wording'] ?? '')))->first(fn ($wording) => $wording !== '' && mb_strlen($wording) <= 90);

                return $offer ? ['text' => $offer] : null;

            case 'product_hero':
                $bullets = $benefits ?: array_map(fn (array $angle) => $angle['title'], $angles);

                return [
                    'images' => $photos,
                    'badge' => mb_strlen((string) ($brief->brand['name'] ?? '')) <= 30 ? (string) ($brief->brand['name'] ?? '') : '',
                    'title' => $name,
                    'subtitle' => mb_substr($brief->valueProposition() ?: ($angles[0]['text'] ?? ''), 0, 160),
                    'description' => mb_substr($brief->description(), 0, 600),
                    'price' => (string) $brief->price,
                    'bullets' => array_map(fn (string $line) => ['text' => mb_substr($line, 0, 100)], array_slice($short($bullets, 100), 0, 5)),
                    'button_label' => 'Buy now',
                ];

            case 'benefit_strip':
                $items = array_slice($short($benefits ?: array_map(fn (array $angle) => $angle['title'], $angles), 32), 0, 5);

                return count($items) >= 2 ? ['items' => array_map(fn (string $text, int $index) => ['icon' => self::ICON_CYCLE[$index % count(self::ICON_CYCLE)], 'text' => $text], $items, array_keys($items))] : null;

            case 'feature':
                $angle = $angles[$used['angles']] ?? null;
                if (! $angle) {
                    return null;
                }
                $used['angles']++;
                $photo = $photos[min(count($photos) - 1, ++$used['photos'])] ?? ($photos[0] ?? null);

                return ['heading' => mb_substr($angle['title'], 0, 120), 'text' => mb_substr($angle['text'], 0, 700), 'image' => $photo, 'button_label' => ''];

            case 'benefits':
                $items = $angles
                    ? array_map(fn (array $angle) => ['title' => mb_substr($angle['title'], 0, 60), 'text' => mb_substr($angle['text'], 0, 240)], array_slice($angles, 0, 4))
                    : array_map(fn (string $line) => ['title' => mb_substr($line, 0, 60), 'text' => ''], array_slice($short($benefits, 60), 0, 4));
                if (count($items) < 2) {
                    return null;
                }

                return ['heading' => 'Why you will love '.self::shortName($name), 'items' => self::withIcons($items)];

            case 'numbered':
                $items = $differentiators
                    ? array_map(fn (string $line) => ['title' => mb_substr($line, 0, 80), 'text' => ''], array_slice($short($differentiators, 80), 0, 5))
                    : array_map(fn (array $angle) => ['title' => mb_substr($angle['title'], 0, 80), 'text' => mb_substr($angle['text'], 0, 300)], array_slice($angles, 0, 5));
                if (count($items) < 2) {
                    return null;
                }
                $photo = $photos[min(count($photos) - 1, ++$used['photos'])] ?? null;

                return ['heading' => count($items).' reasons to choose '.self::shortName($name), 'items' => $items, 'image' => $photo];

            case 'comparison':
                $rows = array_slice($short($differentiators, 60), 0, 6);
                if (count($rows) < 2) {
                    return null;
                }

                return ['heading' => 'How '.self::shortName($name).' compares', 'us_label' => self::shortName($name, 24), 'them_label' => 'Others', 'rows' => array_map(fn (string $row) => ['label' => $row, 'us' => true, 'them' => false], $rows)];

            case 'faq':
                $items = array_values(array_filter([
                    $brief->description() !== '' ? ['question' => 'What is '.self::shortName($name).'?', 'answer' => mb_substr($brief->description(), 0, 800)] : null,
                    filled($brief->facts['audience'] ?? null) ? ['question' => 'Who is it for?', 'answer' => mb_substr((string) $brief->facts['audience'], 0, 800)] : null,
                    $differentiators ? ['question' => 'What makes it different?', 'answer' => mb_substr(implode('. ', array_map(fn ($line) => rtrim($line, '.'), $differentiators)).'.', 0, 800)] : null,
                    filled($brief->facts['availability'] ?? null) ? ['question' => 'Is it available now?', 'answer' => mb_substr((string) $brief->facts['availability'], 0, 800)] : null,
                ]));

                return count($items) >= 2 ? ['items' => $items] : null;

            case 'specs':
                $rows = array_values(array_filter([
                    $brief->price ? ['label' => 'Price', 'value' => $brief->price] : null,
                    filled($brief->facts['availability'] ?? null) ? ['label' => 'Availability', 'value' => (string) $brief->facts['availability']] : null,
                    filled($brief->truth['brand'] ?? $brief->brand['name'] ?? null) ? ['label' => 'Brand', 'value' => (string) ($brief->truth['brand'] ?? $brief->brand['name'])] : null,
                    $benefits ? ['label' => 'Benefits', 'value' => implode("\n", $benefits)] : null,
                    $differentiators ? ['label' => 'What sets it apart', 'value' => implode("\n", $differentiators)] : null,
                ]));

                return $rows ? ['rows' => $rows] : null;

            case 'rich_text':
                $text = trim((string) ($brief->approved['positioning']['brand_position'] ?? ''));

                return $text !== '' ? ['heading' => mb_substr($brief->valueProposition(), 0, 140), 'text' => $text] : null;

            case 'gallery':
                return count($photos) >= 2 ? ['images' => $photos] : null;

            case 'cta':
                return ['heading' => 'Ready for '.self::shortName($name).'?', 'text' => mb_substr($brief->valueProposition(), 0, 400), 'button_label' => 'Buy now', 'show_price' => true];

            default:
                return null;
        }
    }

    /**
     * Starting values for a section the AI will write: the structure with empty text, so the editor
     * shows where text goes and the AI knows how many items to write.
     */
    private static function aiStart(string $type, ProductBrief $brief): array
    {
        $name = self::shortName($brief->name());

        return match ($type) {
            'benefit_strip' => ['items' => self::withIcons(array_fill(0, 4, ['text' => '']))],
            'benefits' => ['items' => self::withIcons(array_fill(0, 3, ['title' => '', 'text' => '']))],
            'numbered' => ['items' => array_fill(0, 4, ['title' => '', 'text' => ''])],
            'steps' => ['items' => array_fill(0, 3, ['title' => '', 'text' => ''])],
            'comparison' => ['us_label' => self::shortName($name, 24), 'them_label' => 'Others', 'rows' => array_fill(0, 4, ['label' => '', 'us' => true, 'them' => false])],
            'faq' => ['items' => array_fill(0, 4, ['question' => '', 'answer' => ''])],
            'feature' => ['points' => [], 'button_label' => ''],
            default => [],
        };
    }

    private static function withIcons(array $items): array
    {
        return array_map(fn (array $item, int $index) => ['icon' => self::ICON_CYCLE[$index % count(self::ICON_CYCLE)]] + $item, $items, array_keys($items));
    }

    /** The product name without a long descriptor: "Northline Kindle Stand | Oak" becomes "Northline Kindle Stand". */
    public static function shortName(string $name, int $max = 40): string
    {
        $short = trim((string) preg_split('/\s+[|\-–—:]\s+|\s*\(/u', $name)[0]);
        $short = $short !== '' ? $short : $name;

        return mb_strlen($short) <= $max ? $short : rtrim(mb_substr($short, 0, $max - 1)).'…';
    }

    /** Cards for the template gallery. */
    public static function choices(): array
    {
        return collect(self::ALL)->map(fn (array $template, string $key) => [
            'key' => $key, 'label' => $template['label'], 'description' => $template['description'],
            'sections' => array_map(fn (array $entry) => Sections::get($entry[0])['label'] ?? $entry[0], $template['sections']),
        ])->values()->all();
    }
}
