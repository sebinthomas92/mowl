<?php

namespace App\Services\Pages\Document;

use App\Services\CampaignPackSafetyValidator;

/**
 * Turns a page document into the HTML a store receives, and the editor's canvas into the same HTML
 * with editing hooks. One renderer for both, so the canvas is what goes live.
 *
 * Store pages are WordPress post content, so the output follows what WordPress keeps:
 *  - inline styles only (no <style>, <script>, SVG or iframes) and only CSS properties and functions
 *    WordPress allows in style attributes (no rgba(); colours with transparency are 8-digit hex);
 *  - no line breaks between tags, and one Custom HTML block, so WordPress adds no paragraphs;
 *  - square brackets escaped, so text never runs a shortcode.
 * Layouts use flex and grid with wrapping and clamp() sizes, so they adapt to phones without media
 * queries. Where a store strips "display" (WordPress before 7.0, for users without unfiltered HTML),
 * sections fall back to a readable single column.
 */
class DocumentRenderer
{
    private const STAR = '#f5a524';

    /**
     * @param  array{page_id?: int, version?: int, preview?: bool}  $context
     * @param  callable(array): (string|array{url: string, id?: string}|null)  $image  resolves an image reference
     */
    public function render(array $document, array $context, callable $image): string
    {
        $preview = (bool) ($context['preview'] ?? false);
        $tokens = Theme::tokens($document['theme'] ?? []);
        $html = '';
        $previous = null;
        foreach ($document['sections'] ?? [] as $section) {
            if (($section['hidden'] ?? false) && ! $preview) {
                continue;
            }
            $band = Theme::band($tokens, (string) ($section['settings']['background'] ?? 'default'));
            $html .= $this->section($section, $document, $context, $image, $tokens, $band, $previous === $band['bg']);
            if (! ($section['hidden'] ?? false)) {
                $previous = $band['bg'];
            }
        }
        if ($html === '' && $preview) {
            $html = '<div style="padding:120px 24px;text-align:center;color:'.Theme::mix($tokens['ink'], $tokens['paper'], 0.45).';font-size:16px">This page has no sections yet. Add one from the panel on the left.</div>';
        }
        // No inline margin: the theme's alignfull rules (negative margins) stretch the page edge to edge.
        $root = $this->css([
            'padding' => '0', 'max-width' => 'none', 'background' => $tokens['paper'], 'color' => $tokens['ink'],
            'font-family' => $tokens['body_font'], 'font-size' => 'clamp(15px,0.3vw + 14px,17px)', 'line-height' => '1.6',
            'text-align' => 'left', 'overflow' => 'hidden', 'overflow-wrap' => 'break-word',
        ]);
        $marker = $preview ? '' : ' data-mowl-page="'.(int) ($context['page_id'] ?? 0).'" data-mowl-version="'.(int) ($context['version'] ?? 0).'"';
        $page = '<div class="mowl-page alignfull"'.$marker.' style="'.$root.'">'.$html.'</div>';

        return $preview ? $page : '<!-- wp:html -->'.$page.'<!-- /wp:html -->';
    }

    /** One section, with its band. Hidden sections only render in the editor, faded. */
    public function section(array $section, array $document, array $context, callable $image, ?array $tokens = null, ?array $band = null, bool $continues = false): string
    {
        $tokens ??= Theme::tokens($document['theme'] ?? []);
        $band ??= Theme::band($tokens, (string) ($section['settings']['background'] ?? 'default'));
        $x = [
            'tok' => $tokens, 'band' => $band, 'preview' => (bool) ($context['preview'] ?? false), 'sid' => $section['id'],
            'page' => (int) ($context['page_id'] ?? 0), 'doc' => $document, 'image' => $image, 's' => $section['settings'] ?? [],
        ];
        $inner = match ($section['type']) {
            'announcement' => $this->announcement($x),
            'product_hero' => $this->productHero($x),
            'benefit_strip' => $this->benefitStrip($x),
            'feature' => $this->feature($x),
            'benefits' => $this->benefits($x),
            'numbered' => $this->numbered($x),
            'steps' => $this->steps($x),
            'comparison' => $this->comparison($x),
            'reviews' => $this->reviews($x),
            'stats' => $this->stats($x),
            'logos' => $this->logos($x),
            'guarantee' => $this->guarantee($x),
            'cta' => $this->cta($x),
            'faq' => $this->faq($x),
            'specs' => $this->specs($x),
            'rich_text' => $this->richText($x),
            'image' => $this->singleImage($x),
            'gallery' => $this->gallery($x),
            'video' => $this->video($x),
            'divider' => $this->divider($x),
            default => '',
        };
        if ($inner === '') {
            return $x['preview'] ? $this->shell($x, $this->placeholder($x, 'This '.mb_strtolower(Sections::get($section['type'])['label'] ?? 'section').' is empty and will not show on the page. Fill it in from the panel on the right.', 'block'), $continues) : '';
        }

        return $this->shell($x, $inner, $continues, $section['hidden'] ?? false);
    }

    /* ------------------------------------------------------------------ sections */

    private function announcement(array $x): string
    {
        $text = $this->field($x, 'text', 'span', [], 'Add a short message');
        if ($text === '') {
            return '';
        }
        $link = Document::url($x['s']['link'] ?? '');
        $body = $link !== '' && ! $x['preview']
            ? '<a href="'.$this->e($link).'" style="'.$this->css(['color' => 'inherit', 'text-decoration' => 'underline', 'text-underline-offset' => '3px', 'border' => '0', 'box-shadow' => 'none']).'">'.$text.'</a>'
            : $text;

        return '<p style="'.$this->css(['margin' => '0', 'padding' => '0', 'text-align' => 'center', 'font-size' => '14px', 'font-weight' => '600', 'letter-spacing' => '.01em', 'line-height' => '1.4', 'color' => 'inherit']).'">'.$body.'</p>';
    }

    private function productHero(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $images = array_values(array_filter((array) ($s['images'] ?? [])));
        $gallery = $this->heroGallery($x, $images);

        $info = '';
        $badge = $this->field($x, 'badge', 'span', [
            'display' => 'inline-block', 'padding' => '6px 12px', 'border-radius' => '999px', 'background' => Theme::alpha($b['accent'], 0.12), 'color' => $b['accent'],
            'font-size' => '12px', 'font-weight' => '700', 'letter-spacing' => '.08em', 'text-transform' => 'uppercase', 'line-height' => '1.3', 'margin' => '0 0 16px',
        ]);
        $info .= $badge !== '' ? '<div style="margin:0;padding:0">'.$badge.'</div>' : '';
        $info .= $this->field($x, 'title', 'h1', $this->heading($x, 'clamp(30px,2.4vw + 18px,48px)'), 'Product name');
        if (($s['show_rating'] ?? true) && ($rating = $this->rating($x['doc']))) {
            $info .= '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'align-items' => 'center', 'gap' => '4px 10px', 'margin' => '14px 0 0', 'font-size' => '14px', 'line-height' => '1.4']).'">'
                .$this->stars($rating['average'], 17).'<span style="color:'.$b['muted'].'">'.$this->e(number_format($rating['average'], 1)).' · '.$rating['count'].' '.($rating['count'] === 1 ? 'review' : 'reviews').'</span></div>';
        }
        $info .= $this->field($x, 'subtitle', 'p', ['margin' => '14px 0 0', 'font-size' => 'clamp(17px,0.4vw + 15px,20px)', 'line-height' => '1.5', 'color' => $b['muted']]);
        $info .= $this->priceRow($x, $s, '22px 0 0');
        $info .= $this->field($x, 'description', 'div', ['margin' => '18px 0 0', 'color' => $b['fg']], 'Describe the product in two or three sentences', true);

        $bullets = '';
        foreach ((array) ($s['bullets'] ?? []) as $index => $bullet) {
            $text = $this->field($x, "bullets.{$index}.text", 'span', ['color' => 'inherit'], 'Benefit');
            if ($text !== '') {
                $bullets .= '<li style="'.$this->css(['display' => 'flex', 'gap' => '10px', 'align-items' => 'flex-start', 'margin' => '0 0 10px', 'padding' => '0', 'list-style-type' => 'none', 'line-height' => '1.5']).'">'
                    .'<span aria-hidden="true" style="'.$this->css(['flex' => 'none', 'color' => $b['accent'], 'font-weight' => '800', 'margin' => '0 4px 0 0']).'">✓</span>'.$text.'</li>';
            }
        }
        $info .= $bullets !== '' ? '<ul style="'.$this->css(['list-style-type' => 'none', 'margin' => '22px 0 0', 'padding' => '0']).'">'.$bullets.'</ul>' : '';
        $info .= $this->button($x, 'button_label', 'button_url', ['display' => 'block', 'margin' => '26px 0 0', 'text-align' => 'center'], 'Buy now');
        $info .= $this->field($x, 'note', 'p', ['margin' => '12px 0 0', 'font-size' => '14px', 'color' => $b['muted'], 'text-align' => 'center', 'line-height' => '1.45']);
        $info .= $this->accordion($x, 'details', 'title', 'text', '26px 0 0', 16);

        if ($gallery === '') {
            return '<div style="'.$this->css(['max-width' => '720px', 'margin' => '0 auto']).'">'.$info.'</div>';
        }

        return $this->columns([$gallery, $info], ($s['image_side'] ?? 'left') === 'right', ['1 1 340px', '1 1 300px'], 'flex-start');
    }

    private function heroGallery(array $x, array $images): string
    {
        $b = $x['band'];
        $r = $x['tok']['radius'];
        if ($images === []) {
            return $x['preview'] ? $this->imagePlaceholder($x, 'images', 'Add product photos', '1/1') : '';
        }
        $slides = '';
        $thumbs = '';
        foreach (array_slice($images, 0, 6) as $index => $ref) {
            $image = $this->resolve($x, $ref);
            if (! $image) {
                continue;
            }
            $anchor = 'mowl-'.$x['page'].'-'.$x['sid'].'-'.($index + 1);
            $slides .= '<div id="'.$this->e($anchor).'" style="'.$this->css(['flex' => '0 0 100%', 'min-width' => '100%', 'scroll-snap-align' => 'start', 'margin' => '0', 'padding' => '0', 'aspect-ratio' => '1/1', 'background' => $b['card']]).'">'
                .$this->img($image, $ref['alt'] ?: ($x['s']['title'] ?? ''), ['width' => '100%', 'height' => '100%', 'object-fit' => 'contain', 'aspect-ratio' => '1/1'], $index === 0 ? 'eager' : 'lazy', '(max-width: 760px) 100vw, 620px').'</div>';
            $thumbs .= '<a href="#'.$this->e($anchor).'" aria-label="Show image '.($index + 1).'" style="'.$this->css(['display' => 'block', 'flex' => '0 0 64px', 'width' => '64px', 'height' => '64px', 'margin' => '0', 'padding' => '0', 'border-radius' => max(4, min(12, intdiv($r, 2))).'px', 'overflow' => 'hidden', 'border' => '1px solid '.$b['border'], 'background' => $b['card'], 'box-shadow' => 'none']).'">'
                .$this->img($image, '', ['width' => '64px', 'height' => '64px', 'object-fit' => 'cover'], 'lazy', '64px').'</a>';
        }
        if ($slides === '') {
            return $x['preview'] ? $this->imagePlaceholder($x, 'images', 'Add product photos', '1/1') : '';
        }
        $strip = '<div'.($x['preview'] ? ' data-mowl-image="images"' : '').' style="'.$this->css([
            'display' => 'flex', 'overflow' => 'auto', 'scroll-snap-type' => 'x mandatory', 'scrollbar-width' => 'none', 'margin' => '0', 'padding' => '0',
            'border-radius' => $r.'px', 'background' => $b['card'],
        ]).'">'.$slides.'</div>';

        return $strip.(count($images) > 1 ? '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'gap' => '10px', 'margin' => '12px 0 0', 'padding' => '0']).'">'.$thumbs.'</div>' : '');
    }

    private function benefitStrip(array $x): string
    {
        $items = '';
        foreach ((array) ($x['s']['items'] ?? []) as $index => $item) {
            $text = $this->field($x, "items.{$index}.text", 'span', ['color' => 'inherit'], 'Benefit');
            if ($text === '') {
                continue;
            }
            $items .= '<div style="'.$this->css(['display' => 'flex', 'align-items' => 'center', 'gap' => '10px', 'margin' => '0', 'padding' => '0', 'font-weight' => '700', 'font-size' => '15px', 'line-height' => '1.3']).'">'
                .$this->icon($item['icon'] ?? 'check', 22).$text.'</div>';
        }
        if ($items === '') {
            return '';
        }

        return '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'justify-content' => 'center', 'align-items' => 'center', 'gap' => '12px clamp(20px,3vw,44px)', 'text-align' => 'center', 'margin' => '0', 'padding' => '0']).'">'.$items.'</div>';
    }

    private function feature(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $text = $this->eyebrow($x, 'eyebrow')
            .$this->field($x, 'heading', 'h2', $this->heading($x), 'Add a heading')
            .$this->field($x, 'text', 'div', ['margin' => '16px 0 0', 'color' => $b['muted'], 'font-size' => 'clamp(16px,0.3vw + 15px,18px)'], 'Write a short paragraph about this benefit', true);

        $points = '';
        $cards = ($s['points_style'] ?? 'list') === 'cards';
        foreach ((array) ($s['points'] ?? []) as $index => $point) {
            $title = $this->field($x, "points.{$index}.title", 'strong', ['display' => 'block', 'font-weight' => '700', 'color' => $cards ? $b['card_fg'] : $b['fg'], 'line-height' => '1.35'], 'Point');
            $body = $this->field($x, "points.{$index}.text", 'span', ['display' => 'block', 'margin' => '4px 0 0', 'color' => $b['muted'], 'font-size' => '15px', 'line-height' => '1.5']);
            if ($title === '' && $body === '') {
                continue;
            }
            $points .= $cards
                ? '<div style="'.$this->css(['margin' => '0', 'padding' => '18px', 'border-radius' => max(6, $x['tok']['radius'] - 4).'px', 'background' => $b['card'], 'color' => $b['card_fg']]).'">'.$this->icon($point['icon'] ?? 'check', 22, ['display' => 'block', 'margin' => '0 0 10px']).$title.$body.'</div>'
                : '<div style="'.$this->css(['display' => 'flex', 'gap' => '14px', 'align-items' => 'flex-start', 'margin' => '0', 'padding' => '0']).'">'.$this->iconTile($x, $point['icon'] ?? 'check', 40).'<div style="margin:0;padding:0;min-width:0">'.$title.$body.'</div></div>';
        }
        if ($points !== '') {
            $text .= '<div style="'.$this->css($cards
                ? ['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit,minmax(min(100%,180px),1fr))', 'gap' => '12px', 'margin' => '26px 0 0']
                : ['display' => 'grid', 'gap' => '18px', 'margin' => '26px 0 0']).'">'.$points.'</div>';
        }
        $text .= $this->button($x, 'button_label', 'button_url', ['display' => 'inline-block', 'margin' => '28px 0 0']);

        $image = $this->picture($x, 'image', ['border-radius' => $x['tok']['radius'].'px'], 'Add an image', '4/5');
        if ($image === '') {
            return '<div style="'.$this->css(['max-width' => '760px', 'margin' => '0 auto']).'">'.$text.'</div>';
        }

        return $this->columns([$image, $text], ($s['image_side'] ?? 'left') === 'right', ['1 1 300px', '1 1 300px'], 'center');
    }

    private function benefits(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $plain = ($s['style'] ?? 'cards') === 'plain';
        $cards = [];
        foreach ((array) ($s['items'] ?? []) as $index => $item) {
            $title = $this->field($x, "items.{$index}.title", 'h3', $this->heading($x, 'clamp(18px,0.4vw + 16px,21px)', ['color' => $plain ? $b['fg'] : $b['card_fg'], 'line-height' => '1.3']), 'Benefit');
            $text = $this->field($x, "items.{$index}.text", 'p', ['margin' => '8px 0 0', 'font-size' => '15px', 'line-height' => '1.55', 'color' => $plain ? $b['muted'] : Theme::mix($b['card_fg'], $b['card'], 0.3)]);
            if ($title === '' && $text === '') {
                continue;
            }
            $tile = $this->iconTile($x, $item['icon'] ?? 'check', 52, $plain ? ['margin' => '0 auto 18px'] : ['margin' => '0 0 18px'], $plain ? null : $b['card']);
            $cards[] = '<div style="'.$this->css($plain
                ? ['margin' => '0', 'padding' => '8px', 'text-align' => 'center']
                : ['margin' => '0', 'padding' => 'clamp(22px,2vw,30px)', 'border-radius' => $x['tok']['radius'].'px', 'background' => $b['card'], 'color' => $b['card_fg']]).'">'.$tile.$title.$text.'</div>';
        }
        if ($cards === [] && ! $x['preview']) {
            return '';
        }

        return $this->intro($x, 'eyebrow', 'heading', 'subheading', 'Why you will love it')
            .($cards ? $this->grid($cards, count($cards), 240, 20, '40px 0 0') : $this->placeholder($x, 'Add benefits from the panel on the right.'));
    }

    private function numbered(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $rows = '';
        $number = 0;
        foreach ((array) ($s['items'] ?? []) as $index => $item) {
            $title = $this->field($x, "items.{$index}.title", 'h3', $this->heading($x, 'clamp(18px,0.4vw + 16px,22px)', ['line-height' => '1.3']), 'Reason');
            $text = $this->field($x, "items.{$index}.text", 'p', ['margin' => '6px 0 0', 'color' => $b['muted'], 'font-size' => '15px', 'line-height' => '1.55']);
            if ($title === '' && $text === '') {
                continue;
            }
            $number++;
            $rows .= '<div style="'.$this->css(['display' => 'flex', 'gap' => 'clamp(14px,2vw,24px)', 'align-items' => 'flex-start', 'margin' => '0', 'padding' => '22px 0', 'border-top' => '1px solid '.$b['border']]).'">'
                .'<span aria-hidden="true" style="'.$this->css(['flex' => 'none', 'min-width' => '52px', 'font-family' => $x['tok']['heading_font'], 'font-size' => 'clamp(26px,1.6vw + 16px,38px)', 'font-weight' => '800', 'line-height' => '1', 'color' => $b['accent'], 'letter-spacing' => '-.02em']).'">'.sprintf('%02d', $number).'</span>'
                .'<div style="margin:0;padding:0;min-width:0">'.$title.$text.'</div></div>';
        }
        if ($rows === '' && ! $x['preview']) {
            return '';
        }
        $head = $this->field($x, 'heading', 'h2', $this->heading($x), 'Reasons to choose it')
            .$this->field($x, 'subheading', 'p', ['margin' => '14px 0 0', 'color' => $b['muted'], 'font-size' => 'clamp(16px,0.3vw + 15px,18px)']);
        $list = '<div style="margin:30px 0 0;padding:0">'.($rows ?: $this->placeholder($x, 'Add reasons from the panel on the right.')).'</div>';
        $image = $this->picture($x, 'image', ['border-radius' => $x['tok']['radius'].'px'], 'Add an image', '4/5', false);
        if ($image === '') {
            return '<div style="'.$this->css(['max-width' => '820px', 'margin' => '0 auto']).'">'.$head.$list.'</div>';
        }

        return $this->columns([$image, $head.$list], ($s['image_side'] ?? 'left') === 'right', ['1 1 280px', '1 1 340px'], 'center');
    }

    private function steps(array $x): string
    {
        $b = $x['band'];
        $cards = [];
        $number = 0;
        foreach ((array) ($x['s']['items'] ?? []) as $index => $item) {
            $title = $this->field($x, "items.{$index}.title", 'h3', $this->heading($x, 'clamp(18px,0.4vw + 16px,21px)', ['color' => $b['card_fg'], 'line-height' => '1.3']), 'Step');
            $text = $this->field($x, "items.{$index}.text", 'p', ['margin' => '8px 0 0', 'font-size' => '15px', 'line-height' => '1.55', 'color' => Theme::mix($b['card_fg'], $b['card'], 0.3)]);
            if ($title === '' && $text === '') {
                continue;
            }
            $number++;
            $circle = '<div aria-hidden="true" style="'.$this->css([
                'width' => '46px', 'height' => '46px', 'border-radius' => '50%', 'background' => $b['button_bg'], 'color' => $b['button_fg'], 'font-weight' => '800',
                'font-size' => '19px', 'line-height' => '46px', 'text-align' => 'center', 'margin' => '0 0 18px', 'font-family' => $x['tok']['heading_font'],
            ]).'">'.$number.'</div>';
            $cards[] = '<div style="'.$this->css(['margin' => '0', 'padding' => 'clamp(22px,2vw,30px)', 'border-radius' => $x['tok']['radius'].'px', 'background' => $b['card'], 'color' => $b['card_fg']]).'">'.$circle.$title.$text.'</div>';
        }
        if ($cards === [] && ! $x['preview']) {
            return '';
        }

        return $this->intro($x, 'eyebrow', 'heading', 'subheading', 'How it works')
            .($cards ? $this->grid($cards, count($cards), 230, 20, '40px 0 0') : $this->placeholder($x, 'Add steps from the panel on the right.'));
    }

    private function comparison(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $tok = $x['tok'];
        $usBg = $b['button_bg'];
        $usFg = $b['button_fg'];
        $usSoft = Theme::mix($usBg, $b['card'], 0.9);
        $rows = '';
        foreach ((array) ($s['rows'] ?? []) as $index => $row) {
            $label = $this->field($x, "rows.{$index}.label", 'span', ['color' => 'inherit'], 'Feature');
            if ($label === '') {
                continue;
            }
            $cell = ['padding' => '15px 12px', 'border' => '0', 'border-top' => '1px solid '.$b['border'], 'text-align' => 'center', 'vertical-align' => 'middle', 'background' => 'transparent'];
            $rows .= '<tr style="background:transparent">'
                .'<td style="'.$this->css(['padding' => '15px 16px 15px 20px', 'border' => '0', 'border-top' => '1px solid '.$b['border'], 'text-align' => 'left', 'font-weight' => '600', 'vertical-align' => 'middle', 'background' => 'transparent', 'line-height' => '1.4']).'">'.$label.'</td>'
                .'<td style="'.$this->css(['background' => $usSoft] + $cell).'">'.$this->mark((bool) ($row['us'] ?? true), $usBg, $usFg, $b).'</td>'
                .'<td style="'.$this->css($cell).'">'.$this->mark((bool) ($row['them'] ?? false), $b['muted'], $b['card'], $b).'</td></tr>';
        }
        if ($rows === '' && ! $x['preview']) {
            return '';
        }
        $head = '<tr style="background:transparent">'
            .'<th style="'.$this->css(['width' => '48%', 'padding' => '16px 20px', 'border' => '0', 'background' => 'transparent']).'"></th>'
            .'<th scope="col" style="'.$this->css(['padding' => '16px 10px', 'border' => '0', 'background' => $usBg, 'color' => $usFg, 'font-weight' => '800', 'text-align' => 'center', 'font-size' => '15px', 'line-height' => '1.3']).'">'.$this->field($x, 'us_label', 'span', ['color' => 'inherit'], 'Ours').'</th>'
            .'<th scope="col" style="'.$this->css(['padding' => '16px 10px', 'border' => '0', 'background' => 'transparent', 'color' => $b['muted'], 'font-weight' => '700', 'text-align' => 'center', 'font-size' => '15px', 'line-height' => '1.3']).'">'.$this->field($x, 'them_label', 'span', ['color' => 'inherit'], 'Others').'</th></tr>';
        $table = '<div style="'.$this->css(['max-width' => '820px', 'margin' => '36px auto 0', 'padding' => '0', 'border-radius' => $tok['radius'].'px', 'overflow' => 'hidden', 'background' => $b['card'], 'color' => $b['card_fg'], 'border' => '1px solid '.$b['border']]).'">'
            .($rows !== '' ? '<table style="'.$this->css(['width' => '100%', 'border-collapse' => 'collapse', 'border' => '0', 'margin' => '0', 'background' => 'transparent', 'table-layout' => 'fixed', 'font-size' => '15px']).'"><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>' : $this->placeholder($x, 'Add rows from the panel on the right.'))
            .'</div>';

        return $this->intro($x, null, 'heading', 'text', 'Why it stands out').$table
            .$this->button($x, 'button_label', 'button_url', ['display' => 'table', 'margin' => '32px auto 0']);
    }

    private function reviews(array $x): string
    {
        $b = $x['band'];
        $cards = [];
        foreach ((array) ($x['s']['items'] ?? []) as $index => $item) {
            $text = $this->field($x, "items.{$index}.text", 'p', ['margin' => '14px 0 0', 'line-height' => '1.6', 'color' => $b['card_fg']], 'Paste the review text');
            $name = $this->field($x, "items.{$index}.name", 'strong', ['display' => 'block', 'font-weight' => '700', 'color' => $b['card_fg']], 'Customer name');
            if ($text === '' && $name === '') {
                continue;
            }
            $photo = '';
            if (is_array($item['image'] ?? null) && ($image = $this->resolve($x, $item['image']))) {
                $photo = '<div style="margin:0 0 16px;padding:0">'.$this->img($image, $item['image']['alt'] ?? '', ['width' => '100%', 'height' => 'auto', 'aspect-ratio' => '4/3', 'object-fit' => 'cover', 'border-radius' => max(6, $x['tok']['radius'] - 4).'px'], 'lazy', '(max-width: 600px) 100vw, 400px').'</div>';
            }
            $detail = $this->field($x, "items.{$index}.detail", 'span', ['display' => 'block', 'margin' => '2px 0 0', 'font-size' => '13px', 'color' => Theme::mix($b['card_fg'], $b['card'], 0.38)]);
            $cards[] = '<figure style="'.$this->css(['margin' => '0', 'padding' => 'clamp(20px,2vw,28px)', 'border-radius' => $x['tok']['radius'].'px', 'background' => $b['card'], 'color' => $b['card_fg']]).'">'
                .$photo.$this->stars((float) ($item['rating'] ?? 5), 16).$text
                .'<figcaption style="margin:18px 0 0;padding:0;font-size:15px;line-height:1.4">'.$name.$detail.'</figcaption></figure>';
        }
        if ($cards === [] && ! $x['preview']) {
            return '';
        }
        $summary = '';
        if ($rating = $this->rating(['sections' => [['type' => 'reviews', 'settings' => $x['s']]]])) {
            $summary = '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'justify-content' => 'center', 'align-items' => 'center', 'gap' => '6px 12px', 'margin' => '16px 0 0', 'text-align' => 'center', 'font-size' => '15px']).'">'
                .$this->stars($rating['average'], 20).'<span style="color:'.$b['muted'].'">'.$this->e(number_format($rating['average'], 1)).' out of 5 · '.$rating['count'].' '.($rating['count'] === 1 ? 'review' : 'reviews').'</span></div>';
        }
        $grid = $cards
            ? '<div style="'.$this->css(['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit,minmax(min(100%,270px),1fr))', 'gap' => '18px', 'align-items' => 'start', 'margin' => '36px 0 0']).'">'.implode('', $cards).'</div>'
            : $this->placeholder($x, 'Add real reviews from your customers in the panel on the right. They show here exactly as written.');

        return $this->intro($x, null, 'heading', 'subheading', 'What customers say').$summary.$grid;
    }

    private function stats(array $x): string
    {
        $b = $x['band'];
        $cards = [];
        foreach ((array) ($x['s']['items'] ?? []) as $index => $item) {
            $value = $this->field($x, "items.{$index}.value", 'div', [
                'margin' => '0', 'font-family' => $x['tok']['heading_font'], 'font-size' => 'clamp(36px,2.6vw + 22px,60px)', 'font-weight' => '800',
                'line-height' => '1', 'letter-spacing' => '-.03em', 'color' => $b['accent'],
            ], '00%');
            $label = $this->field($x, "items.{$index}.label", 'p', ['margin' => '10px 0 0', 'font-size' => '15px', 'line-height' => '1.45', 'color' => $b['fg']], 'What it measures');
            if ($value === '' && $label === '') {
                continue;
            }
            $cards[] = '<div style="margin:0;padding:8px;text-align:center">'.$value.$label.'</div>';
        }
        if ($cards === [] && ! $x['preview']) {
            return '';
        }

        return $this->intro($x, null, 'heading', 'subheading', 'The numbers')
            .($cards ? $this->grid($cards, count($cards), 180, 24, '40px 0 0') : $this->placeholder($x, 'Add numbers you can back up.'))
            .$this->field($x, 'source', 'p', ['margin' => '28px 0 0', 'text-align' => 'center', 'font-size' => '13px', 'color' => $b['muted']], 'Where these numbers come from');
    }

    private function logos(array $x): string
    {
        $b = $x['band'];
        $logos = '';
        foreach ((array) ($x['s']['images'] ?? []) as $ref) {
            if ($image = $this->resolve($x, $ref)) {
                $logos .= '<span style="display:inline-block;margin:0;padding:0">'.$this->img($image, $ref['alt'] ?? '', ['height' => '38px', 'width' => 'auto', 'max-width' => '150px', 'object-fit' => 'contain', 'filter' => 'grayscale(1)', 'opacity' => '.75'], 'lazy', '150px').'</span>';
            }
        }
        if ($logos === '') {
            return $x['preview'] ? $this->imagePlaceholder($x, 'images', 'Add logos of press, partners or certifications you have', '6/1') : '';
        }

        return $this->field($x, 'heading', 'p', ['margin' => '0 0 24px', 'text-align' => 'center', 'font-size' => '13px', 'font-weight' => '700', 'letter-spacing' => '.12em', 'text-transform' => 'uppercase', 'color' => $b['muted']])
            .'<div'.($x['preview'] ? ' data-mowl-image="images"' : '').' style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'justify-content' => 'center', 'align-items' => 'center', 'gap' => '20px clamp(28px,4vw,56px)', 'text-align' => 'center', 'margin' => '0', 'padding' => '0']).'">'.$logos.'</div>';
    }

    private function guarantee(array $x): string
    {
        $b = $x['band'];
        $heading = $this->field($x, 'heading', 'h2', $this->heading($x, 'clamp(26px,1.8vw + 16px,38px)'), 'Our promise');
        $text = $this->field($x, 'text', 'div', ['margin' => '14px auto 0', 'max-width' => '620px', 'color' => $b['muted'], 'font-size' => 'clamp(16px,0.3vw + 15px,18px)'], 'Describe the returns or satisfaction promise your store really offers', true);
        if ($heading === '' && $text === '') {
            return '';
        }
        $icon = '<div aria-hidden="true" style="'.$this->css([
            'width' => '76px', 'height' => '76px', 'margin' => '0 auto 22px', 'border-radius' => '50%', 'background' => $b['card'], 'font-size' => '36px',
            'line-height' => '76px', 'text-align' => 'center',
        ]).'">'.$this->e(Sections::ICONS[$x['s']['icon'] ?? 'shield'] ?? '🛡️').'</div>';

        return '<div style="'.$this->css(['max-width' => '760px', 'margin' => '0 auto', 'text-align' => 'center']).'">'.$icon.$heading.$text
            .$this->button($x, 'button_label', 'button_url', ['display' => 'inline-block', 'margin' => '28px 0 0']).'</div>';
    }

    private function cta(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $text = $this->field($x, 'heading', 'h2', $this->heading($x, 'clamp(30px,2.6vw + 16px,52px)'), 'Ready to try it?')
            .$this->field($x, 'text', 'div', ['margin' => '16px 0 0', 'color' => $b['muted'], 'font-size' => 'clamp(16px,0.4vw + 15px,19px)'], null, true);
        if (($s['show_price'] ?? true) && ($hero = Document::first($x['doc'], 'product_hero'))) {
            $text .= $this->priceRow($x, $hero['settings'], '24px 0 0', false);
        }
        $image = $this->picture($x, 'image', ['border-radius' => $x['tok']['radius'].'px'], null, '1/1', false);
        $center = $image === '';
        $text .= $this->button($x, 'button_label', 'button_url', ['display' => 'inline-block', 'margin' => '30px 0 0', 'font-size' => '18px', 'padding' => '19px 40px'], 'Buy now');
        if ($center) {
            return '<div style="'.$this->css(['max-width' => '760px', 'margin' => '0 auto', 'text-align' => 'center']).'">'.$text.'</div>';
        }

        return $this->columns([$text, $image], false, ['1 1 320px', '1 1 280px'], 'center');
    }

    private function faq(array $x): string
    {
        $s = $x['s'];
        $b = $x['band'];
        $items = $this->accordion($x, 'items', 'question', 'answer', '0', 17, 'Question a buyer asks', 'The answer');
        if ($items === '' && ! $x['preview']) {
            return '';
        }
        $items = $items ?: $this->placeholder($x, 'Add questions from the panel on the right.');
        $contact = $this->field($x, 'contact', 'p', ['margin' => '18px 0 0', 'font-size' => '15px', 'color' => $b['muted']]);
        if (($s['layout'] ?? 'split') === 'stacked') {
            return $this->intro($x, null, 'heading', 'subheading', 'Frequently asked questions')
                .'<div style="'.$this->css(['max-width' => '820px', 'margin' => '36px auto 0', 'border-top' => '1px solid '.$b['border']]).'">'.$items.'</div>'
                .($contact !== '' ? '<div style="text-align:center;margin:0">'.$contact.'</div>' : '');
        }
        $head = $this->field($x, 'heading', 'h2', $this->heading($x), 'Frequently asked questions')
            .$this->field($x, 'subheading', 'p', ['margin' => '14px 0 0', 'color' => $b['muted']]).$contact;

        return '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'gap' => '28px clamp(32px,5vw,80px)', 'align-items' => 'flex-start', 'margin' => '0', 'padding' => '0']).'">'
            .'<div style="'.$this->css(['flex' => '1 1 260px', 'min-width' => '0', 'margin' => '0', 'padding' => '0']).'">'.$head.'</div>'
            .'<div style="'.$this->css(['flex' => '2 1 420px', 'min-width' => '0', 'margin' => '0', 'padding' => '0', 'border-top' => '1px solid '.$b['border']]).'">'.$items.'</div></div>';
    }

    private function specs(array $x): string
    {
        $b = $x['band'];
        $rows = '';
        foreach ((array) ($x['s']['rows'] ?? []) as $index => $row) {
            $label = $this->field($x, "rows.{$index}.label", 'span', ['color' => 'inherit'], 'Label');
            $value = $this->field($x, "rows.{$index}.value", 'span', ['color' => 'inherit'], 'Value', true);
            if ($label === '' && $value === '') {
                continue;
            }
            $cell = ['padding' => '15px 20px', 'border' => '0', 'border-top' => $rows === '' ? '0' : '1px solid '.$b['border'], 'vertical-align' => 'top', 'background' => 'transparent', 'line-height' => '1.5', 'text-align' => 'left'];
            $rows .= '<tr style="background:transparent"><th scope="row" style="'.$this->css(['width' => '38%', 'font-weight' => '600', 'color' => Theme::mix($b['card_fg'], $b['card'], 0.3)] + $cell).'">'.$label.'</th><td style="'.$this->css($cell).'">'.$value.'</td></tr>';
        }
        if ($rows === '' && ! $x['preview']) {
            return '';
        }

        return '<div style="'.$this->css(['max-width' => '860px', 'margin' => '0 auto']).'">'
            .$this->field($x, 'heading', 'h2', $this->heading($x, 'clamp(26px,1.6vw + 18px,38px)', ['text-align' => 'center']), 'Product details')
            .'<div style="'.$this->css(['margin' => '30px 0 0', 'padding' => '6px 0', 'border-radius' => $x['tok']['radius'].'px', 'background' => $b['card'], 'color' => $b['card_fg'], 'overflow' => 'hidden']).'">'
            .($rows !== '' ? '<table style="'.$this->css(['width' => '100%', 'border-collapse' => 'collapse', 'border' => '0', 'margin' => '0', 'background' => 'transparent', 'font-size' => '15px']).'"><tbody>'.$rows.'</tbody></table>' : $this->placeholder($x, 'Add rows from the panel on the right.'))
            .'</div></div>';
    }

    private function richText(array $x): string
    {
        $center = ($x['s']['align'] ?? 'center') === 'center';
        $body = $this->eyebrow($x, 'eyebrow')
            .$this->field($x, 'heading', 'h2', $this->heading($x), null)
            .$this->field($x, 'text', 'div', ['margin' => '16px 0 0', 'font-size' => 'clamp(16px,0.35vw + 15px,19px)', 'line-height' => '1.7', 'color' => $x['band']['fg']], 'Write a few paragraphs', true);
        if (trim(strip_tags($body)) === '' && ! $x['preview']) {
            return '';
        }

        return '<div style="'.$this->css(['max-width' => '760px', 'margin' => $center ? '0 auto' : '0', 'text-align' => $center ? 'center' : 'left']).'">'.$body.'</div>';
    }

    private function singleImage(array $x): string
    {
        $wide = ($x['s']['width'] ?? 'contained') === 'wide';
        $image = $this->picture($x, 'image', ['border-radius' => $x['tok']['radius'].'px'], 'Add an image', '16/9', true, $wide ? '(max-width: 1280px) 100vw, 1280px' : '(max-width: 900px) 100vw, 880px');
        if ($image === '') {
            return '';
        }

        return '<figure style="'.$this->css(['max-width' => $wide ? 'none' : '880px', 'margin' => '0 auto', 'padding' => '0']).'">'.$image
            .$this->field($x, 'caption', 'figcaption', ['margin' => '14px 0 0', 'text-align' => 'center', 'font-size' => '14px', 'color' => $x['band']['muted']]).'</figure>';
    }

    private function gallery(array $x): string
    {
        $columns = max(2, min(4, (int) ($x['s']['columns'] ?? 3)));
        $cells = [];
        foreach ((array) ($x['s']['images'] ?? []) as $ref) {
            if ($image = $this->resolve($x, $ref)) {
                $cells[] = '<div style="margin:0;padding:0">'.$this->img($image, $ref['alt'] ?? '', ['width' => '100%', 'height' => 'auto', 'aspect-ratio' => '1/1', 'object-fit' => 'cover', 'border-radius' => max(4, $x['tok']['radius'] - 4).'px'], 'lazy', '(max-width: 600px) 50vw, 400px').'</div>';
            }
        }
        if ($cells === []) {
            return $x['preview'] ? $this->intro($x, null, 'heading', null, null).$this->imagePlaceholder($x, 'images', 'Add images', '3/1') : '';
        }

        return $this->intro($x, null, 'heading', null, null)
            .'<div'.($x['preview'] ? ' data-mowl-image="images"' : '').' style="'.$this->css([
                'display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit,minmax(min(100%,max(150px,calc((100% - '.(($columns - 1) * 14).'px)/'.$columns.'))),1fr))',
                'gap' => '14px', 'margin' => trim(strip_tags($x['s']['heading'] ?? '')) !== '' ? '32px 0 0' : '0',
            ]).'">'.implode('', $cells).'</div>';
    }

    private function video(array $x): string
    {
        $url = Document::url($x['s']['url'] ?? '');
        $cover = is_array($x['s']['image'] ?? null) ? $this->resolve($x, $x['s']['image']) : null;
        $youtube = $this->youtubeCover($url);
        $cover ??= $youtube ? ['url' => $youtube, 'id' => null, 'width' => 480, 'height' => 360] : null;
        if ($url === '' && ! $x['preview']) {
            return '';
        }
        $play = '<span aria-hidden="true" style="'.$this->css([
            'position' => 'absolute', 'left' => '50%', 'top' => '50%', 'width' => '78px', 'height' => '78px', 'margin' => '-39px 0 0 -39px', 'border-radius' => '50%',
            'background' => '#ffffffee', 'color' => '#111111', 'font-size' => '28px', 'line-height' => '78px', 'text-align' => 'center', 'box-shadow' => '0 10px 30px #00000040', 'padding' => '0 0 0 4px',
        ]).'">▶</span>';
        $media = $cover
            ? $this->img($cover, $x['s']['caption'] ?: 'Play the video', ['width' => '100%', 'height' => 'auto', 'aspect-ratio' => '16/9', 'object-fit' => 'cover'], 'lazy', '(max-width: 960px) 100vw, 960px')
            : '<div style="'.$this->css(['aspect-ratio' => '16/9', 'background' => '#111111', 'margin' => '0']).'"></div>';
        $attrs = $url !== '' && ! $x['preview'] ? ' href="'.$this->e($url).'" target="_blank" rel="noopener"' : '';
        $frame = '<a'.$attrs.($x['preview'] ? ' data-mowl-image="image"' : '').' style="'.$this->css([
            'display' => 'block', 'position' => 'relative', 'max-width' => '960px', 'margin' => '0 auto', 'padding' => '0', 'border-radius' => $x['tok']['radius'].'px',
            'overflow' => 'hidden', 'background' => '#111111', 'border' => '0', 'box-shadow' => 'none', 'text-decoration' => 'none',
        ]).'">'.$media.$play.'</a>';
        $heading = $this->field($x, 'heading', 'h2', $this->heading($x, null, ['text-align' => 'center', 'margin' => '0 0 30px']));

        return $heading.$frame.$this->field($x, 'caption', 'p', ['margin' => '14px 0 0', 'text-align' => 'center', 'font-size' => '14px', 'color' => $x['band']['muted']])
            .($url === '' ? $this->placeholder($x, 'Add a YouTube or Vimeo link in the panel on the right.') : '');
    }

    private function divider(array $x): string
    {
        $height = ['small' => 24, 'medium' => 56, 'large' => 104][$x['s']['size'] ?? 'medium'] ?? 56;
        if (! ($x['s']['line'] ?? false)) {
            return '<div aria-hidden="true" style="height:'.$height.'px;margin:0;padding:0"></div>';
        }

        return '<div aria-hidden="true" style="margin:0;padding:'.intdiv($height, 2).'px 0"><div style="'.$this->css(['height' => '1px', 'margin' => '0', 'padding' => '0', 'background' => $x['band']['border']]).'"></div></div>';
    }

    /* ------------------------------------------------------------------ building blocks */

    private function shell(array $x, string $inner, bool $continues, bool $hidden = false): string
    {
        $tok = $x['tok'];
        $type = $this->typeOf($x);
        [$top, $bottom] = match ($type) {
            'announcement' => ['11px', '11px'],
            'benefit_strip' => ['clamp(18px,2.2vw,26px)', 'clamp(18px,2.2vw,26px)'],
            'divider' => ['0', '0'],
            'logos' => ['clamp(32px,4vw,48px)', 'clamp(32px,4vw,48px)'],
            default => ["clamp(44px,6vw,{$tok['pad']}px)", "clamp(44px,6vw,{$tok['pad']}px)"],
        };
        if ($continues && ! in_array($type, ['announcement', 'benefit_strip', 'divider'], true)) {
            $top = '0';
        }
        $style = [
            'margin' => '0', 'padding' => "{$top} clamp(16px,4vw,32px) {$bottom}", 'background' => $x['band']['bg'], 'color' => $x['band']['fg'], 'border' => '0',
        ];
        $attrs = '';
        if ($x['preview']) {
            $attrs = ' data-mowl-section="'.$this->e($x['sid']).'" data-mowl-type="'.$this->e($type).'"'.($hidden ? ' data-mowl-hidden="1"' : '');
            $style['position'] = 'relative';
            if ($hidden) {
                $style['opacity'] = '.45';
            }
        }

        return '<section'.$attrs.' style="'.$this->css($style).'"><div style="'.$this->css(['max-width' => $tok['width'].'px', 'margin' => '0 auto', 'padding' => '0']).'">'.$inner.'</div></section>';
    }

    /** Eyebrow, heading and subheading, centred above a section's content. */
    private function intro(array $x, ?string $eyebrow, ?string $heading, ?string $subheading, ?string $placeholder): string
    {
        $html = ($eyebrow ? $this->eyebrow($x, $eyebrow) : '')
            .($heading ? $this->field($x, $heading, 'h2', $this->heading($x), $placeholder) : '')
            .($subheading ? $this->field($x, $subheading, 'div', ['margin' => '14px auto 0', 'max-width' => '640px', 'color' => $x['band']['muted'], 'font-size' => 'clamp(16px,0.3vw + 15px,18px)'], null, true) : '');

        return $html === '' ? '' : '<div style="'.$this->css(['max-width' => '760px', 'margin' => '0 auto', 'text-align' => 'center']).'">'.$html.'</div>';
    }

    private function eyebrow(array $x, string $path): string
    {
        return $this->field($x, $path, 'p', [
            'margin' => '0 0 12px', 'font-size' => '13px', 'font-weight' => '700', 'letter-spacing' => '.12em', 'text-transform' => 'uppercase',
            'color' => $x['band']['accent'], 'line-height' => '1.4',
        ]);
    }

    /** Heading styles from the theme: font, weight, case and a fluid size. */
    private function heading(array $x, ?string $size = null, array $extra = []): array
    {
        $tok = $x['tok'];
        $upper = $tok['heading_case'] === 'upper';
        $serif = in_array($tok['theme']['heading_font'], ['serif', 'elegant'], true);

        return array_replace([
            'margin' => '0', 'padding' => '0', 'border' => '0', 'background' => 'none', 'font-family' => $tok['heading_font'],
            'font-size' => $size ?? 'clamp(28px,2.2vw + 16px,44px)', 'font-weight' => $serif ? (string) min(600, $tok['heading_weight']) : (string) $tok['heading_weight'],
            'line-height' => '1.14', 'letter-spacing' => $upper ? '.02em' : ($serif ? '-.01em' : '-.025em'), 'text-transform' => $upper ? 'uppercase' : 'none',
            'color' => 'inherit', 'font-style' => 'normal',
        ], $extra);
    }

    /** Two columns that stack on narrow screens, the first one on top. */
    private function columns(array $columns, bool $reverse, array $basis, string $align): string
    {
        $html = '';
        foreach ($columns as $index => $column) {
            $html .= '<div style="'.$this->css(['flex' => $basis[$index] ?? '1 1 320px', 'min-width' => '0', 'margin' => '0', 'padding' => '0']).'">'.$column.'</div>';
        }

        return '<div style="'.$this->css([
            'display' => 'flex', 'flex-wrap' => 'wrap', 'flex-direction' => $reverse ? 'row-reverse' : 'row', 'align-items' => $align,
            'gap' => 'clamp(28px,4vw,64px)', 'margin' => '0', 'padding' => '0',
        ]).'">'.$html.'</div>';
    }

    /** A grid that shows up to $count columns on wide screens and fewer on narrow ones. */
    private function grid(array $cells, int $count, int $min, int $gap, string $margin): string
    {
        $perRow = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 3, 6 => 3, 7 => 4, 8 => 4][$count] ?? 4;
        $track = 'min(100%,max('.$min.'px,calc((100% - '.(($perRow - 1) * $gap).'px)/'.$perRow.')))';

        return '<div style="'.$this->css(['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit,minmax('.$track.',1fr))', 'gap' => $gap.'px', 'margin' => $margin, 'padding' => '0']).'">'.implode('', $cells).'</div>';
    }

    /** Collapsible rows: product details in the hero, questions in the FAQ. Open in the editor. */
    private function accordion(array $x, string $list, string $titleKey, string $bodyKey, string $margin, int $size, ?string $titlePlaceholder = null, ?string $bodyPlaceholder = null): string
    {
        $b = $x['band'];
        $items = '';
        foreach ((array) ($x['s'][$list] ?? []) as $index => $item) {
            $title = $this->field($x, "{$list}.{$index}.{$titleKey}", 'span', ['color' => 'inherit', 'min-width' => '0'], $titlePlaceholder);
            $body = $this->field($x, "{$list}.{$index}.{$bodyKey}", 'div', ['margin' => '0', 'padding' => '0 0 20px', 'color' => $b['muted'], 'font-size' => '15px', 'line-height' => '1.65'], $bodyPlaceholder, true);
            if ($title === '' && $body === '') {
                continue;
            }
            $items .= '<details'.($x['preview'] ? ' open' : '').' style="'.$this->css(['margin' => '0', 'padding' => '0', 'border' => '0', 'border-bottom' => '1px solid '.$b['border'], 'background' => 'transparent']).'">'
                .'<summary style="'.$this->css([
                    'display' => 'flex', 'justify-content' => 'space-between', 'align-items' => 'center', 'gap' => '16px', 'cursor' => 'pointer', 'list-style-type' => 'none',
                    'margin' => '0', 'padding' => '18px 0', 'font-weight' => '700', 'font-size' => $size.'px', 'line-height' => '1.4', 'color' => $b['fg'], 'background' => 'transparent', 'border' => '0',
                ]).'">'.$title.'<span aria-hidden="true" style="'.$this->css(['flex' => 'none', 'color' => $b['muted'], 'font-weight' => '400', 'font-size' => '22px', 'line-height' => '1']).'">+</span></summary>'
                .$body.'</details>';
        }

        return $items === '' ? '' : ($margin === '0' ? $items : '<div style="'.$this->css(['margin' => $margin, 'padding' => '0', 'border-top' => '1px solid '.$b['border']]).'">'.$items.'</div>');
    }

    /** Price, an optional real former price and the saving between them. */
    private function priceRow(array $x, array $settings, string $margin, bool $editable = true): string
    {
        $b = $x['band'];
        $price = Document::text($settings['price'] ?? '', 40, false);
        $compare = Document::text($settings['compare_price'] ?? '', 40, false);
        if ($price === '') {
            return '';
        }
        $priceHtml = $editable ? $this->field($x, 'price', 'span', [], null) : $this->e($price);
        $html = '<span style="'.$this->css(['font-size' => 'clamp(24px,1.2vw + 18px,34px)', 'font-weight' => '800', 'line-height' => '1.1', 'letter-spacing' => '-.01em', 'color' => $b['fg']]).'">'.$priceHtml.'</span>';
        $saving = $this->saving($price, $compare);
        if ($compare !== '' && $saving !== null) {
            $html .= '<s style="'.$this->css(['font-size' => '18px', 'color' => $b['muted'], 'text-decoration' => 'line-through']).'">'.($editable ? $this->field($x, 'compare_price', 'span', [], null) : $this->e($compare)).'</s>';
            $html .= '<span style="'.$this->css(['display' => 'inline-block', 'padding' => '4px 10px', 'border-radius' => '999px', 'background' => $b['button_bg'], 'color' => $b['button_fg'], 'font-size' => '13px', 'font-weight' => '700', 'line-height' => '1.3']).'">Save '.$saving.'%</span>';
        }
        $center = ! $editable;

        return '<div style="'.$this->css(['display' => 'flex', 'flex-wrap' => 'wrap', 'align-items' => 'baseline', 'justify-content' => $center ? 'center' : 'flex-start', 'gap' => '6px 12px', 'margin' => $margin, 'padding' => '0']).'">'.$html.'</div>';
    }

    /** The saving in percent when the former price is higher, else null. */
    public function saving(string $price, string $compare): ?int
    {
        $now = $this->amount($price);
        $before = $this->amount($compare);
        if (! $now || ! $before || $before <= $now) {
            return null;
        }

        return (int) round(($before - $now) / $before * 100);
    }

    /** The first amount in the text, read as the price checks read it ("€30,00" is 30). */
    public function amount(string $text): ?float
    {
        if (! preg_match('/\d(?:[\d.,]*\d)?/u', $text, $match)) {
            return null;
        }

        return (float) CampaignPackSafetyValidator::number($match[0]);
    }

    /**
     * A button. Its link is the field's own, else the page's buy link. Without a label it is left out;
     * in the editor an empty optional button is left out too, so it can be added from the panel.
     */
    private function button(array $x, string $labelPath, string $urlPath, array $extra, ?string $placeholder = null): string
    {
        $b = $x['band'];
        $label = Document::text($x['s'][$labelPath] ?? '', 60, false);
        if ($label === '' && ! ($x['preview'] && $placeholder)) {
            return '';
        }
        $url = Document::url($x['s'][$urlPath] ?? '') ?: Document::url($x['doc']['buy_url'] ?? '');
        $style = $this->css(array_replace([
            'display' => 'inline-block', 'padding' => '17px 34px', 'border-radius' => $x['tok']['button_radius'].'px', 'background' => $b['button_bg'], 'color' => $b['button_fg'],
            'font-family' => $x['tok']['body_font'], 'font-size' => '17px', 'font-weight' => '700', 'line-height' => '1.2', 'text-decoration' => 'none', 'text-align' => 'center',
            'border' => '0', 'box-shadow' => '0 8px 22px '.Theme::alpha($b['button_bg'], 0.22), 'letter-spacing' => '.01em', 'cursor' => 'pointer',
        ], $extra));
        $text = $this->field($x, $labelPath, 'span', ['color' => 'inherit'], $placeholder);
        $href = $x['preview'] ? '#' : ($url !== '' ? $url : '#');

        return '<a href="'.$this->e($href).'" style="'.$style.'">'.$text.'</a>';
    }

    /**
     * A text field as an element. Empty fields are left out of the page; in the editor, fields with a
     * placeholder show it so they can be clicked and typed into.
     */
    private function field(array $x, string $path, string $tag, array $style, ?string $placeholder = null, bool $multiline = false): string
    {
        $value = $this->valueAt($x['s'], $path);
        $value = is_string($value) ? trim($value) : '';
        $attrs = $x['preview'] ? ' data-mowl-field="'.$this->e($path).'"'.($multiline ? ' data-mowl-multiline="1"' : '') : '';
        if ($value === '') {
            if (! $x['preview'] || $placeholder === null) {
                return '';
            }
            $style['opacity'] = '.45';
            $style['font-style'] = 'italic';

            return '<'.$tag.$attrs.' data-mowl-empty="1" style="'.$this->css($style).'">'.$this->e($placeholder).'</'.$tag.'>';
        }
        $content = $multiline ? $this->paragraphs($value, $tag === 'span' ? null : $style) : $this->e($value);
        if ($multiline && $tag !== 'span') {
            // Paragraphs carry their own spacing; the wrapper keeps the field's margins and colour.
            return '<div'.$attrs.' style="'.$this->css($style).'">'.$content.'</div>';
        }

        return '<'.$tag.$attrs.($style ? ' style="'.$this->css($style).'"' : '').'>'.$content.'</'.$tag.'>';
    }

    private function paragraphs(string $text, ?array $style): string
    {
        $blocks = preg_split('/\n{2,}/', $text) ?: [];
        if ($style === null) {
            return implode('<br><br>', array_map(fn ($block) => str_replace("\n", '<br>', $this->e($block)), $blocks));
        }
        $html = '';
        foreach ($blocks as $index => $block) {
            $html .= '<p style="'.$this->css(['margin' => $index === 0 ? '0' : '14px 0 0', 'padding' => '0', 'color' => 'inherit', 'font-size' => 'inherit', 'line-height' => 'inherit']).'">'.str_replace("\n", '<br>', $this->e($block)).'</p>';
        }

        return $html;
    }

    /** An image field as an <img>, or a placeholder in the editor. */
    private function picture(array $x, string $path, array $style, ?string $placeholder, string $ratio, bool $placeholderInPreview = true, string $sizes = '(max-width: 760px) 100vw, 560px'): string
    {
        $ref = $this->valueAt($x['s'], $path);
        $image = is_array($ref) ? $this->resolve($x, $ref) : null;
        if (! $image) {
            return $x['preview'] && $placeholder !== null && $placeholderInPreview ? $this->imagePlaceholder($x, $path, $placeholder, $ratio) : '';
        }
        $img = $this->img($image, $ref['alt'] ?? '', array_replace(['width' => '100%', 'height' => 'auto'], $style), 'lazy', $sizes);

        return $x['preview'] ? '<div data-mowl-image="'.$this->e($path).'" style="margin:0;padding:0">'.$img.'</div>' : $img;
    }

    /**
     * An <img>. Real width and height keep the layout steady while it loads. A store media id adds
     * WordPress's "wp-image-{id}" class, so the store serves sized copies (srcset) of the upload.
     *
     * @param  array{url: string, id: ?string, width: ?int, height: ?int}  $image
     */
    private function img(array $image, string $alt, array $style, string $loading = 'lazy', ?string $sizes = null): string
    {
        $attrs = ' src="'.$this->e($image['url']).'" alt="'.$this->e($alt).'"'
            .($image['width'] && $image['height'] ? ' width="'.$image['width'].'" height="'.$image['height'].'"' : '')
            .' loading="'.$loading.'" decoding="async"'
            .($image['id'] !== null && ctype_digit($image['id']) ? ' class="wp-image-'.$image['id'].'"' : '')
            .($sizes ? ' sizes="'.$this->e($sizes).'"' : '');

        return '<img'.$attrs.' style="'.$this->css(array_replace([
            'display' => 'block', 'max-width' => '100%', 'margin' => '0', 'padding' => '0', 'border' => '0', 'box-shadow' => 'none', 'border-radius' => '0',
        ], $style)).'">';
    }

    /**
     * What an image reference points to: its URL and, when known, its store media id and size.
     *
     * @return array{url: string, id: ?string, width: ?int, height: ?int}|null
     */
    private function resolve(array $x, mixed $ref): ?array
    {
        if (! is_array($ref) || ! isset($ref['kind'], $ref['id'])) {
            return null;
        }
        $resolved = ($x['image'])($ref);
        if (is_string($resolved)) {
            $resolved = ['url' => $resolved];
        }
        $url = is_array($resolved) ? trim((string) ($resolved['url'] ?? '')) : '';
        if ($url === '') {
            return null;
        }
        $width = (int) ($resolved['width'] ?? 0);
        $height = (int) ($resolved['height'] ?? 0);

        return ['url' => $url, 'id' => isset($resolved['id']) ? (string) $resolved['id'] : null, 'width' => $width > 0 ? $width : null, 'height' => $height > 0 ? $height : null];
    }

    private function imagePlaceholder(array $x, string $path, string $label, string $ratio): string
    {
        $b = $x['band'];

        return '<div data-mowl-image="'.$this->e($path).'" data-mowl-empty="1" style="'.$this->css([
            'aspect-ratio' => $ratio, 'min-height' => '120px', 'margin' => '0', 'padding' => '24px', 'border-radius' => $x['tok']['radius'].'px', 'border' => '2px dashed '.$b['border'],
            'background' => Theme::alpha($b['fg'], 0.03), 'color' => $b['muted'], 'display' => 'flex', 'align-items' => 'center', 'justify-content' => 'center',
            'text-align' => 'center', 'font-size' => '15px', 'cursor' => 'pointer',
        ]).'">'.$this->e($label).'</div>';
    }

    private function placeholder(array $x, string $text, string $kind = 'inline'): string
    {
        $b = $x['band'];

        return '<div style="'.$this->css([
            'margin' => $kind === 'block' ? '0' : '24px 0 0', 'padding' => '22px', 'border-radius' => '12px', 'border' => '1.5px dashed '.$b['border'],
            'color' => $b['muted'], 'text-align' => 'center', 'font-size' => '14px', 'line-height' => '1.5',
        ]).'">'.$this->e($text).'</div>';
    }

    private function icon(string $key, int $size, array $extra = []): string
    {
        return '<span aria-hidden="true" style="'.$this->css(array_replace(['flex' => 'none', 'font-size' => $size.'px', 'line-height' => '1', 'margin' => '0'], $extra)).'">'.$this->e(Sections::ICONS[$key] ?? Sections::ICONS['check']).'</span>';
    }

    private function iconTile(array $x, string $key, int $size, array $extra = [], ?string $on = null): string
    {
        $b = $x['band'];
        $bg = Theme::mix($b['accent'], $on ?? $b['bg'], 0.86);

        return '<div aria-hidden="true" style="'.$this->css(array_replace([
            'flex' => 'none', 'width' => $size.'px', 'height' => $size.'px', 'border-radius' => min(16, max(8, $x['tok']['radius'])).'px', 'background' => $bg,
            'font-size' => (int) round($size * 0.5).'px', 'line-height' => $size.'px', 'text-align' => 'center', 'margin' => '0',
        ], $extra)).'">'.$this->e(Sections::ICONS[$key] ?? Sections::ICONS['check']).'</div>';
    }

    /** A tick or a cross in a circle, for comparison tables. */
    private function mark(bool $yes, string $bg, string $fg, array $band): string
    {
        $style = ['display' => 'inline-block', 'width' => '28px', 'height' => '28px', 'line-height' => '28px', 'border-radius' => '50%', 'text-align' => 'center', 'font-size' => '15px', 'font-weight' => '800'];

        return $yes
            ? '<span role="img" aria-label="Yes" style="'.$this->css($style + ['background' => $bg, 'color' => $fg]).'">✓</span>'
            : '<span role="img" aria-label="No" style="'.$this->css($style + ['background' => Theme::alpha($band['muted'], 0.14), 'color' => $band['muted']]).'">✕</span>';
    }

    private function stars(float $rating, int $size): string
    {
        $full = (int) round(max(0, min(5, $rating)));

        return '<span role="img" aria-label="'.$this->e(number_format($rating, 1)).' out of 5 stars" style="'.$this->css(['color' => self::STAR, 'font-size' => $size.'px', 'letter-spacing' => '2px', 'line-height' => '1', 'white-space' => 'nowrap']).'">'
            .str_repeat('★', $full).'<span style="opacity:.3">'.str_repeat('★', 5 - $full).'</span></span>';
    }

    /**
     * The average of the reviews the page shows, from its first visible reviews section.
     *
     * @return array{average: float, count: int}|null
     */
    public function rating(array $document): ?array
    {
        $section = Document::first($document, 'reviews');
        $ratings = [];
        foreach ((array) ($section['settings']['items'] ?? []) as $item) {
            if (trim((string) ($item['text'] ?? '')) !== '' && trim((string) ($item['name'] ?? '')) !== '') {
                $ratings[] = max(1, min(5, (int) ($item['rating'] ?? 5)));
            }
        }

        return $ratings ? ['average' => round(array_sum($ratings) / count($ratings), 1), 'count' => count($ratings)] : null;
    }

    private function youtubeCover(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $id = null;
        if (str_ends_with($host, 'youtu.be')) {
            $id = trim($path, '/');
        } elseif (str_ends_with($host, 'youtube.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = $query['v'] ?? (preg_match('#/(?:embed|shorts|live)/([^/?]+)#', $path, $m) ? $m[1] : null);
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : null;
    }

    private function valueAt(array $settings, string $path): mixed
    {
        $value = $settings;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function typeOf(array $x): string
    {
        foreach ($x['doc']['sections'] ?? [] as $section) {
            if ($section['id'] === $x['sid']) {
                return $section['type'];
            }
        }

        return '';
    }

    /** A style attribute from trusted values: no double quotes, no line breaks. */
    private function css(array $declarations): string
    {
        $parts = [];
        foreach ($declarations as $property => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $property.':'.str_replace(['"', "\n", "\r", '<', '>'], '', (string) $value);
        }

        return implode(';', $parts);
    }

    /** Text for HTML: escaped, with square brackets as entities so WordPress runs no shortcode. */
    private function e(mixed $value): string
    {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
