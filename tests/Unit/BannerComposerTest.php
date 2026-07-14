<?php

namespace Tests\Unit;

use App\Models\BannerCreative;
use App\Models\Brand;
use App\Services\BannerComposer;
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
