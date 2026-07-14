<?php

namespace App\Services;

use App\Models\BannerCreative;
use App\Models\Brand;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BannerComposer
{
    private string $font;

    public function __construct()
    {
        $this->font = resource_path('fonts/DM-Sans.ttf');
    }

    public function compose(string $backgroundBytes, Brand $brand, BannerCreative $creative): string
    {
        $source = @imagecreatefromstring($backgroundBytes);
        if (! $source) {
            throw new RuntimeException('The generated banner background is not a supported image.');
        }
        if (! is_file($this->font)) {
            throw new RuntimeException('The bundled DM Sans font is missing.');
        }

        $width = (int) config('campaigns.banners.width');
        $height = (int) config('campaigns.banners.height');
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        $this->cover($source, $canvas, $width, $height);
        imagedestroy($source);

        $layout = $this->layout($creative->layout, $width, $height);
        $this->drawReadabilityLayer($canvas, $creative->layout, $width, $height);
        $this->drawBrand($canvas, $brand, $layout['x'], $layout['brand_y'], $layout['max_width']);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 231, 235, 241);
        $headline = $this->fitText($creative->headline, $layout['max_width'], 70, 36, 4, $layout['headline_height']);
        $headlineBottom = $this->drawLines($canvas, $headline, $layout['x'], $layout['headline_y'], $white);

        if ($creative->supporting_text) {
            $support = $this->fitText($creative->supporting_text, $layout['max_width'], 32, 23, 3, $layout['support_height']);
            $supportY = $headlineBottom + 34;
            if ($supportY + $support['height'] <= $layout['cta_y'] - 30) {
                $this->drawLines($canvas, $support, $layout['x'], $supportY, $muted);
            }
        }

        $this->drawCta($canvas, $brand->primary_color ?: '#F4B942', $creative->cta, $layout['x'], $layout['cta_y']);

        ob_start();
        imagepng($canvas, null, 8);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        if (! is_string($bytes)) {
            throw new RuntimeException('GD could not encode the final banner PNG.');
        }

        return $bytes;
    }

    private function cover(\GdImage $source, \GdImage $canvas, int $width, int $height): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);
        $sourceX = max(0, (int) floor(($sourceWidth - $cropWidth) / 2));
        $sourceY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));
        imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);
    }

    private function layout(string $layout, int $width, int $height): array
    {
        return match ($layout) {
            'split_left' => ['x' => 80, 'brand_y' => 105, 'headline_y' => 330, 'cta_y' => 1135, 'max_width' => 475, 'headline_height' => 450, 'support_height' => 200],
            'bottom_panel' => ['x' => 80, 'brand_y' => 825, 'headline_y' => 960, 'cta_y' => 1230, 'max_width' => $width - 160, 'headline_height' => 220, 'support_height' => 110],
            default => ['x' => 80, 'brand_y' => 95, 'headline_y' => 240, 'cta_y' => 555, 'max_width' => 520, 'headline_height' => 300, 'support_height' => 130],
        };
    }

    private function drawReadabilityLayer(\GdImage $canvas, string $layout, int $width, int $height): void
    {
        if ($layout === 'split_left') {
            for ($x = 0; $x < 650; $x += 5) {
                $alpha = min(127, 18 + (int) (($x / 650) * 109));
                imagefilledrectangle($canvas, $x, 0, $x + 5, $height, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
            }
        } elseif ($layout === 'bottom_panel') {
            for ($y = 720; $y < $height; $y += 5) {
                $alpha = max(16, 115 - (int) ((($y - 720) / ($height - 720)) * 99));
                imagefilledrectangle($canvas, 0, $y, $width, $y + 5, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
            }
        } else {
            for ($y = 0; $y < 720; $y += 5) {
                $alpha = min(127, 20 + (int) (($y / 720) * 107));
                imagefilledrectangle($canvas, 0, $y, 690, $y + 5, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
            }
        }
    }

    private function drawBrand(\GdImage $canvas, Brand $brand, int $x, int $baseline, int $maxWidth): void
    {
        if ($brand->banner_logo_path && $brand->banner_logo_disk) {
            $bytes = Storage::disk($brand->banner_logo_disk)->get($brand->banner_logo_path);
            $logo = @imagecreatefromstring($bytes);
            if ($logo) {
                $sourceWidth = imagesx($logo);
                $sourceHeight = imagesy($logo);
                $scale = min($maxWidth / $sourceWidth, 72 / $sourceHeight, 1.0);
                $logoWidth = max(1, (int) round($sourceWidth * $scale));
                $logoHeight = max(1, (int) round($sourceHeight * $scale));
                imagecopyresampled($canvas, $logo, $x, $baseline - $logoHeight, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);
                imagedestroy($logo);

                return;
            }
        }

        imagettftext($canvas, 29, 0, $x, $baseline, imagecolorallocate($canvas, 255, 255, 255), $this->font, $brand->name);
    }

    private function drawCta(\GdImage $canvas, string $hex, string $cta, int $x, int $y): void
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');
        $background = imagecolorallocate($canvas, $red, $green, $blue);
        $foreground = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000 > 150
            ? imagecolorallocate($canvas, 12, 19, 29)
            : imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, $x, $y, $x + 230, $y + 82, $background);
        imagettftext($canvas, 27, 0, $x + 32, $y + 53, $foreground, $this->font, $cta);
    }

    private function fitText(string $text, int $maxWidth, int $startSize, int $minSize, int $maxLines, int $maxHeight): array
    {
        for ($size = $startSize; $size >= $minSize; $size -= 2) {
            $lines = $this->wrap($text, $size, $maxWidth);
            $lineHeight = (int) round($size * 1.22);
            $height = count($lines) * $lineHeight;
            if (count($lines) <= $maxLines && $height <= $maxHeight) {
                return compact('lines', 'size', 'lineHeight', 'height');
            }
        }

        for ($size = $minSize - 1; $size >= 14; $size--) {
            $lines = $this->wrap($text, $size, $maxWidth);
            $lineHeight = (int) round($size * 1.18);
            $height = count($lines) * $lineHeight;
            if ($height <= $maxHeight) {
                return compact('lines', 'size', 'lineHeight', 'height');
            }
        }

        $lines = $this->wrap($text, $minSize, $maxWidth);
        $lineHeight = (int) round($minSize * 1.18);

        return ['lines' => $lines, 'size' => $minSize, 'lineHeight' => $lineHeight, 'height' => count($lines) * $lineHeight];
    }

    private function wrap(string $text, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($this->textWidth($word, $size) > $maxWidth) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (mb_str_split($word) as $character) {
                    $candidate = $line.$character;
                    if ($line !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                        $lines[] = $line;
                        $line = $character;
                    } else {
                        $line = $candidate;
                    }
                }

                continue;
            }
            $candidate = $line === '' ? $word : "{$line} {$word}";
            if ($line !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function textWidth(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->font, $text);

        return abs($box[2] - $box[0]);
    }

    private function drawLines(\GdImage $canvas, array $text, int $x, int $y, int $color): int
    {
        foreach ($text['lines'] as $line) {
            imagettftext($canvas, $text['size'], 0, $x, $y, $color, $this->font, $line);
            $y += $text['lineHeight'];
        }

        return $y;
    }
}
