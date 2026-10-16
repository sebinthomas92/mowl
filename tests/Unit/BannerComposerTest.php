<?php

namespace Tests\Unit;

use App\Models\BannerCreative;
use App\Models\Brand;
use App\Services\BannerComposer;
use App\Services\BannerReferenceImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerComposerTest extends TestCase
{
    public function test_it_composes_valid_long_copy_with_brand_color_logo_and_wordmark_fallback(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('logo.png', $this->image(300, 90, [255, 255, 255]));
        $creative = new BannerCreative([
            'layout' => 'split_left',
            'headline' => 'A deliberately long approved campaign headline that must remain fully visible without clipping at the canvas edge',
            'supporting_text' => 'Approved supporting copy remains verbatim while the compositor adjusts its size and line breaks.',
            'cta' => 'Shop now',
        ]);
        $composer = app(BannerComposer::class);
        $background = $this->image(800, 1000, [70, 92, 115]);

        $wordmark = $composer->compose($background, new Brand(['name' => 'Exact Brand', 'primary_color' => '#F4B942']), $creative);
        $logo = $composer->compose($background, new Brand([
            'name' => 'Exact Brand',
            'primary_color' => '#123456',
            'banner_logo_disk' => 'local',
            'banner_logo_path' => 'logo.png',
        ]), $creative);

        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring($wordmark), 0, 2));
        $this->assertSame('image/png', getimagesizefromstring($logo)['mime']);
        $this->assertNotSame(hash('sha256', $wordmark), hash('sha256', $logo));
    }

    public function test_the_real_product_cutout_is_placed_in_its_slot_with_the_scene_showing_through_the_key(): void
    {
        // A red-orange product on a green key: the key becomes transparent and is trimmed away.
        $keyed = imagecreatetruecolor(300, 200);
        imagefill($keyed, 0, 0, imagecolorallocate($keyed, 0, 255, 0));
        imagefilledrectangle($keyed, 50, 40, 249, 159, imagecolorallocate($keyed, 210, 70, 40));
        ob_start();
        imagepng($keyed);
        $cutout = app(BannerReferenceImage::class)->cutout((string) ob_get_clean());
        $this->assertNotNull($cutout);
        $this->assertSame([200, 120], array_slice(getimagesizefromstring((string) $cutout), 0, 2));

        $banner = imagecreatefromstring(app(BannerComposer::class)->compose(
            $this->image(1080, 1350, [40, 120, 200]),
            new Brand(['name' => 'Exact Brand', 'primary_color' => '#F4B942']),
            new BannerCreative(['layout' => 'bottom_panel', 'headline' => 'A stand', 'cta' => 'Shop now']),
            $cutout,
        ));
        $slot = BannerComposer::productSlot('bottom_panel');
        $rgb = fn (int $x, int $y) => [(imagecolorat($banner, $x, $y) >> 16) & 255, (imagecolorat($banner, $x, $y) >> 8) & 255, imagecolorat($banner, $x, $y) & 255];
        // The product's own colour at the slot (lightly matched to the scene), not a redrawn one, and no
        // green anywhere around it.
        foreach ([210, 70, 40] as $channel => $value) {
            $this->assertEqualsWithDelta($value, $rgb($slot['x'], $slot['base'] - 60)[$channel], $value * 0.08);
        }
        $this->assertSame([40, 120, 200], $rgb(60, 200));
    }

    public function test_a_bright_studio_product_is_dimmed_toward_a_darker_scene_within_limits(): void
    {
        $product = imagecreatetruecolor(200, 120);
        imagefill($product, 0, 0, imagecolorallocate($product, 245, 245, 245));
        ob_start();
        imagepng($product);
        $cutout = (string) ob_get_clean();

        $banner = imagecreatefromstring(app(BannerComposer::class)->compose(
            $this->image(1080, 1350, [60, 60, 60]),
            new Brand(['name' => 'Exact Brand', 'primary_color' => '#F4B942']),
            new BannerCreative(['layout' => 'bottom_panel', 'headline' => 'A stand', 'cta' => 'Shop now']),
            $cutout,
        ));
        $slot = BannerComposer::productSlot('bottom_panel');
        $red = (imagecolorat($banner, $slot['x'], $slot['base'] - 100) >> 16) & 255;

        // Dimmed so it does not glow against the scene, but never by more than about a fifth.
        $this->assertLessThan(240, $red);
        $this->assertGreaterThanOrEqual((int) floor(245 * 0.82 * 0.95), $red);
    }

    public function test_the_product_stands_on_the_scenes_own_resting_spot_with_a_contact_shadow_under_its_base(): void
    {
        $product = imagecreatetruecolor(300, 200);
        imagefill($product, 0, 0, imagecolorallocate($product, 210, 70, 40));
        ob_start();
        imagepng($product);
        $cutout = (string) ob_get_clean();

        // The scene is 896x1120 (4:5), so its shares map straight onto the 1080x1350 canvas.
        $scene = $this->image(896, 1120, [200, 170, 130]);
        $banner = imagecreatefromstring(app(BannerComposer::class)->compose(
            $scene,
            new Brand(['name' => 'Exact Brand', 'primary_color' => '#F4B942']),
            new BannerCreative(['layout' => 'split_left', 'headline' => 'A stand', 'cta' => 'Shop now']),
            $cutout,
            ['x' => 0.76, 'y' => 0.7, 'width' => 0.45],
        ));
        $red = fn (int $x, int $y) => (imagecolorat($banner, $x, $y) >> 16) & 255;
        [$x, $base] = [(int) round(0.76 * 1080), (int) round(0.7 * 1350)];

        // The product's base sits on the spot, not on the layout's fixed slot.
        $this->assertGreaterThan(150, $red($x, $base - 5));
        $this->assertNotSame(BannerComposer::productSlot('split_left')['base'], $base);
        // Right under the base the surface is darkened by the contact shadow, and further down it is not.
        $this->assertLessThan($red($x, $base + 60) - 25, $red($x, $base + 2));
    }

    public function test_a_resting_spot_far_from_the_slot_or_under_the_copy_falls_back_to_the_slot(): void
    {
        $slot = BannerComposer::productSlot('split_left');
        $fallback = BannerComposer::placement('split_left', null, 300, 200);
        $this->assertSame([$slot['x'], $slot['base']], [$fallback['x'], $fallback['base']]);

        // Far away from where the scene was asked to leave it.
        $this->assertSame($fallback, BannerComposer::placement('split_left', ['x' => 820, 'y' => 300, 'width' => 400], 300, 200));
        // Close enough, but the product would cover the headline column.
        $this->assertSame($fallback, BannerComposer::placement('split_left', ['x' => 640, 'y' => 900, 'width' => 400], 300, 200));
        // Near the slot and clear of the copy: used, and sized to the surface within limits.
        $placed = BannerComposer::placement('split_left', ['x' => 810, 'y' => 940, 'width' => 600], 300, 200);
        $this->assertSame([810, 940, 480], [$placed['x'], $placed['base'], $placed['width']]);
        // A wide surface never grows the product past a fifth over the slot's size.
        $wide = BannerComposer::placement('bottom_panel', ['x' => 540, 'y' => 820, 'width' => 1000], 300, 200);
        $this->assertSame((int) round(BannerComposer::placement('bottom_panel', null, 300, 200)['width'] * 1.2), $wide['width']);
    }

    public function test_a_keyed_image_that_lost_the_product_or_its_key_is_not_a_cutout(): void
    {
        $references = app(BannerReferenceImage::class);
        $this->assertNull($references->cutout($this->image(100, 100, [0, 255, 0])));
        $this->assertNull($references->cutout($this->image(100, 100, [200, 60, 40])));
        $this->assertNull($references->cutout('not-an-image'));
        // A grey floor left under the product: the key no longer reaches the image's edges.
        $floor = imagecreatetruecolor(100, 100);
        imagefill($floor, 0, 0, imagecolorallocate($floor, 0, 255, 0));
        imagefilledrectangle($floor, 0, 60, 99, 99, imagecolorallocate($floor, 180, 180, 180));
        imagefilledrectangle($floor, 30, 30, 69, 59, imagecolorallocate($floor, 240, 240, 240));
        ob_start();
        imagepng($floor);
        $this->assertNull($references->cutout((string) ob_get_clean()));
    }

    public function test_a_palette_image_is_keyed_by_colour_and_the_products_own_green_is_kept(): void
    {
        $keyed = imagecreate(40, 40);
        imagecolorallocate($keyed, 0, 255, 0);
        imagefilledrectangle($keyed, 10, 10, 29, 29, imagecolorallocate($keyed, 120, 150, 110));
        ob_start();
        imagepng($keyed);
        $cutout = imagecreatefromstring((string) app(BannerReferenceImage::class)->cutout((string) ob_get_clean()));

        $this->assertSame([20, 20], [imagesx($cutout), imagesy($cutout)]);
        $pixel = imagecolorat($cutout, 10, 10);
        $this->assertSame([0, 120, 150, 110], [($pixel >> 24) & 127, ($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255]);
    }

    private function image(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
