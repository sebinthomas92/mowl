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

    /** An enclosed backdrop patch at least this large is an opening in the product, not a highlight. */
    private const HOLE_PIXELS = 64;

    /** How much further from the backdrop colour than the border's typical pixel an opening may be. */
    private const HOLE_MATCH = 4.0;

    /** An opening is at least this wide on average; a thinner backdrop-coloured sliver is a lit gutter or crest. */
    private const HOLE_WIDTH = 6;

    /** Below this opacity an edge pixel's colour is too little product to recover reliably. */
    private const MIN_UNMIX_ALPHA = 0.25;

    /** How much darker a shadow pixel may be than the neighbour the shadow fill reached it from. */
    private const MAX_SHADOW_STEP = 16.0;

    /**
     * How far a shadow's tint may drift from the backdrop's: a little for JPEG noise, plus more the
     * darker it is, since a shadow on a warm paper is more saturated than the paper.
     */
    private const SHADOW_TINT = 0.008;

    private const SHADOW_TINT_SLOPE = 0.12;

    /** Backdrop-coloured gaps at most this wide between product on both sides of a row are product (a page gutter). */
    private const NOTCH_WIDTH = 8;

    /** A product may touch the frame along at most this share of its own extent on that side. */
    private const MAX_EDGE_CONTACT = 0.05;

    /** More separate pieces than this is speckle or a busy scene, not one product on a backdrop. */
    private const MAX_PIECES = 2000;

    private const MIN_COVERAGE = 0.03;

    private const MAX_COVERAGE = 0.85;

    /** How many pixels in from the backdrop an antialiased outline can blend into it. */
    private const EDGE_DEPTH = 3;

    /** How far (RGB distance) an outline pixel can be from a backdrop-product blend and still be one. */
    private const BLEND_MATCH = 18.0;

    /** Edge pixels this far beyond the backdrop tolerance are fully opaque; closer ones are blended. */
    private const EDGE_RAMP = 28.0;

    /** Why the last photo was not cut out, for the error a banner records. */
    public ?string $reason = null;

    /**
     * The backdrop along each side of the photo (top, bottom, left, right), smoothed, as [r, g, b] per
     * pixel. A studio backdrop is rarely one colour: a soft light falloff from top to bottom spread
     * pixel distances from a single median colour so widely that the tolerance took in the stand's
     * lit white pages, and the fill split the stand into pieces. Each pixel is compared with the
     * backdrop blended in from the four sides instead.
     *
     * @var array<string, list<array{float, float, float}>>
     */
    private array $sides = [];

    public function cut(string $bytes): ?string
    {
        $this->reason = 'unreadable image';
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > ProductImageImporter::MAX_PIXELS) {
            return null;
        }
        if (max($size[0], $size[1]) < ProductImageImporter::MIN_SIDE) {
            $this->reason = 'photo too small';

            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }
        imagepalettetotruecolor($source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $factor = min(1, self::MAX_SIDE / max(imagesx($source), imagesy($source)));
        $image = $source;
        if ($factor < 1) {
            [$width, $height] = [max(1, (int) round(imagesx($source) * $factor)), max(1, (int) round(imagesy($source) * $factor))];
            // Some GD builds return false for bicubic scaling (PHP 8.5 with GD 2.3.3); bilinear works everywhere.
            $image = imagescale($source, $width, $height, IMG_BICUBIC) ?: imagescale($source, $width, $height, IMG_BILINEAR_FIXED);
            // The full-size photo is not held while the cutout allocates its masks.
            imagedestroy($source);
        }
        if (! $image) {
            $this->reason = 'photo could not be scaled';

            return null;
        }
        // Turned after scaling, so no second full-size copy is held during the rotation.
        if ($size[2] === IMAGETYPE_JPEG) {
            $image = $this->upright($image, $bytes);
        }

        try {
            return $this->cutImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function cutImage(\GdImage $image): ?string
    {
        $this->sides = [];
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
        $typical = 0.0;
        if (! $alphaOnly) {
            $backdrop = $this->median(array_map(fn (int $colour) => $colour & 0xFFFFFF, $opaque));
            $distances = array_map(fn (int $colour) => self::clear($colour) ? 0.0 : $this->distance($colour & 0xFFFFFF, $backdrop), $borderColours);
            sort($distances);
            $plain = count(array_filter($distances, fn (float $distance) => $distance <= self::PLAIN_DISTANCE)) / count($distances);
            if ($plain < self::MIN_PLAIN_BORDER) {
                $this->reason = 'not a plain backdrop';

                return null;
            }
            $this->sides = $this->sides($image, $ring, $backdrop);
            $distances = [];
            foreach ($border as $i => $index) {
                $distances[] = self::clear($borderColours[$i]) ? 0.0 : $this->distance($borderColours[$i] & 0xFFFFFF, $this->backdropAt($index % $width, intdiv($index, $width), $width, $height));
            }
            sort($distances);
            // What is left of the backdrop's unevenness (JPEG noise, a mottled paper) sets how close counts as backdrop.
            $tolerance = (int) min(40, max(14, ceil($distances[(int) floor(0.95 * (count($distances) - 1))] + 8)));
            $typical = $distances[intdiv(count($distances) - 1, 2)];
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
                    default => chr((int) min(255, round($this->distance($colour & 0xFFFFFF, $this->backdropAt($x, $y, $width, $height))))),
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

        // Backdrop seen through a closed opening (a mug or bag handle, a ring) is not reached from the
        // edges. A patch larger than a specular highlight that is, on average, as close to the backdrop
        // colour as the border itself is backdrop too. A product surface of a near colour (a white
        // dial on cream) sits measurably further away and stays.
        $seen = $mask;
        $openings = str_repeat("\0", $count);
        $hole = max(self::HOLE_PIXELS, (int) round(0.0002 * $count));
        for ($start = 0; $start < $count; $start++) {
            if ($seen[$start] !== "\0" || ord($near[$start]) > $tolerance) {
                continue;
            }
            $seen[$start] = "\1";
            $stack = [$start];
            $patch = [];
            $sum = 0;
            $box = [$width, $height, -1, -1];
            while ($stack !== []) {
                $index = array_pop($stack);
                $patch[] = $index;
                $sum += ord($near[$index]);
                $x = $index % $width;
                $box = [min($box[0], $x), min($box[1], intdiv($index, $width)), max($box[2], $x), max($box[3], intdiv($index, $width))];
                foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                    if ($next >= 0 && $next < $count && $seen[$next] === "\0" && ord($near[$next]) <= $tolerance) {
                        $seen[$next] = "\1";
                        $stack[] = $next;
                    }
                }
            }
            $long = max($box[2] - $box[0], $box[3] - $box[1]) + 1;
            if (count($patch) >= $hole && count($patch) / $long >= self::HOLE_WIDTH && $sum / count($patch) <= $typical + self::HOLE_MATCH) {
                foreach ($patch as $index) {
                    $mask[$index] = "\1";
                    $openings[$index] = "\1";
                }
            }
        }

        // The photo's own shadow on the backdrop (the backdrop's tint, only somewhat darker) goes too:
        // the banner draws a shadow for its own scene, and a cream shadow patch would show on a dark
        // one. On a neutral backdrop a grey product has the same tint as a shadow, so nothing is taken
        // there, and a removal that would take a large part of the object is undone. A shadow darkens
        // gradually from the backdrop; a product part of the same hue starts at a sharp outline, which
        // the fill does not cross.
        $shade = $this->tint($backdrop);
        if (! $alphaOnly && abs($shade[0] - 1 / 3) + abs($shade[1] - 1 / 3) >= 0.012) {
            [$rows, $columns] = $this->productSpans($image, $mask);
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
                $from = self::light(imagecolorat($image, $x, intdiv($index, $width)));
                foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                    if ($next < 0 || $next >= $count || $mask[$next] !== "\0") {
                        continue;
                    }
                    [$nx, $ny] = [$next % $width, intdiv($next, $width)];
                    // Shading inside the product (a page gutter, the band under a ledge) has the
                    // backdrop's tint too; only what lies outside the product's own extent is shadow.
                    if (($nx >= $rows[$ny][0] && $nx <= $rows[$ny][1]) || ($ny >= $columns[$nx][0] && $ny <= $columns[$nx][1])) {
                        continue;
                    }
                    $colour = imagecolorat($image, $nx, $ny);
                    $local = $this->backdropAt($nx, $ny, $width, $height);
                    if (self::light($colour) >= $from - self::MAX_SHADOW_STEP && $this->isShadow($colour, $local, $this->tint($local))) {
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

        // Backdrop-coloured patches the product closes in on every side are product (a lit page),
        // unless they were taken as an opening above.
        $outside = str_repeat("\0", $count);
        $stack = [];
        foreach ($border as $index) {
            if ($mask[$index] === "\1") {
                $outside[$index] = "\1";
                $stack[] = $index;
            }
        }
        while ($stack !== []) {
            $index = array_pop($stack);
            $x = $index % $width;
            foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                if ($next >= 0 && $next < $count && $mask[$next] === "\1" && $outside[$next] === "\0") {
                    $outside[$next] = "\1";
                    $stack[] = $next;
                }
            }
        }
        for ($index = 0; $index < $count; $index++) {
            if ($mask[$index] === "\1" && $outside[$index] === "\0" && $openings[$index] === "\0") {
                $mask[$index] = "\0";
            }
        }
        unset($outside);

        // A narrow backdrop-coloured gap with product on both sides of the row (a lit page gutter
        // that opens at the top) is product too.
        for ($y = 0; $y < $height; $y++) {
            $last = -1;
            for ($x = 0; $x < $width; $x++) {
                $index = $y * $width + $x;
                if ($mask[$index] === "\1") {
                    continue;
                }
                if ($last >= 0 && $x - $last - 1 > 0 && $x - $last - 1 <= self::NOTCH_WIDTH) {
                    $open = false;
                    for ($gap = $last + 1; $gap < $x; $gap++) {
                        $open = $open || $openings[$y * $width + $gap] === "\1";
                    }
                    if (! $open) {
                        for ($gap = $last + 1; $gap < $x; $gap++) {
                            $mask[$y * $width + $gap] = "\0";
                        }
                    }
                }
                $last = $x;
            }
        }
        unset($openings);

        // Single-pixel spurs on the outline are JPEG ringing, not product.
        for ($pass = 0; $pass < 2; $pass++) {
            for ($y = 1; $y < $height - 1; $y++) {
                for ($x = 1; $x < $width - 1; $x++) {
                    $index = $y * $width + $x;
                    // Only pixels close to the backdrop: a thin wire or chain stands well apart from it.
                    if ($mask[$index] === "\0" && ord($near[$index]) < $tolerance + self::EDGE_RAMP && $this->touchesBackdrop($mask, $index, $x, $y, $width, $height)) {
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

        [$keep, $box] = $this->mainObject($mask, $width, $height, $alphaOnly);
        if ($keep === null) {
            return null;
        }
        [$left, $top, $right, $bottom] = $box;
        // An antialiased outline is a few pixels wide, each a blend of product and backdrop, so the kept
        // pixels within EDGE_DEPTH steps of the backdrop are all feathered, not only the outermost one.
        $edge = str_repeat("\0", $count);
        $front = [];
        for ($index = 0; $index < $count; $index++) {
            if ($keep[$index] === "\1" && $this->touchesBackdrop($mask, $index, $index % $width, intdiv($index, $width), $width, $height)) {
                $edge[$index] = "\1";
                $front[] = $index;
            }
        }
        for ($step = 1; $step < self::EDGE_DEPTH && $front !== []; $step++) {
            $next = [];
            foreach ($front as $index) {
                $x = $index % $width;
                foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $side) {
                    if ($side >= 0 && $side < $count && $keep[$side] === "\1" && $edge[$side] === "\0") {
                        $edge[$side] = "\1";
                        $next[] = $side;
                    }
                }
            }
            $front = $next;
        }
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
                $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $alpha = 1.0;
                if ($edge[$index] === "\1") {
                    // Edge pixels are part backdrop: blend them out by how close they are to it.
                    $alpha = max(0.0, min(1.0, (ord($near[$index]) - $tolerance) / self::EDGE_RAMP));
                    $local = $alphaOnly ? 0 : $this->backdropAt($x, $y, $width, $height);
                    if (! $alphaOnly) {
                        $alpha = min($alpha, $this->blendShare($image, $keep, $rgb, $local, $x, $y, $width, $height));
                    }
                    if (! $alphaOnly && $alpha > 0.0 && $alpha < 1.0) {
                        // Take the backdrop's share out of the colour too, or it shows as a light fringe on a
                        // darker scene: pixel = alpha * product + (1 - alpha) * backdrop.
                        $from = [($local >> 16) & 255, ($local >> 8) & 255, $local & 255];
                        $share = max($alpha, self::MIN_UNMIX_ALPHA);
                        foreach ($rgb as $channel => $value) {
                            $rgb[$channel] = (int) max(0, min(255, round(($value - (1 - $share) * $from[$channel]) / $share)));
                        }
                    }
                }
                // A transparent PNG's own soft edges keep their transparency.
                $alpha *= (127 - (($c >> 24) & 127)) / 127;
                imagesetpixel($out, $x - $left, $y - $top, imagecolorallocatealpha($out, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $alpha))));
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
    private function mainObject(string $mask, int $width, int $height, bool $alphaOnly = false): array
    {
        $count = $width * $height;
        $labels = array_fill(0, $count, 0);
        // Only the two largest pieces matter, so per-piece bookkeeping stays constant however speckled
        // the photo is, and a photo in thousands of pieces is not a studio shot at all.
        $main = 0;
        $mainSize = 0;
        $mainBox = [0, 0, 0, 0];
        $second = 0;
        $label = 0;
        for ($start = 0; $start < $count; $start++) {
            if ($mask[$start] !== "\0" || $labels[$start] !== 0) {
                continue;
            }
            $label++;
            if ($label > self::MAX_PIECES) {
                $this->reason = 'not a plain backdrop';

                return [null, [0, 0, 0, 0]];
            }
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
            if ($size > $mainSize) {
                $second = $mainSize;
                [$main, $mainSize, $mainBox] = [$label, $size, $box];
            } elseif ($size > $second) {
                $second = $size;
            }
        }
        if ($main === 0) {
            return [null, [0, 0, 0, 0]];
        }
        $coverage = $mainSize / $count;
        if ($second > self::MAX_SECOND_OBJECT * $mainSize) {
            $this->reason = 'other objects beside the product';

            return [null, [0, 0, 0, 0]];
        }
        if ($second > max(self::STRAY_PIXELS, self::MAX_STRAY * $mainSize)) {
            $this->reason = 'a detached piece beside the product';

            return [null, [0, 0, 0, 0]];
        }
        // A transparent PNG's own clear margin already separates the product, however slim it is.
        if ($coverage < self::MIN_COVERAGE || (! $alphaOnly && $coverage > self::MAX_COVERAGE)) {
            $this->reason = 'product too small or too large in the photo';

            return [null, [0, 0, 0, 0]];
        }
        [$left, $top, $right, $bottom] = $mainBox;
        // A product touching the frame was cropped by the photographer, so it cannot be shown whole;
        // only a corner grazing the edge over a small share of the product's own extent is allowed.
        foreach (['left' => $left === 0, 'top' => $top === 0, 'right' => $right === $width - 1, 'bottom' => $bottom === $height - 1] as $side => $touches) {
            if (! $touches) {
                continue;
            }
            $vertical = $side === 'left' || $side === 'right';
            $line = match ($side) {
                'left', 'top' => 0,
                'right' => $width - 1,
                'bottom' => $height - 1,
            };
            $contact = 0;
            for ($at = 0, $length = $vertical ? $height : $width; $at < $length; $at++) {
                $contact += $labels[$vertical ? $at * $width + $line : $line * $width + $at] === $main ? 1 : 0;
            }
            $extent = $vertical ? $bottom - $top + 1 : $right - $left + 1;
            if ($contact > self::MAX_EDGE_CONTACT * $extent) {
                $this->reason = 'product cropped by the photo edge';

                return [null, [0, 0, 0, 0]];
            }
        }
        $keep = str_repeat("\0", $count);
        for ($index = 0; $index < $count; $index++) {
            if ($labels[$index] === $main) {
                $keep[$index] = "\1";
            }
        }

        return [$keep, $mainBox];
    }

    /**
     * Each side's backdrop colour along its length: the ring's pixels near the backdrop colour averaged
     * across the ring, gaps (a prop or shadow at the edge) bridged from their neighbours, and smoothed
     * so JPEG noise does not carry inward.
     *
     * @return array<string, list<array{float, float, float}>>
     */
    private function sides(\GdImage $image, int $ring, int $backdrop): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $sides = [];
        foreach (['top' => [$width, false, 0], 'bottom' => [$width, false, $height - $ring], 'left' => [$height, true, 0], 'right' => [$height, true, $width - $ring]] as $side => [$length, $vertical, $offset]) {
            $profile = [];
            for ($at = 0; $at < $length; $at++) {
                $sum = [0, 0, 0];
                $n = 0;
                for ($depth = $offset; $depth < $offset + $ring; $depth++) {
                    $colour = $vertical ? imagecolorat($image, $depth, $at) : imagecolorat($image, $at, $depth);
                    if (self::clear($colour) || $this->distance($colour & 0xFFFFFF, $backdrop) > self::PLAIN_DISTANCE) {
                        continue;
                    }
                    $sum = [$sum[0] + (($colour >> 16) & 255), $sum[1] + (($colour >> 8) & 255), $sum[2] + ($colour & 255)];
                    $n++;
                }
                $profile[] = $n > 0 ? [$sum[0] / $n, $sum[1] / $n, $sum[2] / $n] : null;
            }
            $known = array_keys(array_filter($profile, fn ($value) => $value !== null));
            $fallback = [($backdrop >> 16) & 255, ($backdrop >> 8) & 255, $backdrop & 255];
            $before = null;
            foreach ($profile as $at => $value) {
                if ($value !== null) {
                    $before = $at;

                    continue;
                }
                $after = null;
                foreach ($known as $candidate) {
                    if ($candidate > $at) {
                        $after = $candidate;
                        break;
                    }
                }
                $profile[$at] = match (true) {
                    $before === null && $after === null => $fallback,
                    $before === null => $profile[$after],
                    $after === null => $profile[$before],
                    default => array_map(fn ($a, $b) => $a + ($b - $a) * ($at - $before) / ($after - $before), $profile[$before], $profile[$after]),
                };
            }
            $half = max(2, intdiv($length, 60));
            $smooth = [];
            for ($at = 0; $at < $length; $at++) {
                $sum = [0.0, 0.0, 0.0];
                $from = max(0, $at - $half);
                $to = min($length - 1, $at + $half);
                for ($near = $from; $near <= $to; $near++) {
                    $sum = [$sum[0] + $profile[$near][0], $sum[1] + $profile[$near][1], $sum[2] + $profile[$near][2]];
                }
                $smooth[] = [$sum[0] / ($to - $from + 1), $sum[1] / ($to - $from + 1), $sum[2] / ($to - $from + 1)];
            }
            $sides[$side] = $smooth;
        }

        return $sides;
    }

    /** The backdrop colour at a pixel, blended in from the four sides (a Coons patch over the photo). */
    private function backdropAt(int $x, int $y, int $width, int $height): int
    {
        $u = $width > 1 ? $x / ($width - 1) : 0.0;
        $v = $height > 1 ? $y / ($height - 1) : 0.0;
        ['top' => $top, 'bottom' => $bottom, 'left' => $left, 'right' => $right] = $this->sides;
        $colour = 0;
        for ($channel = 0; $channel < 3; $channel++) {
            $corners = (1 - $u) * (1 - $v) * ($top[0][$channel] + $left[0][$channel]) / 2
                + $u * (1 - $v) * ($top[$width - 1][$channel] + $right[0][$channel]) / 2
                + (1 - $u) * $v * ($bottom[0][$channel] + $left[$height - 1][$channel]) / 2
                + $u * $v * ($bottom[$width - 1][$channel] + $right[$height - 1][$channel]) / 2;
            $value = (1 - $v) * $top[$x][$channel] + $v * $bottom[$x][$channel] + (1 - $u) * $left[$y][$channel] + $u * $right[$y][$channel] - $corners;
            $colour = ($colour << 8) | (int) max(0, min(255, round($value)));
        }

        return $colour;
    }

    /**
     * The product's extent along each row and column ([first, last], or [1, 0] for none), from the
     * pixels that are neither backdrop nor shadow-coloured, ignoring specks.
     *
     * @return array{0: list<array{int, int}>, 1: list<array{int, int}>}
     */
    private function productSpans(\GdImage $image, string $mask): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $count = $width * $height;
        $core = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = $y * $width + $x;
                if ($mask[$index] !== "\0") {
                    $core .= "\0";

                    continue;
                }
                $local = $this->backdropAt($x, $y, $width, $height);
                // Looser than the fill's own test: the darkest, most saturated core of a cast shadow
                // must not count as product and stretch the extent over the shadow.
                $core .= $this->isShadow(imagecolorat($image, $x, $y), $local, $this->tint($local), 0.03, 0.12, 0.35) ? "\0" : "\1";
            }
        }
        $pieces = [];
        $largest = 0;
        for ($start = 0; $start < $count; $start++) {
            if ($core[$start] !== "\1") {
                continue;
            }
            $core[$start] = "\2";
            $stack = [$start];
            $piece = [];
            while ($stack !== []) {
                $index = array_pop($stack);
                $piece[] = $index;
                $x = $index % $width;
                foreach ([$x > 0 ? $index - 1 : -1, $x < $width - 1 ? $index + 1 : -1, $index - $width, $index + $width] as $next) {
                    if ($next >= 0 && $next < $count && $core[$next] === "\1") {
                        $core[$next] = "\2";
                        $stack[] = $next;
                    }
                }
            }
            if (count($piece) >= self::STRAY_PIXELS) {
                $pieces[] = $piece;
                $largest = max($largest, count($piece));
            }
        }
        $rows = array_fill(0, $height, [1, 0]);
        $columns = array_fill(0, $width, [1, 0]);
        foreach ($pieces as $piece) {
            if (count($piece) < self::MAX_STRAY * $largest) {
                continue;
            }
            foreach ($piece as $index) {
                [$x, $y] = [$index % $width, intdiv($index, $width)];
                $rows[$y] = $rows[$y][0] > $rows[$y][1] ? [$x, $x] : [min($rows[$y][0], $x), max($rows[$y][1], $x)];
                $columns[$x] = $columns[$x][0] > $columns[$x][1] ? [$y, $y] : [min($columns[$x][0], $y), max($columns[$x][1], $y)];
            }
        }

        return [$rows, $columns];
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

    /**
     * How much of the product an outline pixel holds, when it lies on the line between the backdrop
     * and the nearby product colour (the kept pixel within EDGE_DEPTH that is furthest from the
     * backdrop). A pixel off that line, such as a trim of another colour, is product: 1.
     *
     * @param  array{int, int, int}  $rgb
     */
    private function blendShare(\GdImage $image, string $keep, array $rgb, int $backdrop, int $x, int $y, int $width, int $height): float
    {
        $from = [($backdrop >> 16) & 255, ($backdrop >> 8) & 255, $backdrop & 255];
        $product = null;
        $furthest = 0;
        for ($dy = -self::EDGE_DEPTH; $dy <= self::EDGE_DEPTH; $dy++) {
            for ($dx = -self::EDGE_DEPTH; $dx <= self::EDGE_DEPTH; $dx++) {
                [$nx, $ny] = [$x + $dx, $y + $dy];
                if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height || $keep[$ny * $width + $nx] !== "\1") {
                    continue;
                }
                $c = imagecolorat($image, $nx, $ny);
                $candidate = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $far = ($candidate[0] - $from[0]) ** 2 + ($candidate[1] - $from[1]) ** 2 + ($candidate[2] - $from[2]) ** 2;
                if ($far > $furthest) {
                    [$furthest, $product] = [$far, $candidate];
                }
            }
        }
        if ($product === null || $furthest < 40 ** 2) {
            return 1.0;
        }
        $share = 0.0;
        for ($channel = 0; $channel < 3; $channel++) {
            $share += ($rgb[$channel] - $from[$channel]) * ($product[$channel] - $from[$channel]);
        }
        $share = max(0.0, min(1.0, $share / $furthest));
        $off = 0.0;
        for ($channel = 0; $channel < 3; $channel++) {
            $off += ($rgb[$channel] - ($from[$channel] + $share * ($product[$channel] - $from[$channel]))) ** 2;
        }

        return sqrt($off) <= self::BLEND_MATCH ? $share : 1.0;
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

    private static function light(int $colour): float
    {
        return ((($colour >> 16) & 255) + (($colour >> 8) & 255) + ($colour & 255)) / 3;
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
    private function isShadow(int $colour, int $backdrop, array $shade, float $tint = self::SHADOW_TINT, float $slope = self::SHADOW_TINT_SLOPE, float $darkest = 0.5): bool
    {
        $light = ((($colour >> 16) & 255) + (($colour >> 8) & 255) + ($colour & 255)) / 3;
        $backdropLight = ((($backdrop >> 16) & 255) + (($backdrop >> 8) & 255) + ($backdrop & 255)) / 3;
        if ($light >= $backdropLight - 3 || $light < $darkest * $backdropLight) {
            return false;
        }
        [$r, $g] = $this->tint($colour & 0xFFFFFF);
        $drift = $tint + $slope * (1 - $light / $backdropLight);

        return abs($r - $shade[0]) < $drift && abs($g - $shade[1]) < $drift;
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
