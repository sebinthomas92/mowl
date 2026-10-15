<?php

namespace App\Services;

use App\Models\BannerCreative;
use App\Models\Brand;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BannerComposer
{
    private const CTA_WIDTH = 230;

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
        // The wordmark sits in the same bottom-right corner on every layout, over a soft dark scrim.
        $this->drawBrandScrim($canvas, $width, $height, $this->logoIsDark($brand));
        $this->drawBrand($canvas, $brand, $width - 360 - 80, $height - 62, 360, $width - 80);

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
            'split_left' => ['x' => 80, 'headline_y' => 330, 'cta_y' => 1135, 'max_width' => 440, 'headline_height' => 450, 'support_height' => 200],
            'bottom_panel' => ['x' => 80, 'headline_y' => 960, 'cta_y' => 1230, 'max_width' => $width - 160, 'headline_height' => 220, 'support_height' => 110],
            default => ['x' => 80, 'headline_y' => 240, 'cta_y' => 555, 'max_width' => 520, 'headline_height' => 300, 'support_height' => 130],
        };
    }

    /**
     * A smooth dark gradient behind the text. Drawn one pixel row or column at a time: GD rectangle
     * bounds are inclusive, so wider strips overlap by a pixel and show as dark scan lines. Side
     * panels stay dark across the whole text column and fade out after it, so no hard edge cuts the
     * photo and no text runs off the panel.
     */
    private function drawReadabilityLayer(\GdImage $canvas, string $layout, int $width, int $height): void
    {
        if ($layout === 'split_left') {
            for ($x = 0; $x < 960; $x++) {
                $alpha = 24 + (int) round($this->fade($x, 600, 960) * 103);
                imageline($canvas, $x, 0, $x, $height - 1, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
            }
        } elseif ($layout === 'bottom_panel') {
            for ($y = 720; $y < $height; $y++) {
                $alpha = max(16, 127 - (int) ((($y - 720) / ($height - 720)) * 111));
                imageline($canvas, 0, $y, $width - 1, $y, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
            }
        } else {
            // Dark over the text block down to the CTA row, fading below it and right of the text column.
            for ($y = 0; $y < 840; $y++) {
                $rowAlpha = 20 + $this->fade($y, 420, 840) * 107;
                imageline($canvas, 0, $y, 619, $y, imagecolorallocatealpha($canvas, 5, 11, 20, (int) round($rowAlpha)));
                for ($x = 620; $x < 980; $x++) {
                    $alpha = (int) round($rowAlpha + (127 - $rowAlpha) * $this->fade($x, 620, 980));
                    if ($alpha < 127) {
                        imagesetpixel($canvas, $x, $y, imagecolorallocatealpha($canvas, 5, 11, 20, $alpha));
                    }
                }
            }
        }
    }

    /**
     * A soft dark scrim behind the bottom-right wordmark: solid over the wordmark's box and feathered
     * out over 150px. It is darker when any part of the background behind it is bright (a light
     * napkin, pale wood), so the white wordmark keeps its contrast on any photo.
     */
    /**
     * A dark uploaded logo needs a light backing; the white name and light logos need a dark one.
     */
    private function drawBrandScrim(\GdImage $canvas, int $width, int $height, bool $light = false): void
    {
        [$left, $right, $top, $bottom, $feather] = [$width - 440, $width - 80, $height - 100, $height - 50, 150];
        $samples = [];
        for ($y = $top; $y <= $bottom; $y += 4) {
            for ($x = $left; $x <= $right; $x += 4) {
                $rgb = imagecolorat($canvas, $x, $y);
                $samples[] = 0.2126 * (($rgb >> 16) & 255) + 0.7152 * (($rgb >> 8) & 255) + 0.0722 * ($rgb & 255);
            }
        }
        // The patches closest to the mark's own tone decide, not the average: one white patch behind a
        // few white letters (or a black one behind a dark logo) is enough to lose them.
        sort($samples);
        $clash = $light
            ? 255 - ($samples[(int) floor(0.1 * (count($samples) - 1))] ?? 255)
            : ($samples[(int) floor(0.9 * (count($samples) - 1))] ?? 0);
        $strength = 60 + 55 * max(0, min(1, ($clash - 60) / 140));
        [$red, $green, $blue] = $light ? [246, 244, 240] : [5, 11, 20];

        for ($y = $top - $feather; $y < $height; $y++) {
            for ($x = $left - $feather; $x < $width; $x++) {
                $dx = max(0, $left - $x, $x - $right);
                $dy = max(0, $top - $y, $y - $bottom);
                $distance = sqrt($dx ** 2 + $dy ** 2) / $feather;
                $alpha = 127 - (int) round($strength * (1 - $this->fade((int) round($distance * 100), 0, 100)));
                if ($alpha < 127) {
                    imagesetpixel($canvas, $x, $y, imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha));
                }
            }
        }
    }

    /** Whether the uploaded logo's visible pixels are dark on average, as a black wordmark is. */
    private function logoIsDark(Brand $brand): bool
    {
        if (! $brand->banner_logo_path || ! $brand->banner_logo_disk) {
            return false;
        }
        $logo = @imagecreatefromstring((string) Storage::disk($brand->banner_logo_disk)->get($brand->banner_logo_path));
        if (! $logo) {
            return false;
        }
        [$weight, $total] = [0.0, 0.0];
        $step = max(1, (int) floor(max(imagesx($logo), imagesy($logo)) / 120));
        for ($y = 0; $y < imagesy($logo); $y += $step) {
            for ($x = 0; $x < imagesx($logo); $x += $step) {
                $rgba = imagecolorsforindex($logo, imagecolorat($logo, $x, $y));
                $opacity = 1 - $rgba['alpha'] / 127;
                $weight += $opacity;
                $total += $opacity * (0.2126 * $rgba['red'] + 0.7152 * $rgba['green'] + 0.0722 * $rgba['blue']);
            }
        }
        imagedestroy($logo);

        return $weight > 0 && $total / $weight < 110;
    }

    /** 0 before $from, 1 after $to, eased between. */
    private function fade(int $value, int $from, int $to): float
    {
        $t = max(0, min(1, ($value - $from) / ($to - $from)));

        return $t * $t * (3 - 2 * $t);
    }

    /** Draws the logo or name at $x, or ending at $right when given. */
    private function drawBrand(\GdImage $canvas, Brand $brand, int $x, int $baseline, int $maxWidth, ?int $right = null): void
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
                if ($right !== null) {
                    $x = $right - $logoWidth;
                }
                imagecopyresampled($canvas, $logo, $x, $baseline - $logoHeight, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);
                imagedestroy($logo);

                return;
            }
        }

        $name = $brand->name;
        $size = 29;
        while ($size > 18 && $this->textWidth($name, $size) > $maxWidth) {
            $size -= 2;
        }
        // A name too long even at the smallest size is cut short so it never runs into the CTA.
        while (mb_strlen($name) > 1 && $this->textWidth($name, $size) > $maxWidth) {
            $name = rtrim(mb_substr($name, 0, -2)).'…';
        }
        if ($right !== null) {
            $x = $right - $this->textWidth($name, $size);
        }
        imagettftext($canvas, $size, 0, $x, $baseline, imagecolorallocate($canvas, 255, 255, 255), $this->font, $name);
    }

    private function drawCta(\GdImage $canvas, string $hex, string $cta, int $x, int $y): void
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');
        $background = imagecolorallocate($canvas, $red, $green, $blue);
        $foreground = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000 > 150
            ? imagecolorallocate($canvas, 12, 19, 29)
            : imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, $x, $y, $x + self::CTA_WIDTH, $y + 82, $background);
        imagettftext($canvas, 27, 0, $x + 32, $y + 53, $foreground, $this->font, $cta);
    }

    private function fitText(string $text, int $maxWidth, int $startSize, int $minSize, int $maxLines, int $maxHeight): array
    {
        // Prefer a size at which every word fits whole; a token too wide even at 14px (a URL, a SKU)
        // falls back to the largest size whose wrapped lines fit, as before.
        foreach ([true, false] as $keepWordsWhole) {
            if ($fit = $this->fitTextAt($text, $maxWidth, $startSize, $minSize, $maxLines, $maxHeight, $keepWordsWhole)) {
                return $fit;
            }
        }

        $lines = $this->wrap($text, $minSize, $maxWidth);
        $lineHeight = (int) round($minSize * 1.18);

        return ['lines' => $lines, 'size' => $minSize, 'lineHeight' => $lineHeight, 'height' => count($lines) * $lineHeight];
    }

    private function fitTextAt(string $text, int $maxWidth, int $startSize, int $minSize, int $maxLines, int $maxHeight, bool $keepWordsWhole): ?array
    {
        for ($size = $startSize; $size >= $minSize; $size -= 2) {
            $lines = $this->wrap($text, $size, $maxWidth);
            $lineHeight = (int) round($size * 1.22);
            $height = count($lines) * $lineHeight;
            if (count($lines) <= $maxLines && $height <= $maxHeight && ! ($keepWordsWhole && $this->splitsWords($text, $size, $maxWidth))) {
                return compact('lines', 'size', 'lineHeight', 'height');
            }
        }

        for ($size = $minSize - 1; $size >= 14; $size--) {
            $lines = $this->wrap($text, $size, $maxWidth);
            $lineHeight = (int) round($size * 1.18);
            $height = count($lines) * $lineHeight;
            if ($height <= $maxHeight && ! ($keepWordsWhole && $this->splitsWords($text, $size, $maxWidth))) {
                return compact('lines', 'size', 'lineHeight', 'height');
            }
        }

        return null;
    }

    /** Whether a single word is wider than the column at this size, so wrapping would break it mid-word. */
    private function splitsWords(string $text, int $size, int $maxWidth): bool
    {
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($this->textWidth($word, $size) > $maxWidth) {
                return true;
            }
        }

        return false;
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
