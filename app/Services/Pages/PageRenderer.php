<?php

namespace App\Services\Pages;

use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\DocumentRenderer;

/**
 * Turns a page snapshot into the HTML a store receives. Page documents (format 2) render through
 * {@see DocumentRenderer}, the same renderer as the editor's canvas. Older snapshots, made of sections
 * bound to content items, render here as they always did, so rolling back to them shows what was live.
 *
 * Output has no line breaks between tags: WordPress turns blank lines in page content into paragraphs.
 * Only tags and inline styles that WordPress keeps for editors are used.
 */
class PageRenderer
{
    public function __construct(private DocumentRenderer $documents) {}

    /**
     * @param  callable(array): (string|array|null)  $image  resolves a snapshot image reference to a URL, or to
     *                                                       {url, id, width, height} once it is in the store
     */
    public function page(array $snapshot, int $version, callable $image): string
    {
        if (($snapshot['format'] ?? 1) >= Document::FORMAT) {
            return $this->documents->render((array) ($snapshot['document'] ?? []), ['page_id' => (int) $snapshot['page_id'], 'version' => $version], $image);
        }
        $imageUrl = function (array $ref) use ($image): ?string {
            $resolved = $image($ref);

            return is_array($resolved) ? ($resolved['url'] ?? null) : $resolved;
        };
        $sections = implode('', array_map(fn (array $section) => $this->section($section, $snapshot, $imageUrl), $snapshot['sections'] ?? []));

        return '<div class="mowl-page" data-mowl-page="'.(int) $snapshot['page_id'].'" data-mowl-version="'.$version.'" style="max-width:960px;margin:0 auto">'.$sections.'</div>';
    }

    /** The marker a verified page must carry. */
    public function serves(string $html, int $pageId, int $version): bool
    {
        if (! preg_match_all('/<div\b[^>]*\bdata-mowl-page=["\']?'.$pageId.'["\'\s>][^>]*>/i', $html, $matches)) {
            return false;
        }
        foreach ($matches[0] as $tag) {
            if (preg_match('/\bdata-mowl-version=["\']?'.$version.'["\'\s>]/i', $tag)) {
                return true;
            }
        }

        return false;
    }

    public function section(array $section, array $snapshot, callable $imageUrl): string
    {
        $inner = match ($section['kind']) {
            'hero' => $this->hero($section, $snapshot, $imageUrl),
            'specs' => $this->specs($section['facts'] ?? []),
            'cta' => $this->cta($section['settings'] ?? []),
            default => $section['item'] ? $this->item($section['item']) : '',
        };
        if ($inner === '') {
            return '';
        }

        return '<section class="mowl-section mowl-'.$this->e($section['kind']).'" style="margin:0 0 40px">'.$inner.'</section>';
    }

    private function hero(array $section, array $snapshot, callable $imageUrl): string
    {
        $html = '';
        $image = $section['image'] ?? null;
        $url = $image ? $imageUrl($image) : null;
        if ($url) {
            $html .= '<img src="'.$this->e($url).'" alt="'.$this->e($image['alt'] ?? '').'" style="width:100%;height:auto;border-radius:16px;display:block;margin:0 0 24px">';
        }
        $html .= '<h1 style="margin:0 0 12px">'.$this->e($snapshot['headline'] ?? '').'</h1>';
        $body = $section['item']['body'] ?? [];
        $lede = trim((string) ($body['supporting_text'] ?? '')) ?: trim((string) ($section['summary'] ?? ''));
        if ($lede !== '') {
            $html .= '<p style="font-size:1.15em;margin:0">'.$this->e($lede).'</p>';
        }

        return $html;
    }

    private function specs(array $facts): string
    {
        if ($facts === []) {
            return '';
        }
        $rows = '';
        foreach ($facts as $fact) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $fact['value']))));
            $value = count($lines) > 1
                ? '<ul style="margin:0;padding-left:1.2em">'.implode('', array_map(fn ($line) => '<li>'.$this->e($line).'</li>', $lines)).'</ul>'
                : $this->e($lines[0] ?? '');
            $rows .= '<tr><th scope="row" style="width:35%;text-align:left;padding:10px 16px 10px 0;vertical-align:top;font-weight:600">'.$this->e($fact['label']).'</th><td style="padding:10px 0">'.$value.'</td></tr>';
        }

        return '<h2 style="margin:0 0 12px">Product details</h2><table style="width:100%;border-collapse:collapse"><tbody>'.$rows.'</tbody></table>';
    }

    private function cta(array $settings): string
    {
        $url = (string) ($settings['url'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return '';
        }
        $text = trim((string) ($settings['text'] ?? ''));

        return ($text !== '' ? '<p style="margin:0 0 16px">'.$this->e($text).'</p>' : '')
            .'<p style="margin:0"><a class="button mowl-button" href="'.$this->e($url).'" style="display:inline-block;padding:14px 28px;border-radius:999px;background:#D9381E;color:#FFFFFF;text-decoration:none;font-weight:600">'
            .$this->e(trim((string) ($settings['label'] ?? '')) ?: 'Shop now').'</a></p>';
    }

    /** A content item body, by the launch pack section it came from. */
    private function item(array $item): string
    {
        $body = $item['body'] ?? [];
        $section = (string) ($item['section'] ?? '');

        if ($section === 'positioning') {
            $html = $this->heading($body['value_proposition'] ?? '').$this->paragraph($body['brand_position'] ?? '');
            $audiences = array_filter(array_map(fn ($audience) => is_array($audience) ? trim(($audience['name'] ?? '').(filled($audience['need'] ?? null) ? ': '.$audience['need'] : '')) : '', $body['audience_priorities'] ?? []));

            return $html.($audiences ? '<p style="margin:16px 0 8px;font-weight:600">Made for</p>'.$this->list($audiences) : '');
        }
        if ($section === 'ranked_angles') {
            $html = '';
            foreach ($body['angles'] ?? [] as $angle) {
                $html .= '<h3 style="margin:0 0 6px">'.$this->e($angle['title'] ?? '').'</h3>'.$this->paragraph($angle['core_idea'] ?? '', '0 0 20px');
            }

            return $html;
        }
        if ($section === 'offers') {
            $html = '';
            foreach ($body['offers'] ?? [] as $offer) {
                $html .= '<p style="font-size:1.3em;font-weight:600;margin:0 0 8px">'.$this->e($offer['wording'] ?? '').'</p>'.$this->paragraph($offer['audience_or_situation'] ?? '', '0 0 20px');
            }

            return $html;
        }
        if ($section === 'marketing_hub') {
            $messaging = $body['key_messaging'] ?? [];

            return $this->heading($messaging['value_proposition'] ?? '').$this->list(array_filter(array_map('strval', $messaging['proof_points'] ?? [])));
        }
        if (isset($body['headline'])) {
            return $this->heading($body['headline']).$this->paragraph($body['supporting_text'] ?? '');
        }

        return $this->list(array_slice(array_values(array_filter($this->strings($body))), 0, 8));
    }

    private function heading(mixed $text): string
    {
        $text = trim((string) (is_scalar($text) ? $text : ''));

        return $text === '' ? '' : '<h2 style="margin:0 0 12px">'.$this->e($text).'</h2>';
    }

    private function paragraph(mixed $text, string $margin = '0 0 12px'): string
    {
        $text = trim((string) (is_scalar($text) ? $text : ''));

        return $text === '' ? '' : '<p style="margin:'.$margin.'">'.$this->e($text).'</p>';
    }

    private function list(array $lines): string
    {
        return $lines === [] ? '' : '<ul style="margin:0 0 12px;padding-left:1.2em">'.implode('', array_map(fn ($line) => '<li>'.$this->e($line).'</li>', $lines)).'</ul>';
    }

    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [trim($value)];
        }

        return is_array($value) ? array_merge(...array_map(fn ($entry) => $this->strings($entry), array_values($value)) ?: [[]]) : [];
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
