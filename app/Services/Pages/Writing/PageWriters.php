<?php

namespace App\Services\Pages\Writing;

/** The configured page writer: Gemini on Vertex AI in production, the mock writer elsewhere. */
class PageWriters
{
    public function current(): PageWriter
    {
        return match (config('pages.writer')) {
            'google' => app(GooglePageWriter::class),
            default => app(MockPageWriter::class),
        };
    }
}
