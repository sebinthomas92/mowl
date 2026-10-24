<?php

namespace App\Services\Pages\Document;

use Illuminate\Support\Str;

/**
 * The page document: a theme, a page-wide buy link and an ordered list of sections, each with settings
 * that follow its type's fields in {@see Sections}. Everything that reaches the database or the
 * renderer passes through {@see self::sanitize()}, so a document is always well formed whatever the
 * browser or the AI sent.
 */
final class Document
{
    public const FORMAT = 2;

    public const MAX_SECTIONS = 40;

    /** A new, empty document with the default theme. */
    public static function blank(array $theme = []): array
    {
        return ['format' => self::FORMAT, 'theme' => Theme::sanitize($theme ?: Theme::defaults()), 'buy_url' => '', 'sections' => [], 'confirmations' => []];
    }

    /** A new section of a type, with its defaults and any settings given. */
    public static function section(string $type, array $settings = [], array $extra = []): array
    {
        return self::cleanSection(['id' => self::newId('s'), 'type' => $type, 'hidden' => false, 'settings' => array_replace(Sections::defaults($type), $settings)] + $extra) ?? [];
    }

    public static function newId(string $prefix): string
    {
        return $prefix.Str::lower(Str::random(8));
    }

    /**
     * A document from untrusted input. Unknown section types, fields and values are dropped; text is
     * trimmed to its field's length; images must pass $imageExists (when given).
     *
     * Confirmations are kept only when $keepConfirmations is true: they record an owner's decision and
     * are written by the server, never taken from a browser.
     *
     * @param  (callable(array): bool)|null  $imageExists
     */
    public static function sanitize(mixed $document, ?callable $imageExists = null, bool $keepConfirmations = false): array
    {
        $document = is_array($document) ? $document : [];
        $sections = [];
        $ids = [];
        foreach (array_slice(array_values(array_filter((array) ($document['sections'] ?? []), 'is_array')), 0, self::MAX_SECTIONS) as $section) {
            $clean = self::cleanSection($section, $imageExists);
            if (! $clean) {
                continue;
            }
            // Ids must stay unique: a pasted or duplicated section gets its own.
            while (isset($ids[$clean['id']])) {
                $clean['id'] = self::newId('s');
            }
            $ids[$clean['id']] = true;
            $sections[] = $clean;
        }

        $confirmations = [];
        if ($keepConfirmations) {
            foreach ((array) ($document['confirmations'] ?? []) as $key => $confirmation) {
                if (is_string($key) && preg_match('/^[0-9a-f]{40}$/', $key) && is_array($confirmation)) {
                    $confirmations[$key] = [
                        'by' => (int) ($confirmation['by'] ?? 0),
                        'name' => mb_substr(trim((string) ($confirmation['name'] ?? '')), 0, 120),
                        'at' => mb_substr((string) ($confirmation['at'] ?? ''), 0, 40),
                        'text' => mb_substr((string) ($confirmation['text'] ?? ''), 0, 300),
                    ];
                }
            }
        }

        return [
            'format' => self::FORMAT,
            'theme' => Theme::sanitize($document['theme'] ?? null),
            'buy_url' => self::url($document['buy_url'] ?? ''),
            'sections' => $sections,
            'confirmations' => array_slice($confirmations, 0, 200, true),
        ];
    }

    /** @param  (callable(array): bool)|null  $imageExists */
    public static function cleanSection(mixed $section, ?callable $imageExists = null): ?array
    {
        if (! is_array($section) || ! is_string($section['type'] ?? null) || ! Sections::exists($section['type'])) {
            return null;
        }
        $type = $section['type'];
        $id = is_string($section['id'] ?? null) && preg_match('/^s[a-z0-9]{4,20}$/', $section['id']) ? $section['id'] : self::newId('s');
        $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
        $clean = [];
        foreach (Sections::get($type)['fields'] as $field) {
            $clean[$field['key']] = self::value($field, $settings[$field['key']] ?? null, array_key_exists($field['key'], $settings), $imageExists);
        }

        return ['id' => $id, 'type' => $type, 'hidden' => (bool) ($section['hidden'] ?? false), 'settings' => $clean];
    }

    /**
     * One field's value. A missing value takes the field's default; a present but invalid one becomes
     * empty, so clearing a field in the editor sticks.
     *
     * @param  (callable(array): bool)|null  $imageExists
     */
    public static function value(array $field, mixed $value, bool $present = true, ?callable $imageExists = null): mixed
    {
        if (! $present) {
            return Sections::fieldDefault($field);
        }

        return match ($field['type']) {
            'text' => self::text($value, $field['max'] ?? 200, false),
            'textarea' => self::text($value, $field['max'] ?? 1000, true),
            'url' => self::url($value),
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'rating' => max(1, min(5, (int) (is_numeric($value) ? $value : 5))),
            'select' => is_scalar($value) && array_key_exists((string) $value, $field['options']) ? (string) $value : Sections::fieldDefault($field),
            'icon' => is_string($value) && isset(Sections::ICONS[$value]) ? $value : ($field['default'] ?? 'check'),
            'image' => self::image($value, $imageExists),
            'images' => array_values(array_slice(array_filter(array_map(fn ($ref) => self::image($ref, $imageExists), is_array($value) ? array_values($value) : [])), 0, $field['max'] ?? 12)),
            'list' => self::items($field, $value, $imageExists),
            default => null,
        };
    }

    /** @param  (callable(array): bool)|null  $imageExists */
    private static function items(array $field, mixed $value, ?callable $imageExists): array
    {
        $items = [];
        $ids = [];
        foreach (array_slice(array_values(array_filter(is_array($value) ? $value : [], 'is_array')), 0, $field['max'] ?? 10) as $item) {
            $clean = [];
            foreach ($field['fields'] as $child) {
                $clean[$child['key']] = self::value($child, $item[$child['key']] ?? null, array_key_exists($child['key'], $item), $imageExists);
            }
            $id = is_string($item['id'] ?? null) && preg_match('/^i[a-z0-9]{4,20}$/', $item['id']) && ! isset($ids[$item['id']]) ? $item['id'] : self::newId('i');
            $ids[$id] = true;
            $items[] = ['id' => $id] + $clean;
        }

        return $items;
    }

    /** Plain text: tags and control characters removed, whitespace tidied, cut to length. */
    public static function text(mixed $value, int $max, bool $multiline): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
        if ($multiline) {
            $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
            $text = (string) preg_replace('/ *\n */u', "\n", $text);
            $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        } else {
            $text = (string) preg_replace('/\s+/u', ' ', $text);
        }

        return mb_substr(trim($text), 0, $max);
    }

    public static function url(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 600 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? $value : '';
    }

    /**
     * An image reference: a product image (media) or a banner made in the app, never a URL. The
     * publisher uploads the image to the store and the app previews it from its own storage.
     *
     * @param  (callable(array): bool)|null  $imageExists
     */
    public static function image(mixed $value, ?callable $imageExists = null): ?array
    {
        if (! is_array($value) || ! in_array($value['kind'] ?? null, ['media', 'banner'], true) || ! is_numeric($value['id'] ?? null) || (int) $value['id'] < 1) {
            return null;
        }
        $ref = ['kind' => $value['kind'], 'id' => (int) $value['id'], 'key' => $value['kind'].'-'.(int) $value['id'], 'alt' => self::text($value['alt'] ?? '', 160, false)];
        if ($imageExists && ! $imageExists($ref)) {
            return null;
        }

        return $ref;
    }

    /**
     * Every image a document uses.
     *
     * @return list<array{kind: string, id: int, key: string, alt: string}>
     */
    public static function images(array $document): array
    {
        $refs = [];
        foreach ($document['sections'] ?? [] as $section) {
            self::walk(Sections::get($section['type'])['fields'] ?? [], $section['settings'] ?? [], function (array $field, mixed $value) use (&$refs): void {
                if ($field['type'] === 'image' && is_array($value)) {
                    $refs[$value['key']] = $value;
                }
                if ($field['type'] === 'images') {
                    foreach ((array) $value as $ref) {
                        $refs[$ref['key']] = $ref;
                    }
                }
            });
        }

        return array_values($refs);
    }

    /**
     * Every text value of the visible sections, with where it sits.
     *
     * @return list<array{section: string, type: string, path: string, field: array, text: string}>
     */
    public static function texts(array $document): array
    {
        $texts = [];
        foreach ($document['sections'] ?? [] as $section) {
            if ($section['hidden'] ?? false) {
                continue;
            }
            self::walk(Sections::get($section['type'])['fields'] ?? [], $section['settings'] ?? [], function (array $field, mixed $value, string $path) use (&$texts, $section): void {
                if (in_array($field['type'], ['text', 'textarea'], true) && is_string($value) && trim($value) !== '') {
                    $texts[] = ['section' => $section['id'], 'type' => $section['type'], 'path' => $path, 'field' => $field, 'text' => $value];
                }
            });
        }

        return $texts;
    }

    /** Calls $visit for every field value, lists included ("items.2.title"). */
    public static function walk(array $fields, array $settings, callable $visit, string $prefix = ''): void
    {
        foreach ($fields as $field) {
            $value = $settings[$field['key']] ?? null;
            $path = $prefix.$field['key'];
            if ($field['type'] === 'list') {
                foreach (is_array($value) ? $value : [] as $index => $item) {
                    if (is_array($item)) {
                        self::walk($field['fields'], $item, $visit, "{$path}.{$index}.");
                    }
                }

                continue;
            }
            $visit($field, $value, $path);
        }
    }

    public static function find(array $document, string $sectionId): ?array
    {
        foreach ($document['sections'] ?? [] as $section) {
            if ($section['id'] === $sectionId) {
                return $section;
            }
        }

        return null;
    }

    /** The first visible section of a type. */
    public static function first(array $document, string $type): ?array
    {
        foreach ($document['sections'] ?? [] as $section) {
            if ($section['type'] === $type && ! ($section['hidden'] ?? false)) {
                return $section;
            }
        }

        return null;
    }
}
