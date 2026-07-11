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
<html><head><title>Canvas Tote</title><meta name="description" content="An everyday carry tote."><link rel="canonical" href="/products/tote"><script type="application/ld+json">{"@type":"Product","name":"Canvas Tote","sku":"TOTE-1","brand":{"name":"Harbor"},"offers":{"price":"89","priceCurrency":"USD"}}</script></head><body><script>ignore me</script><h1>Canvas Tote</h1><p>Roomy and easy to carry.</p></body></html>
HTML;
        Http::fake(['93.184.216.34/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $page = app(ProductPageFetcher::class)->fetch('https://93.184.216.34/products/tote');

        $this->assertSame('Canvas Tote', $page['title']);
        $this->assertSame('https://93.184.216.34/products/tote', $page['canonical_url']);
        $this->assertSame('TOTE-1', $page['product_truth']['sku']);
        $this->assertSame('89', $page['product_truth']['price']);
        $this->assertStringContainsString('Roomy and easy to carry.', $page['content']);
        $this->assertStringNotContainsString('ignore me', $page['content']);
        $this->assertSame(hash('sha256', $html), $page['content_hash']);
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
}
