<?php
/**
 * QR codes for the event pages, for posters and flyers. Generated on the own server.
 *
 * A compact QR encoder (ISO/IEC 18004, byte mode, versions 1–40). The error correction level is
 * raised as far as the version allows, so printed codes stay readable when partly covered.
 * Output as SVG (for print) and PNG (GD), cached in uploads/event-toolkit/qr.
 */

defined('ABSPATH') || exit;

class IMM_TK_QR
{
    const DIR = 'event-toolkit/qr';

    /** Error correction codewords per block, by level (L, M, Q, H) and version. */
    const ECC_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Number of error correction blocks, by level and version. */
    const BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** Format bits of the levels. */
    const FORMAT = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** @var bool[][] */
    private $modules = [];
    /** @var bool[][] */
    private $function = [];
    /** @var int */
    private $size = 0;
    /** @var int */
    public $version = 0;
    /** @var string */
    public $level = 'M';

    /* ------------------------------------------------------------ public helpers */

    /**
     * Module matrix for a text: rows of booleans (true = dark), without quiet zone.
     *
     * @param string $min_level Lowest error correction level; raised if it fits in the same version.
     * @param bool   $boost     Raise the level when possible.
     * @return bool[][]
     */
    public static function matrix(string $text, string $min_level = 'M', bool $boost = true): array
    {
        $qr = new self();
        $qr->encode($text, $min_level, $boost);
        return $qr->modules;
    }

    /** SVG with a quiet zone of 4 modules. */
    public static function svg(string $text, int $module = 10): string
    {
        $m     = self::matrix($text);
        $count = count($m);
        $full  = $count + 8;
        $path  = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $px = $full * $module;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /**
     * PNG (GD) with a quiet zone of 4 modules.
     *
     * @return string|null PNG data, or null without GD.
     */
    public static function png(string $text, int $module = 12): ?string
    {
        if (!function_exists('imagepng')) {
            return null;
        }
        $m    = self::matrix($text);
        $full = (count($m) + 8) * $module;
        $im   = imagecreate($full, $full);
        imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    imagefilledrectangle($im, ($x + 4) * $module, ($y + 4) * $module, ($x + 5) * $module - 1, ($y + 5) * $module - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /** Text the QR code of an event points to: the event page. */
    public static function event_target(IMM_TK_Event $event): string
    {
        return (string) apply_filters('imm_tk_qr_url', $event->url, $event);
    }

    /**
     * Public file of the QR code of an event, created if needed.
     *
     * @param string $format 'svg' or 'png'
     * @return array{path: string, url: string}|WP_Error
     */
    public static function file(int $post_id, string $format = 'svg')
    {
        $event = IMM_TK_Event::from_post($post_id);
        if (!$event) {
            return new WP_Error('imm_tk_qr_event', __('The event was not found or has no valid date.', 'event-toolkit-for-custom-fields'));
        }
        $format  = $format === 'png' ? 'png' : 'svg';
        $target  = self::event_target($event);
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . self::DIR;
        $name    = 'event-' . $event->id . '-' . substr(md5($target . IMM_TK_VERSION), 0, 10) . '.' . $format;
        $path    = $dir . '/' . $name;
        if (!file_exists($path)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('imm_tk_qr_dir', __('The uploads folder is not writable.', 'event-toolkit-for-custom-fields'));
            }
            $data = $format === 'png' ? self::png($target) : self::svg($target);
            if ($data === null || file_put_contents($path, $data) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache in uploads.
                return new WP_Error('imm_tk_qr_write', __('The QR code could not be saved.', 'event-toolkit-for-custom-fields'));
            }
        }
        return ['path' => $path, 'url' => trailingslashit($uploads['baseurl']) . self::DIR . '/' . $name];
    }

    /* ------------------------------------------------------------ encoder */

    private function encode(string $text, string $min_level, bool $boost): void
    {
        $data  = array_values(unpack('C*', $text) ?: []);
        $order = ['L', 'M', 'Q', 'H'];
        $level = in_array($min_level, $order, true) ? $min_level : 'M';

        // Smallest version that holds the data.
        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            if ($this->bits_needed($data, $v) <= self::data_codewords($v, $level) * 8) {
                $version = $v;
                break;
            }
        }
        if (!$version) {
            throw new InvalidArgumentException('Text too long for a QR code.');
        }
        if ($boost) {
            foreach (array_slice($order, array_search($level, $order, true) + 1) as $higher) {
                if ($this->bits_needed($data, $version) <= self::data_codewords($version, $higher) * 8) {
                    $level = $higher;
                }
            }
        }
        $this->version = $version;
        $this->level   = $level;
        $this->size    = $version * 4 + 17;

        // Data bits: byte mode, length, data, terminator, padding.
        $bits = [];
        $this->append($bits, 4, 4);
        $this->append($bits, count($data), $version <= 9 ? 8 : 16);
        foreach ($data as $byte) {
            $this->append($bits, $byte, 8);
        }
        $capacity = self::data_codewords($version, $level) * 8;
        $this->append($bits, 0, min(4, $capacity - count($bits)));
        $this->append($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $this->append($bits, $pad, 8);
        }
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        $this->modules  = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->function = $this->modules;
        $this->draw_function_patterns();
        $this->draw_codewords($this->add_ecc_and_interleave($codewords));

        // Mask with the lowest penalty.
        $best  = 0;
        $score = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->apply_mask($mask);
            $this->draw_format_bits($mask);
            $penalty = $this->penalty();
            if ($penalty < $score) {
                $best  = $mask;
                $score = $penalty;
            }
            $this->apply_mask($mask); // XOR again = undo.
        }
        $this->apply_mask($best);
        $this->draw_format_bits($best);
    }

    private function bits_needed(array $data, int $version): int
    {
        return 4 + ($version <= 9 ? 8 : 16) + 8 * count($data);
    }

    private function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function raw_modules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $align   = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function data_codewords(int $version, string $level): int
    {
        return intdiv(self::raw_modules($version), 8) - self::ECC_PER_BLOCK[$level][$version] * self::BLOCKS[$level][$version];
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x]  = $dark;
        $this->function[$y][$x] = true;
    }

    private function draw_function_patterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $positions = $this->alignment_positions();
        $last      = count($positions) - 1;
        foreach ($positions as $i => $px) {
            foreach ($positions as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->draw_format_bits(0); // Reserve; drawn for real after masking.
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a   = $n - 11 + $i % 3;
                $b   = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function alignment_positions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $count  = intdiv($this->version, 7) + 2;
        $step   = $this->version === 32 ? 26 : (int) ceil(($this->version * 4 + 4) / ($count * 2 - 2)) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $count; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function draw_format_bits(int $mask): void
    {
        $data = (self::FORMAT[$this->level] << 3) | $mask;
        $rem  = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit  = function (int $i) use ($bits): bool {
            return (($bits >> $i) & 1) === 1;
        };
        $n = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true); // The dark module.
    }

    private function add_ecc_and_interleave(array $data): array
    {
        $blocks_count = self::BLOCKS[$this->level][$this->version];
        $ecc_len      = self::ECC_PER_BLOCK[$this->level][$this->version];
        $raw          = intdiv(self::raw_modules($this->version), 8);
        $short_count  = $blocks_count - $raw % $blocks_count;
        $short_len    = intdiv($raw, $blocks_count);
        $divisor      = self::rs_divisor($ecc_len);

        $blocks = [];
        $k      = 0;
        for ($i = 0; $i < $blocks_count; $i++) {
            $len = $short_len - $ecc_len + ($i < $short_count ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k  += $len;
            $ecc = self::rs_remainder($dat, $divisor);
            if ($i < $short_count) {
                $dat[] = 0; // Placeholder, skipped when interleaving.
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $width  = count($blocks[0]);
        for ($i = 0; $i < $width; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $short_len - $ecc_len || $j >= $short_count) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private static function gf_multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function rs_divisor(int $degree): array
    {
        $result          = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root            = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gf_multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gf_multiply($root, 0x02);
        }
        return $result;
    }

    private static function rs_remainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gf_multiply($coef, $factor);
            }
        }
        return $result;
    }

    private function draw_codewords(array $data): void
    {
        $n     = $this->size;
        $total = count($data) * 8;
        $i     = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x      = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y      = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function apply_mask(int $mask): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->function[$y][$x]) {
                    continue;
                }
                switch ($mask) {
                    case 0:
                        $invert = ($x + $y) % 2 === 0;
                        break;
                    case 1:
                        $invert = $y % 2 === 0;
                        break;
                    case 2:
                        $invert = $x % 3 === 0;
                        break;
                    case 3:
                        $invert = ($x + $y) % 3 === 0;
                        break;
                    case 4:
                        $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
                        break;
                    case 5:
                        $invert = $x * $y % 2 + $x * $y % 3 === 0;
                        break;
                    case 6:
                        $invert = ($x * $y % 2 + $x * $y % 3) % 2 === 0;
                        break;
                    default:
                        $invert = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
                }
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty after ISO/IEC 18004 (runs, 2×2 blocks, finder-like patterns, balance). */
    private function penalty(): int
    {
        $n       = $this->size;
        $m       = $this->modules;
        $score   = 0;
        $dark    = 0;
        $pattern = [true, false, true, true, true, false, true];
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $line[$b] === $line[$b - 1]) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + $run - 5;
                    }
                    $run = 1;
                }
                for ($b = 0; $b + 7 <= $n; $b++) {
                    if (array_slice($line, $b, 7) !== $pattern) {
                        continue;
                    }
                    $before = $b >= 4 && !in_array(true, array_slice($line, $b - 4, 4), true);
                    $after  = $b + 11 <= $n && !in_array(true, array_slice($line, $b + 7, 4), true);
                    if ($before || $after) {
                        $score += 40;
                    }
                }
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($x + 1 < $n && $y + 1 < $n && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total  = $n * $n;
        $score += intdiv(abs($dark * 20 - $total * 10), $total) * 10;
        return $score;
    }
}
