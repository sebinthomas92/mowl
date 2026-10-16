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
        $this->assertEqualsWithDelta(342, getimagesizefromstring($png)[1], 8);
    }

    public function test_a_same_hue_part_and_a_thin_antenna_stay_with_the_product(): void
    {
        $image = self::backdrop(627);
        // A beige lid with the cream backdrop's own tint, only darker: a sharp outline, not a soft shadow.
        imagefilledrectangle($image, 220, 260, 400, 330, imagecolorallocate($image, 181, 175, 164));
        imagefilledrectangle($image, 180, 330, 440, 420, imagecolorallocate($image, 214, 72, 38));
        // A one-pixel antenna standing on the lid.
        imageline($image, 310, 200, 310, 259, imagecolorallocate($image, 40, 40, 40));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $this->assertEqualsWithDelta(221, getimagesizefromstring($png)[1], 4);
    }

    public function test_backdrop_seen_through_a_handle_is_made_clear(): void
    {
        $image = self::backdrop();
        imagefilledrectangle($image, 100, 150, 220, 300, imagecolorallocate($image, 214, 72, 38));
        // A closed handle on the right with backdrop inside it.
        imagesetthickness($image, 10);
        imagerectangle($image, 220, 180, 300, 260, imagecolorallocate($image, 214, 72, 38));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $cutout = imagecreatefromstring($png);
        // Inside the handle (around x 260, y 220 before trimming and doubling) is clear; the body is solid.
        $this->assertSame(127, (imagecolorat($cutout, 2 * (260 - 100), 2 * (220 - 150)) >> 24) & 127);
        $this->assertSame(0, (imagecolorat($cutout, 2 * (160 - 100), 2 * (220 - 150)) >> 24) & 127);
    }

    public function test_a_light_dial_inside_a_frame_is_not_taken_for_an_opening(): void
    {
        $image = self::backdrop();
        imagefilledrectangle($image, 120, 120, 280, 280, imagecolorallocate($image, 60, 50, 45));
        // A light dial a shade darker than the cream backdrop: within its tolerance, but not the backdrop itself.
        imagefilledellipse($image, 200, 200, 120, 120, imagecolorallocate($image, 231, 223, 209));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $this->assertSame(0, (imagecolorat(imagecreatefromstring($png), 2 * 80, 2 * 80) >> 24) & 127);
    }

    public function test_soft_edges_lose_the_backdrops_colour(): void
    {
        $image = imagecreatetruecolor(400, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 240, 230));
        imagefilledrectangle($image, 120, 120, 280, 280, imagecolorallocate($image, 30, 30, 30));
        // An antialiased column on the left edge, mostly backdrop with a little of the product in it.
        imageline($image, 119, 120, 119, 280, imagecolorallocate($image, 215, 210, 201));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $edge = imagecolorat(imagecreatefromstring($png), 0, 100);
        // Partly clear, and darker than the light blend it was: the backdrop's share is taken out.
        $this->assertGreaterThan(0, ($edge >> 24) & 127);
        $this->assertLessThan(127, ($edge >> 24) & 127);
        $this->assertLessThan(205, ($edge >> 16) & 255);
    }

    public function test_a_thumbnail_is_not_cut_out(): void
    {
        $cutter = new ProductPhotoCutout;
        $image = imagecreatetruecolor(240, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, 60, 60, 180, 180, imagecolorallocate($image, 214, 72, 38));
        ob_start();
        imagepng($image);

        $this->assertNull($cutter->cut((string) ob_get_clean()));
        $this->assertSame('photo too small', $cutter->reason);
    }

    public function test_a_transparent_png_keeps_its_own_soft_edges(): void
    {
        $image = imagecreatetruecolor(300, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 200, 180, imagecolorallocatealpha($image, 214, 72, 38, 0));
        imagefilledrectangle($image, 99, 100, 99, 180, imagecolorallocatealpha($image, 214, 72, 38, 90));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $cutout = imagecreatefromstring($png);
        $this->assertSame(0, (imagecolorat($cutout, 100, 80) >> 24) & 127);
        // The half-clear edge column stays mostly clear instead of turning solid.
        $this->assertGreaterThanOrEqual(90, (imagecolorat($cutout, 0, 80) >> 24) & 127);
    }

    public function test_a_black_product_on_a_fully_clear_png_is_kept_by_its_alpha(): void
    {
        // Clear pixels often hold black RGB, the same colour as the product.
        $image = imagecreatetruecolor(300, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 200, 180, imagecolorallocatealpha($image, 12, 12, 12, 0));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $this->assertSame([202, 162], array_slice(getimagesizefromstring($png), 0, 2));
        $this->assertSame(0, (imagecolorat(imagecreatefromstring($png), 100, 80) >> 24) & 127);
    }

    public function test_a_camera_jpeg_is_turned_upright_before_cutting(): void
    {
        $image = self::backdrop();
        imagefilledrectangle($image, 120, 160, 280, 240, imagecolorallocate($image, 214, 72, 38));
        $jpeg = self::encode($image, true);
        // An Exif block saying the camera was turned: the photo is shown rotated 90 degrees clockwise.
        $exif = "Exif\0\0MM\0\x2a\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
        $rotated = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $png = (new ProductPhotoCutout)->cut($rotated);

        $this->assertNotNull($png);
        [$width, $height] = getimagesizefromstring($png);
        $this->assertEqualsWithDelta(162, $width, 12);
        $this->assertEqualsWithDelta(322, $height, 12);
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

        // A small detached part (a charm beside a bag) is not dropped from the product without a word.
        $piece = self::backdrop();
        imagefilledrectangle($piece, 60, 200, 260, 300, imagecolorallocate($piece, 214, 72, 38));
        imagefilledellipse($piece, 320, 250, 16, 16, imagecolorallocate($piece, 70, 110, 90));
        $this->assertNull($cutter->cut(self::encode($piece)));
        $this->assertSame('a detached piece beside the product', $cutter->reason);

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

    /** Drawn at 300-400 px for easy coordinates, saved at twice that: smaller photos are thumbnails. */
    private static function encode(\GdImage $image, bool $jpeg = false): string
    {
        if (imagesx($image) < 500) {
            $image = imagescale($image, imagesx($image) * 2, imagesy($image) * 2, IMG_NEAREST_NEIGHBOUR);
            imagesavealpha($image, true);
        }
        ob_start();
        $jpeg ? imagejpeg($image, null, 85) : imagepng($image);

        return (string) ob_get_clean();
    }
}
