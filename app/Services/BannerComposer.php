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

    /** @param  array{x: float, y: float, width: float}|null  $productSpot  the scene's empty resting spot, as shares of its width and height */
    public function compose(string $backgroundBytes, Brand $brand, BannerCreative $creative, ?string $productCutout = null, ?array $productSpot = null): string
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
        $cover = $this->cover($source, $canvas, $width, $height);
        if ($productSpot !== null) {
            // From shares of the generated scene to canvas pixels, through the cover crop.
            $productSpot = [
                'x' => ($productSpot['x'] * imagesx($source) - $cover[1]) * $cover[0],
                'y' => ($productSpot['y'] * imagesy($source) - $cover[2]) * $cover[0],
                'width' => $productSpot['width'] * imagesx($source) * $cover[0],
            ];
        }
        imagedestroy($source);

        $layout = $this->layout($creative->layout, $width, $height);
        $this->drawReadabilityLayer($canvas, $creative->layout, $width, $height);
        if ($productCutout !== null) {
            $this->drawProduct($canvas, $productCutout, $creative->layout, $productSpot);
        }
        // The wordmark sits in the same bottom-right corner on every layout, over a soft dark scrim.
        $this->drawBrandScrim($canvas, $width, $height, $this->logoIsDark($brand));
        $this->drawBrand($canvas, $brand, $width - 360 - 80, $height - 62, 360, $width - 80);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 231, 235, 241);
        $headline = $this->fitText($creative->headline, $layout['max_width'], 70, 36, 4, $layout['headline_height']);
        $headlineBottom = $this->drawLines($canvas, $headline, $layout['x'], $layout['headline_y'], $white);

        if ($creative->supporting_text) {
            $support = $this->fitText($creative->supporting_text, $layout['max_width'], 38, 28, 3, $layout['support_height']);
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

    /** @return array{float, int, int} the scale and the source crop's left and top */
    private function cover(\GdImage $source, \GdImage $canvas, int $width, int $height): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);
        $sourceX = max(0, (int) floor(($sourceWidth - $cropWidth) / 2));
        $sourceY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));
        imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);

        return [$scale, $sourceX, $sourceY];
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
     * Where the product cutout stands on each layout: centre x, the y its base rests on, and the box it
     * is fitted into. These match the resting spot BannerCopySelector asks the scene to leave, and
     * stay clear of the text column and the bottom-right wordmark.
     *
     * @return array{x: int, base: int, width: int, height: int}
     */
    public static function productSlot(?string $layout): array
    {
        return match ($layout) {
            'split_left' => ['x' => 820, 'base' => 900, 'width' => 420, 'height' => 580],
            'bottom_panel' => ['x' => 540, 'base' => 820, 'width' => 520, 'height' => 540],
            default => ['x' => 600, 'base' => 1170, 'width' => 500, 'height' => 500],
        };
    }

    /**
     * Boxes the product must stay out of on each layout (left, top, right, bottom): the copy and CTA,
     * and the wordmark corner every layout shares.
     *
     * @return list<array{int, int, int, int}>
     */
    private static function keepOut(?string $layout, int $width, int $height): array
    {
        $wordmark = [$width - 460, $height - 120, $width, $height];

        return [$wordmark, ...match ($layout) {
            'split_left' => [[0, 0, 560, $height]],
            'bottom_panel' => [[0, 930, $width, $height]],
            default => [[0, 0, 640, 660]],
        }];
    }

    /**
     * Where the product stands: on the scene's own empty spot when the scene check found one near the
     * slot the scene was asked for (so it sits on the surface that was drawn, not mid-air or on the
     * table's front edge), sized to that surface within limits; otherwise the layout's fixed slot.
     *
     * @param  array{x: float, y: float, width: float}|null  $spot  in canvas pixels
     * @return array{x: int, base: int, width: int, height: int}
     */
    public static function placement(?string $layout, ?array $spot, int $cutoutWidth, int $cutoutHeight, int $width = 1080, int $height = 1350): array
    {
        $slot = self::productSlot($layout);
        $fit = min($slot['width'] / $cutoutWidth, $slot['height'] / $cutoutHeight);
        $fallback = ['x' => $slot['x'], 'base' => $slot['base'], 'width' => max(1, (int) round($cutoutWidth * $fit)), 'height' => max(1, (int) round($cutoutHeight * $fit))];
        if ($spot === null || abs($spot['x'] - $slot['x']) > 0.2 * $width || abs($spot['y'] - $slot['base']) > 0.15 * $height) {
            return $fallback;
        }
        // A product a little narrower than the free surface, never far from the size the layout expects.
        $grow = max(0.85, min(1.2, 0.8 * $spot['width'] / max(1, $fallback['width'])));
        $placed = [
            'x' => (int) round($spot['x']),
            'base' => (int) round($spot['y']),
            'width' => max(1, (int) round($fallback['width'] * $grow)),
            'height' => max(1, (int) round($fallback['height'] * $grow)),
        ];
        $box = [$placed['x'] - $placed['width'] / 2, $placed['base'] - $placed['height'], $placed['x'] + $placed['width'] / 2, $placed['base']];
        if ($box[0] < 20 || $box[1] < 20 || $box[2] > $width - 20 || $box[3] > $height - 20) {
            return $fallback;
        }
        foreach (self::keepOut($layout, $width, $height) as [$left, $top, $right, $bottom]) {
            if ($box[0] < $right && $box[2] > $left && $box[1] < $bottom && $box[3] > $top) {
                return $fallback;
            }
        }

        return $placed;
    }

    /**
     * The real product, standing on the scene's surface: a cast shadow falling back and away from the
     * front-left light, a dark contact line that follows the product's own base (so no gap of lit table
     * shows under it), and edges softened to the scene's photographic softness.
     *
     * @param  array{x: float, y: float, width: float}|null  $spot
     */
    private function drawProduct(\GdImage $canvas, string $cutoutBytes, ?string $layout, ?array $spot = null): void
    {
        $cutout = @imagecreatefromstring($cutoutBytes);
        if (! $cutout) {
            throw new RuntimeException('The product cutout is not a supported image.');
        }
        $place = self::placement($layout, $spot, imagesx($cutout), imagesy($cutout), imagesx($canvas), imagesy($canvas));
        [$width, $height] = [$place['width'], $place['height']];
        $x = $place['x'] - intdiv($width, 2);
        $y = $place['base'] - $height;
        // Resized first, so the light match below touches only the pixels that are drawn.
        $product = imagecreatetruecolor($width, $height);
        imagealphablending($product, false);
        imagesavealpha($product, true);
        imagefill($product, 0, 0, imagecolorallocatealpha($product, 0, 0, 0, 127));
        imagecopyresampled($product, $cutout, 0, 0, 0, 0, $width, $height, imagesx($cutout), imagesy($cutout));
        imagedestroy($cutout);
        $this->matchLight($product, $this->averageColour($canvas, $x, $y, $width, $height));
        $this->drawShadow($canvas, $product, $x, $y);
        $this->softenEdges($product);

        imagealphablending($canvas, true);
        imagecopy($canvas, $product, $x, $y, 0, 0, $width, $height);
        imagedestroy($product);
    }

    /**
     * Darkens the scene under and behind the product. The cast shadow is the product's silhouette laid
     * flat on the surface and sheared back to the right, away from the front-left light the scene is
     * asked for, then blurred wide; the contact shadow is a tight dark band along the lowest opaque
     * pixel of every column, where the product meets the surface.
     */
    private function drawShadow(\GdImage $canvas, \GdImage $product, int $x, int $y): void
    {
        [$width, $height] = [imagesx($product), imagesy($product)];
        $opaque = fn (int $px, int $py): bool => ((imagecolorat($product, $px, $py) >> 24) & 127) < 64;
        $bottoms = [];
        for ($px = 0; $px < $width; $px++) {
            for ($py = $height - 1; $py >= 0; $py--) {
                if ($opaque($px, $py)) {
                    $bottoms[$px] = $py;

                    break;
                }
            }
        }
        if ($bottoms === []) {
            return;
        }
        // Shadow buffers are a quarter of the size: blurring them wide is cheap, and upscaling softens more.
        $pad = (int) max(24, round($width * 0.25));
        $factor = 4;
        $bufferWidth = intdiv($width + 2 * $pad, $factor) + 1;
        $bufferHeight = intdiv($height + 2 * $pad, $factor) + 1;
        $cast = $this->shadowBuffer($bufferWidth, $bufferHeight);
        $contact = $this->shadowBuffer($bufferWidth, $bufferHeight);
        $black = imagecolorallocate($cast, 0, 0, 0);
        $contactBlack = imagecolorallocate($contact, 0, 0, 0);
        $depth = max(1, $height * 0.22);
        for ($py = 0; $py < $height; $py += 2) {
            for ($px = 0; $px < $width; $px += 2) {
                if (! isset($bottoms[$px]) || ! $opaque($px, $py)) {
                    continue;
                }
                $rise = $bottoms[$px] - $py;
                $sx = $px + $pad + 0.35 * $rise * $depth / $height;
                $sy = $bottoms[$px] + $pad - $rise * $depth / $height;
                imagesetpixel($cast, (int) ($sx / $factor), (int) ($sy / $factor), $black);
            }
        }
        foreach ($bottoms as $px => $bottom) {
            imagefilledrectangle($contact, intdiv($px + $pad, $factor), intdiv($bottom + $pad - 4, $factor), intdiv($px + $pad, $factor), intdiv($bottom + $pad + 5, $factor), $contactBlack);
        }
        for ($pass = 0; $pass < 10; $pass++) {
            imagefilter($cast, IMG_FILTER_GAUSSIAN_BLUR);
        }
        for ($pass = 0; $pass < 3; $pass++) {
            imagefilter($contact, IMG_FILTER_GAUSSIAN_BLUR);
        }
        // Scaled back up smoothly, so the soft shadow shows no blocks.
        [$fullWidth, $fullHeight] = [$bufferWidth * $factor, $bufferHeight * $factor];
        foreach ([&$cast, &$contact] as &$buffer) {
            $full = imagescale($buffer, $fullWidth, $fullHeight, IMG_BILINEAR_FIXED);
            imagedestroy($buffer);
            $buffer = $full;
        }
        unset($buffer);
        $left = $x - $pad;
        $top = $y - $pad;
        for ($cy = max(0, $top); $cy < min(imagesy($canvas), $top + $fullHeight); $cy++) {
            for ($cx = max(0, $left); $cx < min(imagesx($canvas), $left + $fullWidth); $cx++) {
                $dark = 0.5 * (1 - (imagecolorat($cast, $cx - $left, $cy - $top) & 255) / 255) + 0.9 * (1 - (imagecolorat($contact, $cx - $left, $cy - $top) & 255) / 255);
                if ($dark < 0.01) {
                    continue;
                }
                $keep = max(0.35, 1 - $dark);
                $c = imagecolorat($canvas, $cx, $cy);
                imagesetpixel($canvas, $cx, $cy, imagecolorallocate($canvas, (int) ((($c >> 16) & 255) * $keep), (int) ((($c >> 8) & 255) * $keep), (int) (($c & 255) * $keep)));
            }
        }
        imagedestroy($cast);
        imagedestroy($contact);
    }

    private function shadowBuffer(int $width, int $height): \GdImage
    {
        $buffer = imagecreatetruecolor($width, $height);
        imagefill($buffer, 0, 0, imagecolorallocate($buffer, 255, 255, 255));

        return $buffer;
    }

    /**
     * A cutout's edge is sharper than anything in a photographed scene, which made it look pasted on.
     * Each partly or fully opaque edge pixel takes the mean opacity of its 3x3 neighbourhood.
     */
    private function softenEdges(\GdImage $product): void
    {
        [$width, $height] = [imagesx($product), imagesy($product)];
        $alpha = [];
        for ($py = 0; $py < $height; $py++) {
            for ($px = 0; $px < $width; $px++) {
                $alpha[$py][$px] = (imagecolorat($product, $px, $py) >> 24) & 127;
            }
        }
        imagealphablending($product, false);
        for ($py = 0; $py < $height; $py++) {
            for ($px = 0; $px < $width; $px++) {
                if ($alpha[$py][$px] === 127) {
                    continue;
                }
                [$sum, $count, $edge] = [0, 0, false];
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $neighbour = $alpha[$py + $dy][$px + $dx] ?? 127;
                        $edge = $edge || $neighbour === 127;
                        [$sum, $count] = [$sum + $neighbour, $count + 1];
                    }
                }
                if (! $edge) {
                    continue;
                }
                $c = imagecolorat($product, $px, $py);
                imagesetpixel($product, $px, $py, imagecolorallocatealpha($product, ($c >> 16) & 255, ($c >> 8) & 255, $c & 255, (int) round(max($alpha[$py][$px], $sum / $count))));
            }
        }
        imagealphablending($product, true);
    }

    /** @return array{float, float, float} */
    private function averageColour(\GdImage $canvas, int $x, int $y, int $width, int $height): array
    {
        [$r, $g, $b, $n] = [0, 0, 0, 0];
        for ($py = max(0, $y); $py < min(imagesy($canvas), $y + $height); $py += 6) {
            for ($px = max(0, $x); $px < min(imagesx($canvas), $x + $width); $px += 6) {
                $c = imagecolorat($canvas, $px, $py);
                [$r, $g, $b, $n] = [$r + (($c >> 16) & 255), $g + (($c >> 8) & 255), $b + ($c & 255), $n + 1];
            }
        }

        return $n ? [$r / $n, $g / $n, $b / $n] : [128.0, 128.0, 128.0];
    }

    /**
     * A studio-lit product is brighter and more neutral than most scenes, which made it look pasted on.
     * It is dimmed toward the scene behind it and takes a little of its colour, within limits that
     * keep the product's own colours recognisable.
     *
     * @param  array{float, float, float}  $scene
     */
    private function matchLight(\GdImage $cutout, array $scene): void
    {
        imagealphablending($cutout, false);
        imagesavealpha($cutout, true);
        [$r, $g, $b, $n] = [0, 0, 0, 0];
        for ($y = 0; $y < imagesy($cutout); $y += 3) {
            for ($x = 0; $x < imagesx($cutout); $x += 3) {
                $c = imagecolorat($cutout, $x, $y);
                if ((($c >> 24) & 127) < 40) {
                    [$r, $g, $b, $n] = [$r + (($c >> 16) & 255), $g + (($c >> 8) & 255), $b + ($c & 255), $n + 1];
                }
            }
        }
        if ($n === 0) {
            return;
        }
        $productLight = ($r + $g + $b) / (3 * $n);
        $sceneLight = array_sum($scene) / 3;
        $gain = max(0.82, min(1.0, ($sceneLight / max(1, $productLight)) ** 0.3));
        $sceneTint = array_map(fn (float $channel) => $channel / max(1, $sceneLight), $scene);
        $tint = array_map(fn (float $channel) => 1 + 0.08 * ($channel - 1), $sceneTint);
        if ($gain > 0.995 && abs($tint[0] - 1) < 0.005 && abs($tint[2] - 1) < 0.005) {
            return;
        }
        for ($y = 0; $y < imagesy($cutout); $y++) {
            for ($x = 0; $x < imagesx($cutout); $x++) {
                $c = imagecolorat($cutout, $x, $y);
                $alpha = ($c >> 24) & 127;
                if ($alpha === 127) {
                    continue;
                }
                imagesetpixel($cutout, $x, $y, imagecolorallocatealpha(
                    $cutout,
                    (int) min(255, round((($c >> 16) & 255) * $gain * $tint[0])),
                    (int) min(255, round((($c >> 8) & 255) * $gain * $tint[1])),
                    (int) min(255, round(($c & 255) * $gain * $tint[2])),
                    $alpha,
                ));
            }
        }
        imagealphablending($cutout, true);
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
