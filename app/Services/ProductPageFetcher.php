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
    public function fetch(string $url): array
    {
        $currentUrl = $url;
        $response = null;

        for ($redirects = 0; $redirects <= config('campaigns.source.max_redirects'); $redirects++) {
            $this->assertSafeUrl($currentUrl);
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('MarketingOwl/1.0 (+campaign-source-snapshot)')
                ->timeout(config('campaigns.source.timeout_seconds'))
                ->withOptions(['allow_redirects' => false])
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

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
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
