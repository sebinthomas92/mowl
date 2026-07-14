<?php

namespace App\Services;

class ProductHubCsvExporter
{
    public function export(array $content, string $campaignType): string
    {
        $type = $this->normalizeType($campaignType);
        $ads = data_get($content, "channels.google_ads.{$type}", []);
        $finalUrl = (string) ($ads['final_url'] ?? '');
        $rows = [['campaign_type', 'field_group', 'position', 'value', 'character_count', 'final_url']];

        foreach (['headlines', 'long_headlines', 'descriptions', 'sitelinks'] as $group) {
            foreach (array_values($ads[$group] ?? []) as $index => $value) {
                $text = is_array($value) ? implode(' — ', array_filter($value)) : (string) $value;
                $rows[] = [$type, $group, $index + 1, $text, mb_strlen($text), $finalUrl];
            }
        }
        if (($ads['promotion'] ?? '') !== '') {
            $text = (string) $ads['promotion'];
            $rows[] = [$type, 'promotion', 1, $text, mb_strlen($text), $finalUrl];
        }
        $rows[] = [$type, 'final_url', 1, $finalUrl, mb_strlen($finalUrl), $finalUrl];

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function normalizeType(string $campaignType): string
    {
        $type = str($campaignType)->snake()->toString();
        abort_unless(in_array($type, ['search', 'performance_max', 'display'], true), 404);

        return $type;
    }
}
