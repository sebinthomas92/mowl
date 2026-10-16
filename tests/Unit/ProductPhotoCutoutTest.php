<?php

namespace Tests\Unit;

use App\Services\ProductPhotoCutout;
use Tests\TestCase;

class ProductPhotoCutoutTest extends TestCase
{
    public function test_a_studio_shot_keeps_the_products_own_pixels_and_drops_the_backdrop_and_its_shadow(): void
    {
        $cutter = new ProductPhotoCutout;
        $png = $cutter->cut(self::studio());

        $this->assertNotNull($png, (string) $cutter->reason);
        $cutout = imagecreatefromstring($png);
        // Trimmed to the stand (pages and base), not the shadow below it.
        $this->assertEqualsWithDelta(327, imagesx($cutout), 12);
        $this->assertEqualsWithDelta(125, imagesy($cutout), 10);
        $centre = imagecolorat($cutout, intdiv(imagesx($cutout), 2), imagesy($cutout) - 15);
        $this->assertSame(0, ($centre >> 24) & 127);
        // The red-orange base keeps its colour (JPEG noise aside).
        $this->assertEqualsWithDelta(214, ($centre >> 16) & 255, 8);
        $this->assertEqualsWithDelta(72, ($centre >> 8) & 255, 8);
        $corner = imagecolorat($cutout, 0, 0);
        $this->assertSame(127, ($corner >> 24) & 127);
    }

    public function test_a_grey_product_on_a_white_backdrop_is_not_taken_for_a_shadow(): void
    {
        $image = imagecreatetruecolor(400, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, 120, 120, 280, 260, imagecolorallocate($image, 170, 170, 170));
        imagefilledrectangle($image, 110, 260, 290, 290, imagecolorallocate($image, 214, 72, 38));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        // The grey body and the red base, not the base alone.
        $this->assertEqualsWithDelta(171, getimagesizefromstring($png)[1], 4);
    }

    public function test_a_styled_scene_or_a_set_with_props_is_not_cut_out(): void
    {
        $cutter = new ProductPhotoCutout;

        $busy = imagecreatetruecolor(400, 400);
        for ($x = 0; $x < 400; $x += 20) {
            imagefilledrectangle($busy, $x, 0, $x + 9, 399, imagecolorallocate($busy, 140, 100, 60));
        }
        $this->assertNull($cutter->cut(self::encode($busy)));
        $this->assertSame('not a plain backdrop', $cutter->reason);

        $props = self::backdrop();
        imagefilledrectangle($props, 60, 200, 200, 300, imagecolorallocate($props, 214, 72, 38));
        imagefilledellipse($props, 320, 220, 80, 160, imagecolorallocate($props, 70, 110, 90));
        $this->assertNull($cutter->cut(self::encode($props)));
        $this->assertSame('other objects beside the product', $cutter->reason);

        $cropped = self::backdrop();
        imagefilledrectangle($cropped, 100, 200, 399, 300, imagecolorallocate($cropped, 214, 72, 38));
        $this->assertNull($cutter->cut(self::encode($cropped)));
        $this->assertSame('product cropped by the photo edge', $cutter->reason);

        $this->assertNull($cutter->cut('not-an-image'));
    }

    /** A low white stand with page lines on a red-orange base, on a cream backdrop with a soft shadow. */
    public static function studio(): string
    {
        $image = self::backdrop(627);
        for ($y = 400; $y < 460; $y++) {
            for ($x = 100; $x < 527; $x++) {
                $d = (($x - 313) / 190) ** 2 + (($y - 428) / 22) ** 2;
                if ($d < 1) {
                    $f = 1 - 0.35 * (1 - $d);
                    $c = imagecolorat($image, $x, $y);
                    imagesetpixel($image, $x, $y, imagecolorallocate($image, (int) ((($c >> 16) & 255) * $f), (int) ((($c >> 8) & 255) * $f), (int) (($c & 255) * $f)));
                }
            }
        }
        imagefilledrectangle($image, 150, 390, 476, 425, imagecolorallocate($image, 214, 72, 38));
        imagefilledrectangle($image, 165, 300, 461, 390, imagecolorallocate($image, 246, 245, 242));
        for ($k = 0; $k < 5; $k++) {
            imageline($image, 165, 310 + $k * 15, 461, 315 + $k * 15, imagecolorallocate($image, 200, 198, 194));
        }

        return self::encode($image, true);
    }

    private static function backdrop(int $size = 400): \GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y++) {
            $v = (int) (6 * $y / $size);
            imageline($image, 0, $y, $size - 1, $y, imagecolorallocate($image, 241 - $v, 233 - $v, 219 - $v));
        }

        return $image;
    }

    private static function encode(\GdImage $image, bool $jpeg = false): string
    {
        ob_start();
        $jpeg ? imagejpeg($image, null, 85) : imagepng($image);

        return (string) ob_get_clean();
    }
}
