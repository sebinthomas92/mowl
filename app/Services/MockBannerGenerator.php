<?php

namespace App\Services;

use App\Contracts\BannerGenerator;
use App\Data\BannerGenerationResult;
use App\Models\BannerCreative;
use Illuminate\Support\Collection;
use RuntimeException;

class MockBannerGenerator implements BannerGenerator
{
    public function generate(BannerCreative $creative, Collection $productImages): BannerGenerationResult
    {
        $image = imagecreatetruecolor(800, 1000);
        if (! $image) {
            throw new RuntimeException('GD could not create the mock banner background.');
        }

        [$start, $end] = match ($creative->layout) {
            'split_left' => [[32, 45, 66], [181, 135, 108]],
            'bottom_panel' => [[20, 70, 64], [188, 165, 99]],
            default => [[28, 48, 76], [164, 120, 156]],
        };

        for ($y = 0; $y < 1000; $y++) {
            $ratio = $y / 999;
            $color = imagecolorallocate(
                $image,
                (int) ($start[0] + (($end[0] - $start[0]) * $ratio)),
                (int) ($start[1] + (($end[1] - $start[1]) * $ratio)),
                (int) ($start[2] + (($end[2] - $start[2]) * $ratio)),
            );
            imageline($image, 0, $y, 799, $y, $color);
        }

        $highlight = imagecolorallocatealpha($image, 255, 255, 255, 92);
        imagefilledellipse($image, 555, 440, 460, 590, $highlight);
        imagefilledellipse($image, 590, 420, 300, 430, imagecolorallocatealpha($image, 255, 255, 255, 80));

        ob_start();
        imagepng($image, null, 8);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! is_string($bytes)) {
            throw new RuntimeException('GD could not encode the mock banner background.');
        }

        return new BannerGenerationResult(
            imageBytes: $bytes,
            mimeType: 'image/png',
            provider: 'mock',
            providerRequestId: 'mock-'.$creative->id,
            providerLatencyMs: 1,
        );
    }
}
