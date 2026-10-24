<?php

namespace App\Services\Pages\Writing;

/** What a page writer returned: field values by section id, and what it cost. */
final class PageWriting
{
    /** @param  array<string, array>  $sections */
    public function __construct(
        public readonly array $sections,
        public readonly ?string $model = null,
        public readonly int $inputTokens = 0,
        public readonly int $cachedInputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
