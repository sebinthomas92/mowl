<?php

namespace Tests\Unit;

use App\Services\BannerImageInspector;
use Tests\TestCase;

class BannerImageInspectorFlattenTest extends TestCase
{
    public function test_a_transparent_black_product_is_sent_on_a_light_backdrop(): void
    {
        $image = imagecreatetruecolor(300, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 200, 200, imagecolorallocatealpha($image, 10, 10, 10, 0));
        ob_start();
        imagepng($image);

        [$mimeType, $bytes] = (new \ReflectionMethod(BannerImageInspector::class, 'smaller'))
            ->invoke(app(BannerImageInspector::class), 'image/png', (string) ob_get_clean());

        $this->assertSame('image/jpeg', $mimeType);
        $sent = imagecreatefromstring($bytes);
        $this->assertGreaterThan(200, imagecolorat($sent, 20, 20) & 255);
        $this->assertLessThan(40, imagecolorat($sent, 150, 150) & 255);
    }
}
