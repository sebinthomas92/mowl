<?php

namespace App\Services\Pages\Writing;

use App\Services\Pages\Document\ProductBrief;

/** Writes page copy from a product brief. See {@see PageCopy} for what it may write. */
interface PageWriter
{
    /**
     * @param  list<string>  $targets  ids of the sections to write
     * @param  string  $instruction  the team's direction for tone and emphasis, never a source of facts
     */
    public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting;
}
