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
        // The product's own colour at the slot, not a redrawn one, and no green anywhere around it.
        $this->assertSame([210, 70, 40], $rgb($slot['x'], $slot['base'] - 60));
        $this->assertSame([40, 120, 200], $rgb(60, 200));
    }

    public function test_a_keyed_image_that_lost_the_product_or_its_key_is_not_a_cutout(): void
    {
        $references = app(BannerReferenceImage::class);
        $this->assertNull($references->cutout($this->image(100, 100, [0, 255, 0])));
        $this->assertNull($references->cutout($this->image(100, 100, [200, 60, 40])));
        $this->assertNull($references->cutout('not-an-image'));
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
