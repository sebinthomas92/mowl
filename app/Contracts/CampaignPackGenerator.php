<?php

namespace App\Contracts;

use App\Data\GenerationResult;
use App\Models\Product;
use App\Models\SourceSnapshot;

interface CampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult;
}
