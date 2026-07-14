<?php

namespace Tests\Unit;

use App\Services\ProductHubCsvExporter;
use PHPUnit\Framework\TestCase;

class ProductHubCsvExporterTest extends TestCase
{
    public function test_export_preserves_order_quoting_newlines_and_character_counts(): void
    {
        $headline = "Café, ready\nfor today";
        $content = ['channels' => ['google_ads' => ['search' => [
            'headlines' => [$headline, 'Second headline'],
            'long_headlines' => ['A longer handoff headline'],
            'descriptions' => ['Clear product details.'],
            'sitelinks' => ['Shop now'],
            'promotion' => 'Free delivery',
            'final_url' => 'https://example.com/product',
        ]]]];

        $csv = (new ProductHubCsvExporter)->export($content, 'search');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(['campaign_type', 'field_group', 'position', 'value', 'character_count', 'final_url'], $rows[0]);
        $this->assertSame(['headlines', 'headlines', 'long_headlines', 'descriptions', 'sitelinks', 'promotion', 'final_url'], array_column(array_slice($rows, 1), 1));
        $this->assertSame($headline, $rows[1][3]);
        $this->assertSame((string) mb_strlen($headline), $rows[1][4]);
        $this->assertSame('https://example.com/product', $rows[1][5]);
    }
}
