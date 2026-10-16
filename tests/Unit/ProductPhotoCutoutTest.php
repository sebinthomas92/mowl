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

    public function test_a_wide_antialiased_outline_is_feathered_all_the_way_in(): void
    {
        $image = imagecreatetruecolor(600, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, 180, 180, 420, 420, imagecolorallocate($image, 20, 20, 20));
        // A three-pixel blend from backdrop to product on the left edge.
        foreach ([177 => 235, 178 => 215, 179 => 195] as $x => $grey) {
            imageline($image, $x, 180, $x, 420, imagecolorallocate($image, $grey, $grey, $grey));
        }

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $cutout = imagecreatefromstring($png);
        // No light pixel along the outline stays fully opaque: it would show as a pale line on a dark scene.
        for ($x = 0; $x < 8; $x++) {
            $c = imagecolorat($cutout, $x, 120);
            $this->assertFalse((($c >> 24) & 127) === 0 && (($c >> 16) & 255) > 120, "pixel {$x} is an opaque light fringe");
        }
    }

    public function test_a_store_size_photo_is_scaled_down_and_cut_out(): void
    {
        // Store gallery photos are usually over 1000px, so they always go through the scaling step.
        $large = imagescale(imagecreatefromstring(self::studio()), 1254, 1254, IMG_NEAREST_NEIGHBOUR);
        ob_start();
        imagejpeg($large, null, 92);

        $cutter = new ProductPhotoCutout;
        $png = $cutter->cut((string) ob_get_clean());

        $this->assertNotNull($png, (string) $cutter->reason);
        $this->assertLessThanOrEqual(1000, max(array_slice(getimagesizefromstring($png), 0, 2)));
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

    public function test_a_transparent_png_with_a_slim_margin_is_not_too_large(): void
    {
        // A package filling over 85% of the canvas: the clear margin still separates it.
        $image = imagecreatetruecolor(300, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 5, 5, 294, 294, imagecolorallocatealpha($image, 200, 60, 40, 0));

        $png = (new ProductPhotoCutout)->cut(self::encode($image));

        $this->assertNotNull($png);
        $this->assertSame([580, 580], array_slice(getimagesizefromstring($png), 0, 2));
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

        // Speckle in thousands of separate pieces stops early instead of tracking every piece.
        $speckled = self::backdrop();
        for ($y = 20; $y < 380; $y += 6) {
            for ($x = 20; $x < 380; $x += 6) {
                imagesetpixel($speckled, $x, $y, imagecolorallocate($speckled, 20, 20, 20));
            }
        }
        $this->assertNull($cutter->cut(self::encode($speckled)));
        $this->assertSame('not a plain backdrop', $cutter->reason);

        $this->assertNull($cutter->cut('not-an-image'));
    }

    public function test_lit_pages_on_a_backdrop_that_darkens_towards_the_top_stay_one_product(): void
    {
        // The Kindle Stand's studio shot: a beige backdrop lit from below, and white pages split by a
        // shaded spine, only a little lighter than the backdrop. Against one median backdrop colour the
        // tolerance took in the pages, and the stand fell apart into "other objects".
        $image = imagecreatetruecolor(627, 627);
        for ($y = 0; $y < 627; $y++) {
            $t = $y / 626;
            imageline($image, 0, $y, 626, $y, imagecolorallocate($image, (int) (184 + 44 * $t), (int) (164 + 44 * $t), (int) (140 + 44 * $t)));
        }
        imagefilledrectangle($image, 150, 400, 476, 430, imagecolorallocate($image, 214, 72, 38));
        imagefilledrectangle($image, 165, 300, 309, 400, imagecolorallocate($image, 236, 227, 221));
        imagefilledrectangle($image, 317, 300, 461, 400, imagecolorallocate($image, 238, 229, 222));
        // The spine and a highlight band: page-white shaded towards the backdrop.
        imagefilledrectangle($image, 310, 300, 316, 400, imagecolorallocate($image, 218, 202, 188));
        imagefilledrectangle($image, 165, 345, 461, 349, imagecolorallocate($image, 220, 205, 192));

        $cutter = new ProductPhotoCutout;
        $png = $cutter->cut(self::encode($image, true));

        $this->assertNotNull($png, (string) $cutter->reason);
        $cutout = imagecreatefromstring($png);
        // Both page blocks and the base, as one piece.
        $this->assertEqualsWithDelta(327, imagesx($cutout), 12);
        $this->assertEqualsWithDelta(131, imagesy($cutout), 6);
        $spine = imagecolorat($cutout, 313 - 150, 330 - 300);
        $this->assertSame(0, ($spine >> 24) & 127);
    }

    public function test_shading_inside_the_product_stays_while_its_cast_shadow_goes(): void
    {
        // The spine gutter between the stand's pages is shaded in the backdrop's own tint and opens
        // onto the backdrop at the top, so the shadow fill reached in and cut the stand apart.
        $image = imagecreatefromstring(self::studio());
        for ($y = 300; $y < 390; $y++) {
            // Darkening gradually from the backdrop's colour at the top, as a soft shadow does.
            $f = 1 - 0.25 * ($y - 300) / 89;
            imageline($image, 305, $y, 321, $y, imagecolorallocate($image, (int) (240 * $f), (int) (232 * $f), (int) (218 * $f)));
        }

        // A lit slit near the backdrop's colour, opening at the top of the pages.
        imagefilledrectangle($image, 379, 290, 386, 330, imagecolorallocate($image, 232, 222, 206));
        // A wider fold within the backdrop tolerance (but not as close as an opening), from the top edge.
        imagefilledrectangle($image, 410, 290, 433, 370, imagecolorallocate($image, 232, 222, 206));

        $cutter = new ProductPhotoCutout;
        $png = $cutter->cut(self::encode($image, true));

        $this->assertNotNull($png, (string) $cutter->reason);
        $cutout = imagecreatefromstring($png);
        $this->assertSame(0, (imagecolorat($cutout, 382 - 150, 25) >> 24) & 127, 'slit');
        $this->assertSame(0, (imagecolorat($cutout, 421 - 150, 50) >> 24) & 127, 'fold');
        // Still trimmed to the stand, not the shadow below it.
        $this->assertEqualsWithDelta(125, imagesy($cutout), 10);
        foreach ([45, 65, 85] as $y) {
            $this->assertSame(0, (imagecolorat($cutout, 313 - 150, $y) >> 24) & 127, "gutter row {$y}");
        }
    }

    public function test_a_narrow_slot_open_to_the_backdrop_stays_clear(): void
    {
        // A U-shaped stand: the backdrop between its arms is real backdrop, not a lit surface.
        $image = self::backdrop();
        $red = imagecolorallocate($image, 214, 72, 38);
        imagefilledrectangle($image, 120, 100, 180, 340, $red);
        imagefilledrectangle($image, 200, 100, 260, 340, $red);
        imagefilledrectangle($image, 120, 300, 260, 340, $red);
        // And a slit a few pixels wide, cut into one arm from the top.
        imagefilledrectangle($image, 228, 100, 229, 200, imagecolorat($image, 228, 99));

        $cutter = new ProductPhotoCutout;
        $png = $cutter->cut(self::encode($image));

        $this->assertNotNull($png, (string) $cutter->reason);
        $cutout = imagecreatefromstring($png);
        $this->assertSame(127, (imagecolorat($cutout, (190 - 120) * 2, (150 - 100) * 2) >> 24) & 127);
        $this->assertSame(0, (imagecolorat($cutout, (150 - 120) * 2, (150 - 100) * 2) >> 24) & 127);
        $this->assertSame(127, (imagecolorat($cutout, (228 - 120) * 2 + 1, (150 - 100) * 2) >> 24) & 127);
    }

    public function test_a_corner_grazing_the_frame_is_kept_but_a_cropped_side_is_not(): void
    {
        $graze = self::backdrop();
        imagefilledrectangle($graze, 100, 150, 380, 260, imagecolorallocate($graze, 214, 72, 38));
        // The base's corner runs flush to the frame for a few rows.
        imagefilledrectangle($graze, 381, 250, 399, 251, imagecolorallocate($graze, 214, 72, 38));
        $this->assertNotNull((new ProductPhotoCutout)->cut(self::encode($graze)));

        $cropped = self::backdrop();
        imagefilledrectangle($cropped, 100, 150, 399, 230, imagecolorallocate($cropped, 214, 72, 38));
        $cutter = new ProductPhotoCutout;
        $this->assertNull($cutter->cut(self::encode($cropped)));
        $this->assertSame('product cropped by the photo edge', $cutter->reason);
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
