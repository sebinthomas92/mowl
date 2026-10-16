<?php

namespace Tests\Unit;

use App\Exceptions\UnsafeSourceUrlException;
use App\Services\ProductPageFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductPageFetcherTest extends TestCase
{
    public function test_it_extracts_normalized_product_truth_and_a_content_hash(): void
    {
        $html = <<<'HTML'
<html><head><title>Canvas Tote</title><meta name="description" content="An everyday carry tote."><meta property="og:image" content="/media/tote-hero.webp"><link rel="canonical" href="/products/tote"><script type="application/ld+json">{"@type":"Product","name":"Canvas Tote","sku":"TOTE-1","image":"/products/tote-detail.png","brand":{"name":"Harbor"},"offers":{"price":"89","priceCurrency":"USD"}}</script></head><body><script>ignore me</script><h1>Canvas Tote</h1><img src="/images/logo.png"><img srcset="/products/tote-small.jpg 400w, /products/tote-large.jpg 1200w"><p>Roomy and easy to carry.</p></body></html>
HTML;
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $page = app(ProductPageFetcher::class)->fetch('https://93.184.216.34/products/tote');

        $this->assertSame('Canvas Tote', $page['title']);
        $this->assertSame('https://93.184.216.34/products/tote', $page['canonical_url']);
        $this->assertSame('TOTE-1', $page['product_truth']['sku']);
        $this->assertSame('89', $page['product_truth']['price']);
        // The product's own photo comes before the page's share image.
        $this->assertSame([
            'https://93.184.216.34/products/tote-detail.png',
            'https://93.184.216.34/media/tote-hero.webp',
            'https://93.184.216.34/products/tote-large.jpg',
        ], $page['images']);
        $this->assertSame(['https://93.184.216.34/products/tote-detail.png'], $page['gallery_images']);
        $this->assertStringContainsString('Roomy and easy to carry.', $page['content']);
        $this->assertStringNotContainsString('ignore me', $page['content']);
        $this->assertSame(hash('sha256', $html), $page['content_hash']);
    }

    public function test_woocommerce_gallery_photos_are_the_products_own_and_come_before_the_share_image(): void
    {
        $html = <<<'HTML'
<html><head><title>Kindle Stand</title><meta property="og:image" content="/wp-content/uploads/styled-scene.webp"></head><body>
<div class="woocommerce-product-gallery">
<div data-thumb="/wp-content/uploads/stand-1-100x100.jpg" class="woocommerce-product-gallery__image"><a href="/wp-content/uploads/stand-1.jpg"><img src="/wp-content/uploads/stand-1-600x600.jpg" data-large_image="/wp-content/uploads/stand-1.jpg"></a></div>
<div data-thumb="/wp-content/uploads/studio-100x100.jpg" class="woocommerce-product-gallery__image"><a href="/wp-content/uploads/studio.jpg"><img src="/wp-content/uploads/studio-600x600.jpg"></a></div>
</div>
<div class="pdp-gallery__thumbs"><button class="pdp-gallery__thumb" data-idx="2" data-full-src="/wp-content/uploads/set-1.jpg"><img src="https://cdn.example/set-1-300x300.jpg"></button></div>
<section class="related products"><div class="card-gallery"><button data-full-src="/wp-content/uploads/other-product.jpg"><img src="/wp-content/uploads/other-product.jpg"></button></div></section>
<div class="RelatedProducts"><div class="ProductGallery"><button data-full-src="/wp-content/uploads/capitalised-related.jpg"></button></div></div>
<div class="quick-view"><button data-full-src="/wp-content/uploads/not-in-a-gallery.jpg"></button></div>
</body></html>
HTML;
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $page = app(ProductPageFetcher::class)->fetch('https://93.184.216.34/product/kindle-stand');

        $this->assertSame([
            'https://93.184.216.34/wp-content/uploads/stand-1.jpg',
            'https://93.184.216.34/wp-content/uploads/studio.jpg',
            'https://93.184.216.34/wp-content/uploads/set-1.jpg',
        ], $page['gallery_images']);
        $this->assertSame([
            'https://93.184.216.34/wp-content/uploads/stand-1.jpg',
            'https://93.184.216.34/wp-content/uploads/studio.jpg',
            'https://93.184.216.34/wp-content/uploads/set-1.jpg',
            'https://93.184.216.34/wp-content/uploads/styled-scene.webp',
        ], array_slice($page['images'], 0, 4));
    }

    public function test_a_full_gallery_leaves_room_for_the_share_image(): void
    {
        $gallery = implode('', array_map(fn (int $i) => "<button data-full-src=\"/uploads/photo-{$i}.jpg\"></button>", range(1, 15)));
        $html = '<html><head><title>Stand</title><meta property="og:image" content="/uploads/share.webp"></head><body><div class="product-gallery">'.$gallery.'</div></body></html>';
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $page = app(ProductPageFetcher::class)->fetch('https://93.184.216.34/product/stand');

        $this->assertContains('https://93.184.216.34/uploads/share.webp', $page['images']);
        $this->assertSame('https://93.184.216.34/uploads/photo-1.jpg', $page['images'][0]);
    }

    public function test_it_downloads_and_validates_a_product_image(): void
    {
        $bytes = $this->imageBytes();
        Http::fake([
            '93.184.216.34/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $image = app(ProductPageFetcher::class)->fetchImage('https://93.184.216.34/media/product.png');

        $this->assertSame('image/png', $image['mime_type']);
        $this->assertSame([600, 800], [$image['width'], $image['height']]);
        $this->assertSame($bytes, $image['bytes']);
    }

    public function test_it_blocks_private_and_local_source_urls(): void
    {
        $this->expectException(UnsafeSourceUrlException::class);

        app(ProductPageFetcher::class)->assertSafeUrl('http://127.0.0.1/private-product');
    }

    public function test_it_blocks_non_web_ports(): void
    {
        $this->expectException(UnsafeSourceUrlException::class);

        app(ProductPageFetcher::class)->assertSafeUrl('https://93.184.216.34:8080/product');
    }

    private function imageBytes(): string
    {
        $image = imagecreatetruecolor(600, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 218, 173, 115));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
