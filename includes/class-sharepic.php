<?php
/**
 * Story images (1080 × 1920 JPEG) for Instagram, WhatsApp status, Signal stories and the like.
 *
 * A frame is described by a spec:
 * - ['type' => 'event', 'variant' => 'today'|'tomorrow', 'id' => 123]
 * - ['type' => 'list',  'variant' => 'week'|'new'|'today'|'tomorrow', 'ids' => [..], 'page' => 1, 'pages' => 2]
 *
 * Images are rendered with GD and cached in uploads/event-toolkit/stories. The file name contains
 * a hash of the spec, the event data and the design settings, so a changed event gets a new file.
 */

defined('ABSPATH') || exit;

class IMM_TK_Sharepic
{
    const W      = 1080;
    const H      = 1920;
    const MARGIN = 96;
    /** Top of the content. Instagram shows the progress bar and the account name above it. */
    const TOP = 236;
    /** Lowest baseline. Instagram shows the reply bar below it. */
    const BOTTOM = 1640;
    /** Rows per list frame. */
    const ROWS = 4;
    /** More events than this on one day are shown as one list instead of single frames. */
    const MAX_SINGLE = 3;
    const DIR = 'event-toolkit/stories';
    /** Raise when the layout changes, so cached images are rendered again. */
    const DESIGN = 2;

    /** Rendering needs GD with FreeType and JPEG support. */
    public static function available(): bool
    {
        if (!function_exists('imagettftext') || !function_exists('imagejpeg') || !function_exists('imagerotate')) {
            return false;
        }
        foreach (self::fonts() as $file) {
            if (!is_readable($file)) {
                return false;
            }
        }
        return true;
    }

    /** Font files: the website's fonts or the bundled ones (see IMM_TK_Fonts). */
    public static function fonts(): array
    {
        static $fonts = null;
        if ($fonts === null) {
            $fonts = IMM_TK_Fonts::files();
        }
        return $fonts;
    }

    /* ------------------------------------------------------------ frames */

    /**
     * Frames of a weekly preview: the coming days (with pictures), then the new events.
     *
     * @return array[] specs
     */
    public static function week_frames(array $selection): array
    {
        $frames = [];
        foreach (['week', 'new'] as $part) {
            $ids    = array_map(function (IMM_TK_Event $e) {
                return $e->id;
            }, $selection[$part]);
            $chunks = array_chunk($ids, self::ROWS);
            foreach ($chunks as $i => $chunk) {
                $frames[] = ['type' => 'list', 'variant' => $part, 'ids' => $chunk, 'page' => $i + 1, 'pages' => count($chunks)];
            }
        }
        return $frames;
    }

    /**
     * Frames announcing the events of one day: one frame per event, or a list if there are many.
     *
     * @param IMM_TK_Event[] $events
     * @param string         $variant 'today' or 'tomorrow'
     * @return array[] specs
     */
    public static function day_frames(array $events, string $variant): array
    {
        if (!$events) {
            return [];
        }
        if (count($events) > self::MAX_SINGLE) {
            $ids    = array_map(function (IMM_TK_Event $e) {
                return $e->id;
            }, $events);
            $chunks = array_chunk($ids, self::ROWS);
            $frames = [];
            foreach ($chunks as $i => $chunk) {
                $frames[] = ['type' => 'list', 'variant' => $variant, 'ids' => $chunk, 'page' => $i + 1, 'pages' => count($chunks)];
            }
            return $frames;
        }
        return array_map(function (IMM_TK_Event $e) use ($variant) {
            return ['type' => 'event', 'variant' => $variant, 'id' => $e->id];
        }, $events);
    }

    /** Normalises a spec from untrusted input. Returns null if it is not valid. */
    public static function clean_spec($spec): ?array
    {
        if (!is_array($spec) || !isset($spec['type'], $spec['variant'])) {
            return null;
        }
        if ($spec['type'] === 'event' && in_array($spec['variant'], ['today', 'tomorrow'], true) && !empty($spec['id'])) {
            return ['type' => 'event', 'variant' => $spec['variant'], 'id' => absint($spec['id'])];
        }
        if ($spec['type'] === 'list' && in_array($spec['variant'], ['week', 'new', 'today', 'tomorrow'], true) && !empty($spec['ids']) && is_array($spec['ids'])) {
            $ids   = array_slice(array_values(array_filter(array_map('absint', $spec['ids']))), 0, self::ROWS);
            $pages = max(1, absint($spec['pages'] ?? 1));
            return $ids ? ['type' => 'list', 'variant' => $spec['variant'], 'ids' => $ids, 'page' => min($pages, max(1, absint($spec['page'] ?? 1))), 'pages' => $pages] : null;
        }
        return null;
    }

    /* ------------------------------------------------------------ files */

    /**
     * Path and URL of the image for a spec, rendered if needed.
     *
     * @return array{path: string, url: string}|WP_Error
     */
    public static function file(array $spec)
    {
        $spec = self::clean_spec($spec);
        if (!$spec) {
            return new WP_Error('imm_tk_sharepic_spec', __('Unknown image.', 'event-toolkit-for-custom-fields'));
        }
        if (!self::available()) {
            return new WP_Error('imm_tk_sharepic_gd', __('Story images need the PHP extension GD with FreeType and JPEG support.', 'event-toolkit-for-custom-fields'));
        }
        $events = self::events($spec);
        if (!$events) {
            return new WP_Error('imm_tk_sharepic_event', __('The event was not found or has no valid date.', 'event-toolkit-for-custom-fields'));
        }

        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . self::DIR;
        $name    = $spec['variant'] . '-' . $events[0]->start->format('Ymd') . '-' . substr(md5(self::fingerprint($spec, $events)), 0, 12) . '.jpg';
        $path    = $dir . '/' . $name;
        $url     = trailingslashit($uploads['baseurl']) . self::DIR . '/' . $name;

        if (!file_exists($path)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('imm_tk_sharepic_dir', __('The uploads folder is not writable.', 'event-toolkit-for-custom-fields'));
            }
            $image = $spec['type'] === 'event' ? self::render_event($events[0], $spec['variant']) : self::render_list($events, $spec);
            $ok    = imagejpeg($image, $path, 88);
            imagedestroy($image);
            if (!$ok) {
                return new WP_Error('imm_tk_sharepic_write', __('The image could not be saved.', 'event-toolkit-for-custom-fields'));
            }
            self::cleanup($dir);
        }
        return ['path' => $path, 'url' => $url];
    }

    /** @return IMM_TK_Event[] */
    private static function events(array $spec): array
    {
        $ids    = $spec['type'] === 'event' ? [$spec['id']] : $spec['ids'];
        $events = [];
        foreach ($ids as $id) {
            $event = IMM_TK_Event::from_post($id);
            if ($event) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /** Everything the image depends on. */
    private static function fingerprint(array $spec, array $events): string
    {
        $data = [$spec, IMM_TK_VERSION, self::DESIGN, self::style(), array_map('basename', self::fonts()), get_locale(), get_option('time_format')];
        foreach ($events as $e) {
            $data[] = [$e->id, $e->title, $e->start->format('c'), $e->main_start->format('c'), $e->doors ? $e->doors->format('c') : '', $e->location, get_post_thumbnail_id($e->id), $e->modified->format('c')];
        }
        return (string) wp_json_encode($data);
    }

    /** Deletes images older than three weeks. Runs at most once a day. */
    private static function cleanup(string $dir): void
    {
        if (get_transient('imm_tk_sharepic_cleanup')) {
            return;
        }
        set_transient('imm_tk_sharepic_cleanup', 1, DAY_IN_SECONDS);
        foreach ((array) glob($dir . '/*.jpg') as $file) {
            if ($file && filemtime($file) < time() - 21 * DAY_IN_SECONDS) {
                wp_delete_file($file);
            }
        }
    }

    /* ------------------------------------------------------------ style */

    /** Colours and footer text from the settings. */
    public static function style(): array
    {
        $footer = trim((string) IMM_TK_Settings::get('sharepic_footer'));
        if ($footer === '') {
            $footer = preg_replace('#^https?://(www\.)?#', '', untrailingslashit(IMM_TK_Digest::program_url()));
        }
        return [
            'paper'  => (string) IMM_TK_Settings::get('sharepic_paper'),
            'ink'    => (string) IMM_TK_Settings::get('sharepic_ink'),
            'accent' => (string) IMM_TK_Settings::get('sharepic_accent'),
            'footer' => $footer,
        ];
    }

    /** Colours of a frame. "today" is dark, everything else light, so the two reminders look different. */
    private static function palette(bool $dark): array
    {
        $s     = self::style();
        $bg    = self::rgb($dark ? $s['ink'] : $s['paper']);
        $fg    = self::rgb($dark ? $s['paper'] : $s['ink']);
        return [
            'bg'     => $bg,
            'fg'     => $fg,
            'muted'  => self::mix($bg, $fg, $dark ? 0.68 : 0.64),
            'accent' => $dark ? self::mix(self::rgb($s['accent']), [255, 255, 255], 0.18) : self::rgb($s['accent']),
            'shadow' => self::mix($bg, [0, 0, 0], $dark ? 0.55 : 0.22),
            'white'  => [255, 255, 255],
            'ink'    => self::rgb($s['ink']),
            'blank'  => self::mix([255, 255, 255], self::rgb($s['ink']), 0.07),
        ];
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /** @param resource|GdImage $im */
    private static function color($im, array $rgb): int
    {
        return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }

    /* ------------------------------------------------------------ text helpers */

    /** GD sizes are points at 96 dpi; we think in pixels. */
    private static function pt(float $px): float
    {
        return $px * 0.75;
    }

    private static function width(string $font, float $px, string $text): int
    {
        $box = imagettfbbox(self::pt($px), 0, $font, $text);
        return $box ? (int) ($box[2] - $box[0]) : 0;
    }

    /** Splits text into lines that fit $max pixels. Words longer than a line are kept whole. */
    private static function wrap(string $font, float $px, string $text, int $max): array
    {
        $lines = [];
        $line  = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && self::width($font, $px, $try) > $max) {
                $lines[] = $line;
                $line    = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Largest size between $max_px and $min_px at which the text fits in $max_lines lines
     * (and no single word is wider than the line). At the minimum size the text is shortened.
     *
     * @return array{0: float, 1: string[]}
     */
    private static function fit(string $font, string $text, int $width, float $max_px, float $min_px, int $max_lines): array
    {
        for ($px = $max_px; $px >= $min_px; $px -= 2) {
            $lines = self::wrap($font, $px, $text, $width);
            $wide  = false;
            foreach ($lines as $line) {
                if (self::width($font, $px, $line) > $width) {
                    $wide = true;
                    break;
                }
            }
            if (!$wide && count($lines) <= $max_lines) {
                return [$px, $lines];
            }
        }
        $lines = array_slice(self::wrap($font, $min_px, $text, $width), 0, $max_lines);
        $last  = $lines[$max_lines - 1] ?? '';
        while ($last !== '' && self::width($font, $min_px, $last . '…') > $width) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[count($lines) - 1] = rtrim($last) . '…';
        return [$min_px, $lines];
    }

    /**
     * Short info line: one line if it fits at $min_one or larger, else up to two lines,
     * so that no single word ends up alone on a second line.
     *
     * @return array{0: float, 1: string[]}
     */
    private static function fit_short(string $font, string $text, int $width, float $max_px, float $min_one, float $min_two): array
    {
        for ($px = $max_px; $px >= $min_one; $px -= 2) {
            if (self::width($font, $px, $text) <= $width) {
                return [$px, [$text]];
            }
        }
        // Two lines, broken at the comma closest to the middle if there is one.
        $parts = preg_split('/(?<=,)\s+/', $text);
        if (count($parts) > 1) {
            $best = null;
            for ($i = 1; $i < count($parts); $i++) {
                $a    = implode(' ', array_slice($parts, 0, $i));
                $b    = implode(' ', array_slice($parts, $i));
                $diff = abs(mb_strlen($a) - mb_strlen($b));
                if ($best === null || $diff < $best[0]) {
                    $best = [$diff, [$a, $b]];
                }
            }
            for ($px = $max_px; $px >= $min_two; $px -= 2) {
                if (self::width($font, $px, $best[1][0]) <= $width && self::width($font, $px, $best[1][1]) <= $width) {
                    return [$px, $best[1]];
                }
            }
        }
        return self::fit($font, $text, $width, $max_px, $min_two, 2);
    }

    /**
     * Draws lines of text, $y is the top of the first line. Returns the y below the last line.
     *
     * @param resource|GdImage $im
     */
    private static function lines($im, string $font, float $px, float $leading, int $x, int $y, array $rgb, array $lines, string $align = 'left', int $width = 0): int
    {
        $color = self::color($im, $rgb);
        $step  = (int) round($px * $leading);
        $base  = $y + (int) round($px * 0.8);
        foreach ($lines as $line) {
            $lx = $x;
            if ($align !== 'left') {
                $w  = self::width($font, $px, $line);
                $lx = $align === 'center' ? $x + (int) (($width - $w) / 2) : $x + $width - $w;
            }
            imagettftext($im, self::pt($px), 0, $lx, $base, $color, $font, $line);
            $base += $step;
        }
        return $y + $step * count($lines);
    }

    /* ------------------------------------------------------------ texts */

    private static function word(string $variant): string
    {
        return $variant === 'today' ? __('Today', 'event-toolkit-for-custom-fields') : __('Tomorrow', 'event-toolkit-for-custom-fields');
    }

    /**
     * "Today" / "Tomorrow" as large as fits. Returns the y below it.
     *
     * @param resource|GdImage $im
     */
    private static function big_word($im, string $variant, array $p): int
    {
        $font = self::fonts()['display'];
        [$px, $lines] = self::fit($font, self::word($variant), self::W - 2 * self::MARGIN, 200, 120, 1);
        return self::lines($im, $font, $px, 0.98, self::MARGIN - 6, self::TOP + (int) ((200 - $px) * 0.8), $p['fg'], $lines);
    }

    /** "Wednesday, October 29" */
    public static function long_date(IMM_TK_Event $e): string
    {
        /* translators: long date on story images, see https://www.php.net/manual/datetime.format.php */
        return wp_date(__('l, F j', 'event-toolkit-for-custom-fields'), $e->start->getTimestamp(), $e->start->getTimezone());
    }

    /** "Wed, 10/29" */
    public static function short_date(IMM_TK_Event $e): string
    {
        /* translators: short date on story images, see https://www.php.net/manual/datetime.format.php */
        return wp_date(__('D, n/j', 'event-toolkit-for-custom-fields'), $e->start->getTimestamp(), $e->start->getTimezone());
    }

    /** Start of the programme only, e.g. "7:30 pm". */
    public static function start_time(IMM_TK_Event $e): string
    {
        if ($e->all_day) {
            return __('all day', 'event-toolkit-for-custom-fields');
        }
        $start = wp_date(get_option('time_format') ?: 'H:i', $e->main_start->getTimestamp(), $e->main_start->getTimezone());
        /* translators: %s: start time */
        return sprintf(__('at %s', 'event-toolkit-for-custom-fields'), $start);
    }

    /** Venue name, including the default venue (a single event should say where it is). */
    public static function venue_name(IMM_TK_Event $e): string
    {
        return (string) ($e->location_parts[0] ?? IMM_TK_Settings::get('venue_name'));
    }

    /* ------------------------------------------------------------ images */

    /** @return resource|GdImage */
    private static function canvas(array $p)
    {
        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        imagefilledrectangle($im, 0, 0, self::W, self::H, self::color($im, $p['bg']));
        return $im;
    }

    /**
     * Featured image of a post, cropped to a square of $size pixels. Null without image.
     *
     * @return resource|GdImage|null
     */
    private static function photo(int $post_id, int $size)
    {
        $thumb = get_post_thumbnail_id($post_id);
        $file  = $thumb ? get_attached_file($thumb) : '';
        if (!$file || !is_readable($file)) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($file)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (!$src) {
            return null;
        }
        $w    = imagesx($src);
        $h    = imagesy($src);
        $side = min($w, $h);
        $sx   = (int) (($w - $side) / 2);
        $sy   = (int) (($h - $side) * 0.3); // Portraits: keep the upper part, where faces usually are.
        $out  = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        imagedestroy($src);
        return $out;
    }

    /**
     * A polaroid: white frame, square photo, a strip below for the caption. Rendered at double size,
     * rotated, then scaled down for smooth edges. Returns an image with alpha.
     *
     * @param callable $caption function ($im, int $scale, int $x, int $y, int $width, array $palette) draws into the strip.
     * @return resource|GdImage
     */
    private static function polaroid(IMM_TK_Event $event, int $photo, int $frame, int $strip, float $angle, array $p, callable $caption)
    {
        $s  = 2;
        $w  = ($photo + 2 * $frame) * $s;
        $h  = ($frame + $photo + $strip) * $s;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        imagefilledrectangle($im, 0, 0, $w, $h, self::color($im, $p['white']));

        $pic = self::photo($event->id, $photo * $s);
        if ($pic) {
            imagecopy($im, $pic, $frame * $s, $frame * $s, 0, 0, $photo * $s, $photo * $s);
            imagedestroy($pic);
        } else {
            // No picture: a blank print with the date.
            imagefilledrectangle($im, $frame * $s, $frame * $s, ($frame + $photo) * $s - 1, ($frame + $photo) * $s - 1, self::color($im, $p['blank']));
            $font = self::fonts()['display'];
            $text = wp_date('j.n.', $event->start->getTimestamp(), $event->start->getTimezone());
            [$px, $lines] = self::fit($font, $text, (int) ($photo * $s * 0.8), $photo * $s * 0.34, 40, 1);
            self::lines($im, $font, $px, 1, $frame * $s, (int) (($frame + $photo / 2) * $s - $px * 0.45), self::mix($p['blank'], $p['ink'], 0.35), $lines, 'center', $photo * $s);
        }
        $caption($im, $s, $frame * $s, ($frame + $photo) * $s, $photo * $s, $p);

        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $rot         = imagerotate($im, $angle, $transparent);
        imagedestroy($im);
        $rw  = (int) (imagesx($rot) / $s);
        $rh  = (int) (imagesy($rot) / $s);
        $out = imagecreatetruecolor($rw, $rh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefilledrectangle($out, 0, 0, $rw, $rh, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $rot, 0, 0, 0, 0, $rw, $rh, imagesx($rot), imagesy($rot));
        imagedestroy($rot);
        return $out;
    }

    /**
     * Places a polaroid with a soft shadow on the canvas. ($x, $y) is the top left of the rotated image.
     *
     * @param resource|GdImage $canvas
     * @param resource|GdImage $pol
     */
    private static function place($canvas, $pol, int $x, int $y, array $p, int $blur = 22, int $drop = 18): void
    {
        $w   = imagesx($pol);
        $h   = imagesy($pol);
        $pad = $blur * 3;

        // Shadow: the silhouette in the shadow colour on a patch of background, blurred at low resolution.
        $patch = imagecreatetruecolor($w + 2 * $pad, $h + 2 * $pad);
        imagefilledrectangle($patch, 0, 0, imagesx($patch), imagesy($patch), self::color($patch, $p['bg']));
        $sil = imagecreatetruecolor($w, $h);
        imagealphablending($sil, false);
        imagesavealpha($sil, true);
        imagecopy($sil, $pol, 0, 0, 0, 0, $w, $h);
        imagefilter($sil, IMG_FILTER_BRIGHTNESS, -255);
        imagefilter($sil, IMG_FILTER_COLORIZE, $p['shadow'][0], $p['shadow'][1], $p['shadow'][2]);
        imagealphablending($patch, true);
        imagecopy($patch, $sil, $pad, $pad, 0, 0, $w, $h);
        imagedestroy($sil);

        $f     = max(4, (int) ($blur / 3));
        $small = imagecreatetruecolor((int) ceil(imagesx($patch) / $f), (int) ceil(imagesy($patch) / $f));
        imagecopyresampled($small, $patch, 0, 0, 0, 0, imagesx($small), imagesy($small), imagesx($patch), imagesy($patch));
        for ($i = 0; $i < 4; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopyresampled($patch, $small, 0, 0, 0, 0, imagesx($patch), imagesy($patch), imagesx($small), imagesy($small));
        imagedestroy($small);

        imagecopy($canvas, $patch, $x - $pad, $y - $pad + $drop, 0, 0, imagesx($patch), imagesy($patch));
        imagedestroy($patch);

        imagealphablending($canvas, true);
        imagecopy($canvas, $pol, $x, $y, 0, 0, $w, $h);
    }

    /**
     * Footer line (website) in the accent colour, like a link.
     *
     * @param resource|GdImage $im
     */
    private static function footer($im, array $p, string $right = ''): void
    {
        $f = self::fonts();
        self::lines($im, $f['bold'], 36, 1, self::MARGIN, self::BOTTOM - 29, $p['accent'], [self::style()['footer']]);
        if ($right !== '') {
            self::lines($im, $f['text'], 32, 1, self::MARGIN, self::BOTTOM - 26, $p['muted'], [$right], 'right', self::W - 2 * self::MARGIN);
        }
    }

    /**
     * One event: "Today" (dark) or "Tomorrow" (light), with the picture as polaroid.
     *
     * @return resource|GdImage
     */
    public static function render_event(IMM_TK_Event $e, string $variant)
    {
        $today = $variant === 'today';
        $p     = self::palette($today);
        $f     = self::fonts();
        $im    = self::canvas($p);
        $width = self::W - 2 * self::MARGIN;

        // "Today" / "Tomorrow", big.
        $y = self::big_word($im, $variant, $p);

        // When: today only the time, tomorrow day and time.
        $when = $today ? IMM_TK_Digest::time($e) : self::long_date($e) . ', ' . IMM_TK_Digest::time($e);
        [$px, $lines] = self::fit_short($f['text'], $when, $width, 42, 34, 30);
        $y = self::lines($im, $f['text'], $px, 1.3, self::MARGIN, $y + 18, $p['fg'], $lines);

        // Caption on the polaroid strip: the title.
        $frame = 30;
        $title = IMM_TK_Digest::title($e);
        $venue = self::venue_name($e);

        // Space left for the polaroid: down to the venue line above the footer.
        $venue_y = self::BOTTOM - 29 - 72;
        $bottom  = ($venue !== '' ? $venue_y : self::BOTTOM - 29) - 44;
        $top     = $y + 56;
        $max     = 720;
        [$tpx, $tlines] = self::fit($f['display'], $title, $max - 2 * 28, 60, 40, 3);
        $strip  = 34 + (int) round($tpx * 1.08) * count($tlines) + 40;
        // Rotation by ~2.4° makes the image about 4 % of its width taller.
        $avail  = (int) floor(($bottom - $top - $frame - $strip - 6) / 1.042);
        $photo  = max(380, min($max, $avail));
        if ($photo < $max) {
            [$tpx, $tlines] = self::fit($f['display'], $title, $photo - 56, $tpx, 34, 3);
        }

        $caption = function ($pim, int $s, int $x, int $sy, int $w, array $pp) use ($f, $tpx, $tlines) {
            self::lines($pim, $f['display'], $tpx * $s, 1.08, $x + 28 * $s, $sy + 30 * $s, $pp['ink'], $tlines);
        };
        $angle = $today ? 2.2 : -2.4;
        $pol   = self::polaroid($e, $photo, $frame, $strip, $angle, $p, $caption);
        $px_x  = (int) ((self::W - imagesx($pol)) / 2) + ($today ? 18 : -18);
        self::place($im, $pol, $px_x, $top, $p);
        $y = $top + imagesy($pol);
        imagedestroy($pol);

        if ($venue !== '') {
            [$vpx, $vlines] = self::fit($f['text'], $venue, $width, 38, 30, 1);
            self::lines($im, $f['text'], $vpx, 1.2, self::MARGIN, max($y + 28, $venue_y), $p['muted'], $vlines);
        }
        self::footer($im, $p);
        return $im;
    }

    /**
     * A list of events: the week, the new events, or many events on one day.
     *
     * @param IMM_TK_Event[] $events
     * @return resource|GdImage
     */
    public static function render_list(array $events, array $spec)
    {
        $variant = $spec['variant'];
        $p       = self::palette($variant === 'today');
        $f       = self::fonts();
        $im      = self::canvas($p);
        $width   = self::W - 2 * self::MARGIN;
        $day     = in_array($variant, ['today', 'tomorrow'], true);

        if ($day) {
            $y = self::big_word($im, $variant, $p);
            [$dpx, $dlines] = self::fit_short($f['text'], self::long_date($events[0]), $width, 42, 34, 30);
            $y = self::lines($im, $f['text'], $dpx, 1.3, self::MARGIN, $y + 18, $p['fg'], $dlines);
        } else {
            $heading = $variant === 'week' ? IMM_TK_Digest::heading_week() : IMM_TK_Digest::heading_new();
            [$hpx, $hlines] = self::fit($f['display'], $heading, $width, 104, 64, 3);
            $y = self::lines($im, $f['display'], $hpx, 1.02, self::MARGIN - 4, self::TOP, $p['fg'], $hlines);
        }

        // Rows: small polaroid, date and time, title.
        // Few events get bigger rows.
        $y     += 72;
        $space  = self::BOTTOM - 100 - $y;
        $row    = min(400, (int) floor($space / max(1, count($events))));
        $photo  = max(120, min(300, $row - 80));
        $grow   = min(1, ($photo - 170) / 80); // 0 at the normal size, 1 from 250 px on.
        $text_x = self::MARGIN + $photo + 2 * 12 + 52;
        $text_w = self::W - self::MARGIN - $text_x;
        $none   = function () {
        };

        foreach (array_values($events) as $i => $e) {
            $angle = $i % 2 ? 2.6 : -2.6;
            $pol   = self::polaroid($e, $photo, 12, 40, $angle, $p, $none);
            self::place($im, $pol, self::MARGIN - 8, $y, $p, 12, 8);
            $ph = imagesy($pol);
            imagedestroy($pol);

            $when  = $day ? IMM_TK_Digest::time($e) : self::short_date($e) . ', ' . self::start_time($e);
            [$wpx, $wlines] = self::fit($f['text'], $when, $text_w, 32 + 4 * $grow, 24, 2);
            [$tpx, $tlines] = self::fit($f['display'], IMM_TK_Digest::title($e), $text_w, 50 + 10 * $grow, 36, 3);
            $block = (int) round($wpx * 1.4) * count($wlines) + (int) round($tpx * 1.08) * count($tlines);
            $ty    = $y + (int) max(0, ($ph - $block) / 2) - 6;
            $ty    = self::lines($im, $f['text'], $wpx, 1.4, $text_x, $ty, $p['muted'], $wlines);
            self::lines($im, $f['display'], $tpx, 1.08, $text_x, $ty, $p['fg'], $tlines);
            $y += $row;
        }

        $pages = (int) $spec['pages'];
        self::footer($im, $p, $pages > 1 ? $spec['page'] . '/' . $pages : '');
        return $im;
    }
}
