<?php

namespace App\Contracts;

use App\Data\BannerGenerationResult;
use App\Models\BannerCreative;
use Illuminate\Support\Collection;

interface BannerGenerator
{
    public function generate(BannerCreative $creative, Collection $productImages): BannerGenerationResult;
}
