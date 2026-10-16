<?php

namespace App\Services;

/**
 * Cuts a product out of a studio photo on a plain backdrop without any AI: the backdrop colour is
 * read from the photo's edges, everything connected to the edges in that colour becomes transparent,
 * and the product keeps its own pixels. Asking the image model to redraw the product on a key colour
 * changed it (a tall brown frame for a low white stand on a red-orange base), and a vision check
 * passed the redraw, so a banner product must come from the photo's pixels.
 *
 * Returns null for anything else (a styled scene, props beside the product, a busy backdrop), so the
 * caller can try another photo.
 */
class ProductPhotoCutout
{
    /** Photos are worked on at most this size on their longer side; a banner shows the product smaller. */
    private const MAX_SIDE = 1000;

    /** Most of the photo's edge must be one colour for it to count as a plain backdrop. */
    private const MIN_PLAIN_BORDER = 0.92;

    private const PLAIN_DISTANCE = 24.0;

    /** A second object this big next to the product (a vase, a mug) means the photo is a styled set. */
    private const MAX_SECOND_OBJECT = 0.04;

    /** A detached piece larger than this share of the main object (and STRAY_PIXELS) is not JPEG noise. */
    private const MAX_STRAY = 0.005;

    private const STRAY_PIXELS = 40;

    private const MIN_COVERAGE = 0.03;

    private const MAX_COVERAGE = 0.85;

    /** Edge pixels this far beyond the backdrop tolerance are fully opaque; closer ones are blended. */
    private const EDGE_RAMP = 28.0;

    private const MAX_PIXELS = 25_000_000;

    /** Why the last photo was not cut out, for the error a banner records. */
    public ?string $reason = null;

    public function cut(string $bytes): ?string
    {
        $this->reason = 'unreadable image';
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }
        if ($size[2] === IMAGETYPE_JPEG) {
            $source = $this->upright($source, $bytes);
        }
        imagepalettetotruecolor($source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $factor = min(1, self::MAX_SIDE / max(imagesx($source), imagesy($source)));
        $image = $factor < 1
            ? imagescale($source, max(1, (int) round(imagesx($source) * $factor)), max(1, (int) round(imagesy($source) * $factor)), IMG_BICUBIC)
            : $source;
        if ($image !== $source) {
            imagedestroy($source);
        }
        if (! $image) {
            return null;
        }

        try {
            return $this->cutImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function cutImage(\GdImage $image): ?string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 32 || $height < 32) {
            return null;
        }
        $count = $width * $height;
        $ring = max(2, (int) round(min($width, $height) * 0.015));
        $border = [];
        $borderColours = [];
        for ($y = 0; $y < $height; $y++) {
            $edge = $y < $ring || $y >= $height - $ring;
            for ($x = 0; $x < $width; $x++) {
                if ($edge || $x < $ring || $x >= $width - $ring) {
                    $border[] = $y * $width + $x;
                    $borderColours[] = imagecolorat($image, $x, $y);
                }
            }
        }
        // A transparent PNG's clear pixels are backdrop whatever colour they hold. When its border is
        // (almost) all clear, the alpha alone separates the product: a backdrop colour taken from hidden
        // RGB values (often black) would remove a product of that colour.
        $opaque = array_values(array_filter($borderColours, fn (int $colour) => ! self::clear($colour)));
        $alphaOnly = count($opaque) <= (1 - self::MIN_PLAIN_BORDER) * count($borderColours);
        $backdrop = 0;
        $tolerance = 14;
        if (! $alphaOnly) {
            $backdrop = $this->median(array_map(fn (int $colour) => $colour & 0xFFFFFF, $opaque));
            $distances = array_map(fn (int $colour) => self::clear($colour) ? 0.0 : $this->distance($colour & 0xFFFFFF, $backdrop), $borderColours);
            sort($distances);
            $plain = count(array_filter($distances, fn (float $distance) => $distance <= self::PLAIN_DISTANCE)) / count($distances);
            if ($plain < self::MIN_PLAIN_BORDER) {
                $this->reason = 'not a plain backdrop';

                return null;
            }
            // The backdrop's own unevenness (a soft gradient, JPEG noise) sets how close counts as backdrop.
            $tolerance = (int) min(40, max(14, ceil($distances[(int) floor(0.95 * (count($distances) - 1))] + 8)));
        }

        // Each pixel's distance from the backdrop, one byte per pixel (an array of a million floats
        // would take much of the 128 MB memory limit).
        $near = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($image, $x, $y);
                $near .= match (true) {
                    self::clear($colour) => "\0",
                    $alphaOnly => "\xFF",
                    default => chr((int) min(255, round($this->distance($colour & 0xFFFFFF, $backdrop)))),
                };
            }
        }

        // 0 = product, 1 = backdrop reached from the edges.
        $mask = str_repeat("\0", $count);
        $stack = [];
        foreach ($border as $index) {
            if (ord($near[$index]) <= $tolerance) {
                $mask[$index] = "\1";
                $stack[] = $index;
            }
        }
        while ($stack !== []) {
            $index = array_pop($stack);
            $x = $index % $width;
            foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                if ($next >= 0 && $next < $count && $mask[$next] === "\0" && ord($near[$next]) <= $tolerance) {
                    $mask[$next] = "\1";
                    $stack[] = $next;
                }
            }
        }

        // The photo's own shadow on the backdrop (the backdrop's tint, only somewhat darker) goes too:
        // the banner draws a shadow for its own scene, and a cream shadow patch would show on a dark
        // one. On a neutral backdrop a grey product has the same tint as a shadow, so nothing is taken
        // there, and a removal that would take a large part of the object is undone.
        $shade = $this->tint($backdrop);
        if (! $alphaOnly && abs($shade[0] - 1 / 3) + abs($shade[1] - 1 / 3) >= 0.012) {
            $before = $mask;
            $stack = [];
            for ($index = 0; $index < $count; $index++) {
                if ($mask[$index] === "\1") {
                    $stack[] = $index;
                }
            }
            $removed = 0;
            while ($stack !== []) {
                $index = array_pop($stack);
                $x = $index % $width;
                foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                    if ($next >= 0 && $next < $count && $mask[$next] === "\0" && $this->isShadow(imagecolorat($image, $next % $width, intdiv($next, $width)), $backdrop, $shade)) {
                        $mask[$next] = "\1";
                        $stack[] = $next;
                        $removed++;
                    }
                }
            }
            if ($removed > 0.35 * substr_count($before, "\0")) {
                $mask = $before;
            }
        }

        // Single-pixel spurs on the outline are JPEG ringing, not product.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($y = 1; $y < $height - 1; $y++) {
                for ($x = 1; $x < $width - 1; $x++) {
                    $index = $y * $width + $x;
                    if ($mask[$index] === "\0" && $this->touchesBackdrop($mask, $index, $x, $y, $width, $height)) {
                        $solid = 0;
                        foreach ([-$width - 1, -$width, -$width + 1, -1, 1, $width - 1, $width, $width + 1] as $offset) {
                            $solid += $mask[$index + $offset] === "\0" ? 1 : 0;
                        }
                        if ($solid <= 2) {
                            $mask[$index] = "\1";
                        }
                    }
                }
            }
        }

        [$keep, $box] = $this->mainObject($mask, $width, $height);
        if ($keep === null) {
            return null;
        }
        [$left, $top, $right, $bottom] = $box;
        $out = imagecreatetruecolor($right - $left + 1, $bottom - $top + 1);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                $index = $y * $width + $x;
                if ($keep[$index] !== "\1") {
                    continue;
                }
                $c = imagecolorat($image, $x, $y);
                $alpha = 1.0;
                if ($this->touchesBackdrop($mask, $index, $x, $y, $width, $height)) {
                    // Edge pixels are part backdrop: blend them out by how close they are to it.
                    $alpha = max(0.0, min(1.0, (ord($near[$index]) - $tolerance) / self::EDGE_RAMP));
                }
                // A transparent PNG's own soft edges keep their transparency.
                $alpha *= (127 - (($c >> 24) & 127)) / 127;
                imagesetpixel($out, $x - $left, $y - $top, imagecolorallocatealpha($out, ($c >> 16) & 255, ($c >> 8) & 255, $c & 255, (int) round(127 * (1 - $alpha))));
            }
        }
        ob_start();
        imagepng($out);
        imagedestroy($out);
        $this->reason = null;

        return (string) ob_get_clean();
    }

    /**
     * The largest connected object, unless another piece beside it is more than JPEG noise: a small
     * detached part (a charm, a cable) would otherwise be dropped from the product without a word.
     *
     * @return array{0: ?string, 1: array{int, int, int, int}}
     */
    private function mainObject(string $mask, int $width, int $height): array
    {
        $count = $width * $height;
        $labels = array_fill(0, $count, 0);
        $sizes = [];
        $boxes = [];
        $label = 0;
        for ($start = 0; $start < $count; $start++) {
            if ($mask[$start] !== "\0" || $labels[$start] !== 0) {
                continue;
            }
            $label++;
            $labels[$start] = $label;
            $stack = [$start];
            $size = 0;
            $box = [$width, $height, -1, -1];
            while ($stack !== []) {
                $index = array_pop($stack);
                $size++;
                $x = $index % $width;
                $y = intdiv($index, $width);
                $box = [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                            continue;
                        }
                        $next = $ny * $width + $nx;
                        if ($mask[$next] === "\0" && $labels[$next] === 0) {
                            $labels[$next] = $label;
                            $stack[] = $next;
                        }
                    }
                }
            }
            $sizes[$label] = $size;
            $boxes[$label] = $box;
        }
        if ($sizes === []) {
            return [null, [0, 0, 0, 0]];
        }
        arsort($sizes);
        $order = array_keys($sizes);
        $main = $order[0];
        $second = isset($order[1]) ? $sizes[$order[1]] : 0;
        $coverage = $sizes[$main] / $count;
        if ($second > self::MAX_SECOND_OBJECT * $sizes[$main]) {
            $this->reason = 'other objects beside the product';

            return [null, [0, 0, 0, 0]];
        }
        if ($second > max(self::STRAY_PIXELS, self::MAX_STRAY * $sizes[$main])) {
            $this->reason = 'a detached piece beside the product';

            return [null, [0, 0, 0, 0]];
        }
        if ($coverage < self::MIN_COVERAGE || $coverage > self::MAX_COVERAGE) {
            $this->reason = 'product too small or too large in the photo';

            return [null, [0, 0, 0, 0]];
        }
        [$left, $top, $right, $bottom] = $boxes[$main];
        // A product touching the frame was cropped by the photographer, so it cannot be shown whole.
        if ($left === 0 || $top === 0 || $right === $width - 1 || $bottom === $height - 1) {
            $this->reason = 'product cropped by the photo edge';

            return [null, [0, 0, 0, 0]];
        }
        $keep = str_repeat("\0", $count);
        for ($index = 0; $index < $count; $index++) {
            if ($labels[$index] === $main) {
                $keep[$index] = "\1";
            }
        }

        return [$keep, $boxes[$main]];
    }

    /** A JPEG turned the way its camera's orientation tag says it is shown. */
    private function upright(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $turn = [3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($turn !== 0 && ($turned = imagerotate($image, $turn, 0)) !== false) {
            imagedestroy($image);
            $image = $turned;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function touchesBackdrop(string $mask, int $index, int $x, int $y, int $width, int $height): bool
    {
        return ($x > 0 && $mask[$index - 1] === "\1")
            || ($x < $width - 1 && $mask[$index + 1] === "\1")
            || ($y > 0 && $mask[$index - $width] === "\1")
            || ($y < $height - 1 && $mask[$index + $width] === "\1");
    }

    /**
     * Distance in a brightness/colour space that weighs a change of tint above a change of brightness,
     * so white pages on a cream backdrop stay apart even though both are light.
     */
    private function distance(int $a, int $b): float
    {
        [$ar, $ag, $ab] = [($a >> 16) & 255, ($a >> 8) & 255, $a & 255];
        [$br, $bg, $bb] = [($b >> 16) & 255, ($b >> 8) & 255, $b & 255];
        $light = ($ar + $ag + $ab - $br - $bg - $bb) / 3;
        $redGreen = ($ar - $ag) - ($br - $bg);
        $yellowBlue = (($ar + $ag) / 2 - $ab) - (($br + $bg) / 2 - $bb);

        return sqrt($light * $light + 2 * $redGreen * $redGreen + 2 * $yellowBlue * $yellowBlue);
    }

    private static function clear(int $colour): bool
    {
        return (($colour >> 24) & 127) >= 120;
    }

    /** @return array{float, float} */
    private function tint(int $colour): array
    {
        [$r, $g, $b] = [($colour >> 16) & 255, ($colour >> 8) & 255, $colour & 255];
        $sum = max(1, $r + $g + $b);

        return [$r / $sum, $g / $sum];
    }

    /** Darker than the backdrop with the backdrop's own tint: its shadow, not a light product edge. */
    private function isShadow(int $colour, int $backdrop, array $shade): bool
    {
        $light = ((($colour >> 16) & 255) + (($colour >> 8) & 255) + ($colour & 255)) / 3;
        $backdropLight = ((($backdrop >> 16) & 255) + (($backdrop >> 8) & 255) + ($backdrop & 255)) / 3;
        if ($light >= $backdropLight - 3 || $light < 0.5 * $backdropLight) {
            return false;
        }
        [$r, $g] = $this->tint($colour & 0xFFFFFF);

        return abs($r - $shade[0]) < 0.008 && abs($g - $shade[1]) < 0.008;
    }

    /** @param  list<int>  $colours */
    private function median(array $colours): int
    {
        $channel = function (int $shift) use ($colours): int {
            $values = array_map(fn (int $c) => ($c >> $shift) & 255, $colours);
            sort($values);

            return $values[intdiv(count($values), 2)];
        };

        return ($channel(16) << 16) | ($channel(8) << 8) | $channel(0);
    }
}
