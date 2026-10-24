<?php

namespace App\Services\Pages\Writing;

use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Templates;

/**
 * Page copy without an AI, for local development and tests: each section is written from the
 * product brief the way templates fill it. A rewrite asked to be shorter keeps the first sentence.
 */
class MockPageWriter implements PageWriter
{
    public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
    {
        $used = ['angles' => 0, 'photos' => 0];
        $shorter = (bool) preg_match('/\b(short|shorter|concise|brief)\b/i', $instruction);
        $sections = [];
        foreach ($targets as $id) {
            $section = Document::find($document, $id);
            if (! $section) {
                continue;
            }
            $filled = Templates::fill($section['type'], $brief, $used);
            $written = PageCopy::current(['type' => $section['type'], 'settings' => $filled ?? $section['settings']]);
            if ($filled === null && count($targets) > 1) {
                // Nothing true to say: an empty answer lets a whole-page write drop the section.
                $written = array_map(fn ($value) => is_array($value) ? [] : '', $written);
            }
            if ($shorter) {
                array_walk_recursive($written, function (&$value): void {
                    $value = is_string($value) && preg_match('/^(.+?[.!?])\s/u', $value, $match) ? $match[1] : $value;
                });
            }
            $sections[$id] = $written;
        }

        return new PageWriting($sections, 'mock');
    }
}
