<?php

namespace App\Http\Controllers;

use App\Models\CampaignPack;
use App\Models\CampaignPackShare;
use App\Models\CampaignPackVersion;
use App\Services\CampaignPackContentNormalizer;
use Illuminate\Http\Response;

class CampaignPackDeliveryController extends Controller
{
    public function share(string $token, CampaignPackContentNormalizer $normalizer): Response
    {
        $share = CampaignPackShare::query()->with(['version.campaignPack.product.brand', 'version.campaignPack.sourceSnapshot'])
            ->where('token', $token)->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();
        $version = $share->version;
        abort_unless($version->review_status === 'approved', 404);
        $content = $normalizer->normalize($version->content, $version->campaignPack->product, $version->campaignPack->sourceSnapshot);

        return response()->view('campaign-packs.share', compact('version', 'content'));
    }

    public function export(CampaignPack $pack, CampaignPackVersion $version, string $format, CampaignPackContentNormalizer $normalizer): Response
    {
        abort_unless($pack->id === $version->campaign_pack_id, 404);
        abort_unless($pack->product->brand->workspace->users()->whereKey(auth()->id())->exists(), 404);
        abort_unless($version->review_status === 'approved', 422);
        $pack->product->brand->workspace->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => 'campaign_pack_exported', 'subject_type' => CampaignPackVersion::class,
            'subject_id' => $version->id, 'metadata' => ['format' => $format],
        ]);
        $content = $normalizer->normalize($version->content, $pack->product, $pack->sourceSnapshot);
        $text = $this->text($version, $content);
        $filename = str($pack->name)->slug().'-v'.$version->version;

        return match ($format) {
            'text' => response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$filename}.txt\""]),
            'csv' => response($this->csv($content), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$filename}.csv\""]),
            'pdf' => response($this->pdf($text), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$filename}.pdf\""]),
            'voiceover' => $this->csvResponse($this->voiceoverRows($content), "{$filename}-voiceover.csv"),
            'captions' => $this->csvResponse($this->captionRows($content), "{$filename}-captions.csv"),
            'shot-plan' => $this->csvResponse($this->shotPlanRows($content), "{$filename}-shot-plan.csv"),
            default => abort(404),
        };
    }

    private function text(CampaignPackVersion $version, array $content): string
    {
        return "Campaign Pack v{$version->version}\n\n".json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function csv(array $content): string
    {
        $rows = [['section', 'value']];
        foreach ($content as $section => $value) {
            $rows[] = [$section, is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)];
        }

        return $this->rowsToCsv($rows);
    }

    private function voiceoverRows(array $content): array
    {
        $rows = [['route', 'start_seconds', 'end_seconds', 'word_count', 'pace_wpm', 'delivery_notes', 'shot_id', 'line']];
        foreach ($content['creative_routes'] ?? [] as $route) {
            foreach ($route['voiceover'] ?? [] as $line) {
                $rows[] = [$route['name'], $line['start_seconds'], $line['end_seconds'], $line['word_count'], $line['pace_wpm'], $line['delivery_notes'], $line['shot_id'], $line['line']];
            }
        }

        return $rows;
    }

    private function captionRows(array $content): array
    {
        $rows = [['route', 'start_seconds', 'end_seconds', 'shot_id', 'caption']];
        foreach ($content['creative_routes'] ?? [] as $route) {
            foreach ($route['captions'] ?? [] as $caption) {
                $rows[] = [$route['name'], $caption['start_seconds'], $caption['end_seconds'], $caption['shot_id'], $caption['text']];
            }
        }

        return $rows;
    }

    private function shotPlanRows(array $content): array
    {
        $rows = [['route', 'shot_id', 'start_seconds', 'end_seconds', 'purpose', 'scene', 'action', 'camera_framing', 'product_visibility', 'voiceover_line', 'on_screen_caption', 'product_fact_or_benefit', 'props_or_requirements', 'lighting_or_movement', 'priority']];
        foreach ($content['creative_routes'] ?? [] as $route) {
            foreach ($route['shot_plan'] ?? [] as $shot) {
                $rows[] = [$route['name'], $shot['id'], $shot['start_seconds'], $shot['end_seconds'], $shot['purpose'], $shot['scene'], $shot['action'], $shot['camera_framing'], $shot['product_visibility'], $shot['voiceover_line'], $shot['on_screen_caption'], $shot['product_fact_or_benefit'], $shot['props_or_requirements'], $shot['lighting_or_movement'], $shot['priority']];
            }
        }

        return $rows;
    }

    private function csvResponse(array $rows, string $filename): Response
    {
        return response($this->rowsToCsv($rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function rowsToCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function pdf(string $text): string
    {
        $lines = array_slice(preg_split('/\R/', wordwrap($text, 90)), 0, 48);
        $body = implode("\n", array_map(fn ($line, $index) => sprintf('BT /F1 9 Tf 48 %d Td (%s) Tj ET', 780 - ($index * 15), str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line)), $lines, array_keys($lines)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($body)." >>\nstream\n{$body}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
