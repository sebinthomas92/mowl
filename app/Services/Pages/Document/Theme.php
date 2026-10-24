<?php

namespace App\Services\Pages\Document;

/**
 * A page's look: five colours, two fonts, corner radius, button shape, spacing and width. Everything
 * else (muted text, borders, tints, text on accent and dark bands) is worked out from the five colours,
 * so any palette stays readable.
 */
final class Theme
{
    /** Ready-made palettes. "brand" is added from the product's brand when it has colours. */
    public const PALETTES = [
        'coral' => ['label' => 'Coral', 'primary' => '#d9381e', 'ink' => '#171717', 'paper' => '#ffffff', 'surface' => '#f6f3ef', 'dark' => '#171717'],
        'midnight' => ['label' => 'Midnight', 'primary' => '#3451d1', 'ink' => '#121826', 'paper' => '#ffffff', 'surface' => '#f2f4fa', 'dark' => '#0c1222'],
        'forest' => ['label' => 'Forest', 'primary' => '#2e7a4c', 'ink' => '#14231a', 'paper' => '#ffffff', 'surface' => '#f1f5ef', 'dark' => '#13291c'],
        'sand' => ['label' => 'Sand', 'primary' => '#a85a2a', 'ink' => '#2a211b', 'paper' => '#fffcf7', 'surface' => '#f4ece1', 'dark' => '#2a211b'],
        'blush' => ['label' => 'Blush', 'primary' => '#c2417a', 'ink' => '#2b1a22', 'paper' => '#ffffff', 'surface' => '#fbf0f4', 'dark' => '#2b1a22'],
        'ocean' => ['label' => 'Ocean', 'primary' => '#0b7480', 'ink' => '#0f1f24', 'paper' => '#ffffff', 'surface' => '#edf5f6', 'dark' => '#0b2328'],
        'sunset' => ['label' => 'Sunset', 'primary' => '#e0560b', 'ink' => '#1f1410', 'paper' => '#ffffff', 'surface' => '#fff3ea', 'dark' => '#1f1410'],
        'violet' => ['label' => 'Violet', 'primary' => '#6440d6', 'ink' => '#1a1530', 'paper' => '#ffffff', 'surface' => '#f4f1fd', 'dark' => '#17122b'],
        'mono' => ['label' => 'Mono', 'primary' => '#111111', 'ink' => '#111111', 'paper' => '#ffffff', 'surface' => '#f4f4f4', 'dark' => '#111111'],
    ];

    public const COLOR_KEYS = ['primary' => 'Accent', 'ink' => 'Text', 'paper' => 'Page', 'surface' => 'Light panels', 'dark' => 'Dark panels'];

    /**
     * Font stacks only: store pages cannot load web fonts through post content, so every stack ends in
     * fonts that phones and computers already have. "theme" uses the store's own font.
     */
    public const FONTS = [
        'theme' => ['label' => 'Store font', 'stack' => 'inherit'],
        'sans' => ['label' => 'Clean sans', 'stack' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif"],
        'geometric' => ['label' => 'Geometric', 'stack' => "'Avenir Next',Avenir,'Century Gothic',Futura,'Segoe UI',system-ui,sans-serif"],
        'rounded' => ['label' => 'Rounded', 'stack' => "ui-rounded,'SF Pro Rounded','Arial Rounded MT Bold',Nunito,'Segoe UI',system-ui,sans-serif"],
        'condensed' => ['label' => 'Condensed', 'stack' => "'Avenir Next Condensed','Arial Narrow','Roboto Condensed','Helvetica Neue',Arial,sans-serif"],
        'serif' => ['label' => 'Classic serif', 'stack' => "Georgia,'Times New Roman',Times,serif"],
        'elegant' => ['label' => 'Elegant serif', 'stack' => "'Iowan Old Style','Palatino Linotype',Palatino,'Book Antiqua',Georgia,serif"],
        'mono' => ['label' => 'Mono', 'stack' => "ui-monospace,'SF Mono',Menlo,Consolas,'Liberation Mono',monospace"],
    ];

    public const BUTTONS = ['square' => 'Square', 'rounded' => 'Rounded', 'pill' => 'Pill'];

    public const SPACING = ['compact' => 'Compact', 'normal' => 'Normal', 'airy' => 'Airy'];

    public const WIDTHS = [960 => 'Narrow', 1120 => 'Standard', 1280 => 'Wide'];

    public const WEIGHTS = [500 => 'Regular', 600 => 'Medium', 700 => 'Bold', 800 => 'Heavy'];

    public const CASES = ['normal' => 'As typed', 'upper' => 'Capitals'];

    public static function defaults(): array
    {
        return self::fromPalette('coral') + [
            'heading_font' => 'sans', 'body_font' => 'sans', 'heading_weight' => 700, 'heading_case' => 'normal',
            'radius' => 16, 'button' => 'pill', 'spacing' => 'normal', 'width' => 1120,
        ];
    }

    /** @return array{palette: string, colors: array<string, string>} */
    public static function fromPalette(string $key, ?array $colors = null): array
    {
        $palette = $colors ?? self::PALETTES[$key] ?? self::PALETTES['coral'];

        return ['palette' => $key, 'colors' => array_map(fn ($color) => strtolower($color), array_intersect_key($palette, self::COLOR_KEYS))];
    }

    /**
     * A palette built from a brand's colours: its first colour is the accent, and a very dark one
     * (when the brand has it) colours the dark panels.
     *
     * @param  list<string>  $colors
     */
    public static function brandPalette(array $colors): ?array
    {
        $colors = array_values(array_filter(array_map(fn ($color) => self::hex($color), $colors)));
        if (! $colors) {
            return null;
        }
        $primary = $colors[0];
        $dark = collect($colors)->first(fn (string $color) => self::luminance($color) < 0.04) ?? '#171717';
        $surface = collect($colors)->first(fn (string $color) => self::luminance($color) > 0.82 && $color !== '#ffffff') ?? self::mix($primary, '#ffffff', 0.94);

        return ['label' => 'Brand', 'primary' => $primary, 'ink' => '#171717', 'paper' => '#ffffff', 'surface' => $surface, 'dark' => $dark];
    }

    /** A theme from untrusted input: every value checked, anything unknown replaced by the default. */
    public static function sanitize(mixed $theme): array
    {
        $defaults = self::defaults();
        $theme = is_array($theme) ? $theme : [];
        $colors = [];
        foreach (self::COLOR_KEYS as $key => $label) {
            $colors[$key] = self::hex($theme['colors'][$key] ?? null) ?? $defaults['colors'][$key];
        }
        $palette = is_string($theme['palette'] ?? null) && (isset(self::PALETTES[$theme['palette']]) || in_array($theme['palette'], ['brand', 'custom'], true))
            ? $theme['palette'] : 'custom';

        return [
            'palette' => $palette,
            'colors' => $colors,
            'heading_font' => self::pick($theme['heading_font'] ?? null, self::FONTS, $defaults['heading_font']),
            'body_font' => self::pick($theme['body_font'] ?? null, self::FONTS, $defaults['body_font']),
            'heading_weight' => (int) self::pick((string) ($theme['heading_weight'] ?? ''), array_combine(array_map('strval', array_keys(self::WEIGHTS)), self::WEIGHTS), (string) $defaults['heading_weight']),
            'heading_case' => self::pick($theme['heading_case'] ?? null, self::CASES, $defaults['heading_case']),
            'radius' => max(0, min(32, (int) ($theme['radius'] ?? $defaults['radius']))),
            'button' => self::pick($theme['button'] ?? null, self::BUTTONS, $defaults['button']),
            'spacing' => self::pick($theme['spacing'] ?? null, self::SPACING, $defaults['spacing']),
            'width' => (int) self::pick((string) ($theme['width'] ?? ''), array_combine(array_map('strval', array_keys(self::WIDTHS)), self::WIDTHS), (string) $defaults['width']),
        ];
    }

    /**
     * Everything the renderer needs, resolved: colours with their derived shades, font stacks and sizes.
     */
    public static function tokens(array $theme): array
    {
        $theme = self::sanitize($theme);
        $c = $theme['colors'];
        $onPrimary = self::readable($c['primary'], ['#ffffff', $c['ink'], '#000000']);
        $onDark = self::readable($c['dark'], ['#ffffff', $c['paper'], $c['ink']]);
        $soft = self::mix($c['primary'], $c['paper'], 0.9);

        return [
            'theme' => $theme,
            'primary' => $c['primary'], 'ink' => $c['ink'], 'paper' => $c['paper'], 'surface' => $c['surface'], 'dark' => $c['dark'],
            'on_primary' => $onPrimary, 'on_dark' => $onDark, 'soft' => $soft,
            'heading_font' => self::FONTS[$theme['heading_font']]['stack'],
            'body_font' => self::FONTS[$theme['body_font']]['stack'],
            'heading_weight' => $theme['heading_weight'],
            'heading_case' => $theme['heading_case'],
            'radius' => $theme['radius'],
            'button_radius' => ['square' => 0, 'rounded' => min(12, max(6, $theme['radius'])), 'pill' => 999][$theme['button']],
            'pad' => ['compact' => 48, 'normal' => 76, 'airy' => 108][$theme['spacing']],
            'width' => $theme['width'],
        ];
    }

    /**
     * Colours for one section background: page, light, tint, accent or dark.
     *
     * @return array{bg: string, fg: string, muted: string, border: string, card: string, card_fg: string, accent: string, button_bg: string, button_fg: string, dark: bool}
     */
    public static function band(array $tokens, string $background): array
    {
        $ink = $tokens['ink'];
        $primary = $tokens['primary'];
        [$bg, $fg] = match ($background) {
            'surface' => [$tokens['surface'], $ink],
            'soft' => [$tokens['soft'], $ink],
            'accent' => [$primary, $tokens['on_primary']],
            'dark' => [$tokens['dark'], $tokens['on_dark']],
            default => [$tokens['paper'], $ink],
        };
        $fg = self::contrast($bg, $fg) >= 4.5 ? $fg : self::readable($bg, [$ink, '#ffffff', '#000000']);
        $muted = self::mix($fg, $bg, 0.36);
        if (self::contrast($bg, $muted) < 4.5) {
            $muted = self::mix($fg, $bg, 0.2);
        }
        $card = match ($background) {
            'surface', 'soft' => $tokens['paper'],
            'accent', 'dark' => self::mix($fg, $bg, 0.9),
            default => self::contrast($tokens['surface'], $tokens['paper']) > 1.03 ? $tokens['surface'] : self::mix($ink, $bg, 0.95),
        };
        $cardFg = self::contrast($card, $fg) >= 4.5 ? $fg : self::readable($card, [$ink, '#ffffff']);
        $accent = self::contrast($bg, $primary) >= 3 && $background !== 'accent' ? $primary : $fg;
        [$buttonBg, $buttonFg] = $background === 'accent' || self::contrast($bg, $primary) < 1.6
            ? [$fg, self::readable($fg, [$bg, $primary, $ink, '#ffffff'])]
            : [$primary, $tokens['on_primary']];

        return [
            'bg' => $bg, 'fg' => $fg, 'muted' => $muted, 'border' => self::mix($fg, $bg, 0.86), 'card' => $card, 'card_fg' => $cardFg,
            'accent' => $accent, 'button_bg' => $buttonBg, 'button_fg' => $buttonFg, 'dark' => self::luminance($bg) < 0.2,
        ];
    }

    public static function hex(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }
        $color = strtolower(trim($color));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) {
            return "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}";
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }

    /** $a moved toward $b by $amount (0 keeps $a, 1 gives $b). */
    public static function mix(string $a, string $b, float $amount): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        $amount = max(0.0, min(1.0, $amount));

        return sprintf('#%02x%02x%02x', (int) round($ar + ($br - $ar) * $amount), (int) round($ag + ($bg - $ag) * $amount), (int) round($ab + ($bb - $ab) * $amount));
    }

    /** $color with an alpha channel, as 8-digit hex (store pages refuse rgba()). */
    public static function alpha(string $color, float $opacity): string
    {
        return (self::hex($color) ?? '#000000').sprintf('%02x', (int) round(max(0, min(1, $opacity)) * 255));
    }

    public static function luminance(string $color): float
    {
        $channels = array_map(function (int $value): float {
            $value /= 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::rgb($color));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** The first candidate readable on $bg (4.5:1), else the most readable one. */
    public static function readable(string $bg, array $candidates): string
    {
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if (self::contrast($bg, $candidate) >= 4.5) {
                return $candidate;
            }
            if (self::contrast($bg, $candidate) > self::contrast($bg, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $color): array
    {
        $color = self::hex($color) ?? '#000000';

        return [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
    }

    private static function pick(mixed $value, array $options, string $default): string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : $default;
    }
}
