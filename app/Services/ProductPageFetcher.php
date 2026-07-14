<?php

namespace App\Services;

use App\Exceptions\UnsafeSourceUrlException;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductPageFetcher
{
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const SKIP_IMAGE_PATTERN = '/(logo|icon|sprite|pixel|tracking|favicon|placeholder|avatar|badge|flag|payment|stripe|paypal|visa|mastercard|amex|apple-?pay|google-?pay)/i';

    public function fetch(string $url): array
    {
        $currentUrl = $url;
        $response = null;

        for ($redirects = 0; $redirects <= config('campaigns.source.max_redirects'); $redirects++) {
            $binding = $this->publicAddressBinding($currentUrl);
            $options = ['allow_redirects' => false];
            if ($binding) {
                $options['curl'] = [CURLOPT_RESOLVE => [$binding]];
            }

            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('MarketingOwl/1.0 (+campaign-source-snapshot)')
                ->timeout(config('campaigns.source.timeout_seconds'))
                ->withOptions($options)
                ->get($currentUrl);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->header('Location');
            if (! $location) {
                throw new RuntimeException('The source page returned a redirect without a destination.');
            }

            $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        }

        if (! $response || $response->redirect()) {
            throw new RuntimeException('The source page exceeded the redirect limit.');
        }

        $response->throw();
        $this->assertHtmlResponse($response);

        $html = $response->body();
        if (strlen($html) > config('campaigns.source.max_bytes')) {
            throw new RuntimeException('The source page is larger than the supported 2 MB limit.');
        }

        return $this->extract($html, $currentUrl);
    }

    public function fetchImage(string $url): array
    {
        $currentUrl = $url;
        $response = null;

        for ($redirects = 0; $redirects <= config('campaigns.source.max_redirects'); $redirects++) {
            $binding = $this->publicAddressBinding($currentUrl);
            $options = ['allow_redirects' => false];
            if ($binding) {
                $options['curl'] = [CURLOPT_RESOLVE => [$binding]];
            }

            $response = Http::accept('image/webp,image/png,image/jpeg')
                ->withUserAgent('MarketingOwl/1.0 (+campaign-product-image)')
                ->timeout(config('campaigns.source.timeout_seconds'))
                ->withOptions($options)
                ->get($currentUrl);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->header('Location');
            if (! $location) {
                throw new RuntimeException('The product image returned a redirect without a destination.');
            }

            $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        }

        if (! $response || $response->redirect()) {
            throw new RuntimeException('The product image exceeded the redirect limit.');
        }

        $response->throw();
        $bytes = $response->body();
        if (strlen($bytes) > config('campaigns.source.max_image_bytes')) {
            throw new RuntimeException('The product image is larger than the supported limit.');
        }

        $details = @getimagesizefromstring($bytes);
        $mimeType = strtolower((string) ($details['mime'] ?? ''));
        if (! $details || ! in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            throw new RuntimeException('The product image must be a JPEG, PNG, or WebP file.');
        }

        return [
            'url' => $currentUrl,
            'bytes' => $bytes,
            'mime_type' => $mimeType,
            'width' => (int) $details[0],
            'height' => (int) $details[1],
        ];
    }

    public function assertSafeUrl(string $url): void
    {
        $this->publicAddressBinding($url);
    }

    private function publicAddressBinding(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ! in_array($port, [80, 443], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new UnsafeSourceUrlException('Only public HTTP or HTTPS product-page URLs are allowed.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new UnsafeSourceUrlException('Local and internal source URLs are not allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (! $addresses) {
            throw new UnsafeSourceUrlException('The source hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new UnsafeSourceUrlException('Private and reserved network addresses are not allowed.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return "{$host}:{$port}:{$addresses[0]}";
    }

    private function assertHtmlResponse(Response $response): void
    {
        $contentType = strtolower($response->header('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml+xml')) {
            throw new RuntimeException('The source URL did not return an HTML product page.');
        }
    }

    private function extract(string $html, string $resolvedUrl): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('The source page HTML could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        $productData = $this->extractProductJsonLd($xpath);
        $title = $this->nodeText($xpath, '//title') ?: data_get($productData, 'name');
        $description = $this->metaContent($xpath, 'description')
            ?: $this->metaProperty($xpath, 'og:description')
            ?: data_get($productData, 'description');
        $canonical = $xpath->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]')->item(0)?->getAttribute('href');
        $images = $this->extractImageUrls($xpath, $productData, $resolvedUrl);

        foreach ($xpath->query('//script|//style|//noscript|//svg|//template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $content = preg_replace('/\s+/u', ' ', trim($document->textContent ?? '')) ?: '';
        $content = mb_substr($content, 0, config('campaigns.source.max_extracted_characters'));

        return [
            'url' => $resolvedUrl,
            'title' => trim((string) $title),
            'canonical_url' => $canonical ? (string) UriResolver::resolve(new Uri($resolvedUrl), new Uri($canonical)) : $resolvedUrl,
            'description' => trim((string) $description),
            'content' => $content,
            'content_hash' => hash('sha256', $html),
            'images' => $images,
            'product_truth' => array_filter([
                'name' => data_get($productData, 'name') ?: $title,
                'description' => data_get($productData, 'description') ?: $description,
                'sku' => data_get($productData, 'sku'),
                'brand' => data_get($productData, 'brand.name') ?: data_get($productData, 'brand'),
                'price' => data_get($productData, 'offers.price'),
                'currency' => data_get($productData, 'offers.priceCurrency'),
                'availability' => data_get($productData, 'offers.availability'),
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function extractImageUrls(DOMXPath $xpath, array $productData, string $resolvedUrl): array
    {
        $candidates = [];
        foreach (['og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'] as $property) {
            $value = $this->metaProperty($xpath, $property) ?: $this->metaContent($xpath, $property);
            if ($value) {
                $candidates[] = $value;
            }
        }
        $this->appendProductImages($candidates, $productData['image'] ?? null);

        foreach ($xpath->query('//img') as $node) {
            $source = $node->getAttribute('src') ?: $node->getAttribute('data-src');
            if ($source !== '') {
                $candidates[] = $source;
            }
            $sourceSet = $node->getAttribute('srcset') ?: $node->getAttribute('data-srcset');
            if ($sourceSet !== '') {
                $entries = array_values(array_filter(array_map(
                    fn (string $entry): string => explode(' ', trim($entry))[0],
                    explode(',', $sourceSet),
                )));
                if ($entries !== []) {
                    $candidates[] = end($entries);
                }
            }
        }

        return collect($candidates)
            ->map(fn (string $candidate): ?string => $this->resolveImageUrl($candidate, $resolvedUrl))
            ->filter()
            ->reject(fn (string $candidate): bool => preg_match(self::SKIP_IMAGE_PATTERN, $candidate) === 1)
            ->filter(fn (string $candidate): bool => preg_match('/\.(jpe?g|png|webp|gif|avif)(\?|$)/i', $candidate) === 1
                || preg_match('#/(cdn|images?|media|products?)/#i', $candidate) === 1)
            ->unique()
            ->take((int) config('campaigns.source.max_image_candidates'))
            ->values()
            ->all();
    }

    private function appendProductImages(array &$candidates, mixed $image): void
    {
        if (is_string($image) && trim($image) !== '') {
            $candidates[] = $image;

            return;
        }
        if (! is_array($image)) {
            return;
        }
        foreach (['url', 'contentUrl'] as $key) {
            if (is_string($image[$key] ?? null)) {
                $candidates[] = $image[$key];
            }
        }
        if (array_is_list($image)) {
            foreach ($image as $item) {
                $this->appendProductImages($candidates, $item);
            }
        }
    }

    private function resolveImageUrl(string $candidate, string $resolvedUrl): ?string
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5));
        if ($candidate === '' || str_starts_with($candidate, 'data:')) {
            return null;
        }

        $url = (string) UriResolver::resolve(new Uri($resolvedUrl), new Uri($candidate));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function extractProductJsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            foreach ($this->jsonLdCandidates($decoded) as $candidate) {
                $types = (array) ($candidate['@type'] ?? []);
                if (in_array('Product', $types, true)) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    private function jsonLdCandidates(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return array_merge([$decoded], is_array($decoded['@graph'] ?? null) ? $decoded['@graph'] : []);
    }

    private function nodeText(DOMXPath $xpath, string $query): ?string
    {
        return $xpath->query($query)->item(0)?->textContent;
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        return $xpath->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$name}']")->item(0)?->getAttribute('content');
    }

    private function metaProperty(DOMXPath $xpath, string $property): ?string
    {
        return $xpath->query("//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$property}']")->item(0)?->getAttribute('content');
    }
}
