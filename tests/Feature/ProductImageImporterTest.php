<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Services\ProductImageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_thumbnail_listed_first_gives_way_to_a_full_size_photo(): void
    {
        [$product, $source] = $this->fixture();
        Http::fake([
            '93.184.216.34/uploads/thumb.jpg' => Http::response(self::png(150, 150), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/full.jpg' => Http::response(self::png(900, 900), 200, ['Content-Type' => 'image/png']),
        ]);

        $asset = app(ProductImageImporter::class)->importFirst($product, $source, [
            'https://93.184.216.34/uploads/thumb.jpg',
            'https://93.184.216.34/uploads/full.jpg',
        ]);

        $this->assertSame('https://93.184.216.34/uploads/full.jpg', $asset->metadata['source_url']);
        $this->assertSame(2, $asset->metadata['candidate_rank']);
    }

    public function test_a_thumbnail_is_still_used_when_nothing_larger_downloads(): void
    {
        [$product, $source] = $this->fixture();
        Http::fake([
            '93.184.216.34/uploads/thumb.jpg' => Http::response(self::png(150, 150), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/missing.jpg' => Http::response('', 404),
        ]);

        $asset = app(ProductImageImporter::class)->importFirst($product, $source, [
            'https://93.184.216.34/uploads/thumb.jpg',
            'https://93.184.216.34/uploads/missing.jpg',
        ]);

        $this->assertSame('https://93.184.216.34/uploads/thumb.jpg', $asset->metadata['source_url']);
        $this->assertSame(150, $asset->metadata['width']);
    }

    public function test_the_search_past_a_thumbnail_is_kept_short(): void
    {
        [$product, $source] = $this->fixture();
        Http::fake([
            '93.184.216.34/uploads/thumb.jpg' => Http::response(self::png(150, 150), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/full.jpg' => Http::response(self::png(900, 900), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/*' => Http::response('', 404),
        ]);
        $urls = ['https://93.184.216.34/uploads/thumb.jpg'];
        foreach (range(1, 6) as $i) {
            $urls[] = "https://93.184.216.34/uploads/gone-{$i}.jpg";
        }
        $urls[] = 'https://93.184.216.34/uploads/full.jpg';

        $asset = app(ProductImageImporter::class)->importFirst($product, $source, $urls);

        $this->assertSame('https://93.184.216.34/uploads/thumb.jpg', $asset->metadata['source_url']);
        Http::assertSentCount(4);
    }

    public function test_a_photo_too_large_to_decode_is_skipped(): void
    {
        [$product, $source] = $this->fixture();
        // A flat 4000 x 4000 PNG is a small download but 16 megapixels.
        Http::fake([
            '93.184.216.34/uploads/huge.png' => Http::response(self::png(4000, 4000), 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/uploads/full.jpg' => Http::response(self::png(900, 900), 200, ['Content-Type' => 'image/png']),
        ]);

        $asset = app(ProductImageImporter::class)->importFirst($product, $source, [
            'https://93.184.216.34/uploads/huge.png',
            'https://93.184.216.34/uploads/full.jpg',
        ]);

        $this->assertSame('https://93.184.216.34/uploads/full.jpg', $asset->metadata['source_url']);
    }

    private function fixture(): array
    {
        Storage::fake(config('campaigns.banners.disk'));
        $workspace = Workspace::create(['name' => 'Import Agency']);
        $product = $workspace->brands()->create(['name' => 'Brand'])->products()->create(['name' => 'Stand']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://93.184.216.34/product/stand',
            'content_hash' => hash('sha256', 'source'),
            'status' => 'ready',
        ]);

        return [$product, $source];
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 218, 173, 115));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
