<?php
/**
 * Fonts for the story images: by default the main fonts of the website, so the images match
 * the site's look.
 *
 * Detection: Breakdance global settings, then the global styles of the theme (theme.json).
 * GD needs TrueType/OpenType files, which websites rarely ship (they use WOFF2). So the font
 * file comes from the theme if it ships a .ttf/.otf, otherwise it is downloaded once from
 * Google Fonts and kept in uploads/event-toolkit/fonts. If neither works, the bundled fonts
 * (Syne, Atkinson Hyperlegible Mono; SIL OFL 1.1) are used.
 */

defined('ABSPATH') || exit;

class IMM_TK_Fonts
{
    const DIR = 'event-toolkit/fonts';

    /** Bundled fallback fonts. */
    public static function bundled(): array
    {
        $dir = IMM_TK_DIR . 'assets/fonts/';
        return [
            'display' => $dir . 'Syne-Bold.ttf',
            'text'    => $dir . 'AtkinsonHyperlegibleMono-Regular.ttf',
            'bold'    => $dir . 'AtkinsonHyperlegibleMono-Bold.ttf',
        ];
    }

    /**
     * Font files for the story images.
     *
     * @return array{display: string, text: string, bold: string}
     */
    public static function files(): array
    {
        $files = self::bundled();
        if (IMM_TK_Settings::get('sharepic_fonts') === 'site') {
            $families = self::families();
            $weight   = (int) IMM_TK_Settings::get('sharepic_heading_weight') ?: self::heading_weight();
            $wanted   = [
                'display' => [$families['heading'], $weight],
                'text'    => [$families['text'], 400],
                'bold'    => [$families['text'], 700],
            ];
            foreach ($wanted as $role => [$family, $w]) {
                $file = $family !== '' ? self::file($family, $w) : '';
                if ($file !== '') {
                    $files[$role] = $file;
                }
            }
        }
        return (array) apply_filters('imm_tk_sharepic_fonts', $files);
    }

    /**
     * Font families for headings and text: the settings, or else the fonts of the website.
     *
     * @return array{heading: string, text: string}
     */
    public static function families(): array
    {
        $detected = self::detect();
        $heading  = trim((string) IMM_TK_Settings::get('sharepic_heading_font'));
        $text     = trim((string) IMM_TK_Settings::get('sharepic_text_font'));
        return [
            'heading' => $heading !== '' ? $heading : $detected['heading'],
            'text'    => $text !== '' ? $text : $detected['text'],
        ];
    }

    /**
     * Weight of the headings on the website (Breakdance or theme.json), 700 if unknown.
     */
    public static function heading_weight(): int
    {
        $weight = 0;
        $css    = trailingslashit(wp_upload_dir()['basedir']) . 'breakdance/css/global-settings.css';
        if (is_readable($css)) {
            $content = (string) file_get_contents($css); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            // The rule for h1 … h6 (Breakdance writes them in one selector list).
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $content, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                $selectors = array_map('trim', explode(',', $rule[1]));
                if (in_array('h1', $selectors, true) && preg_match('/font-weight\s*:\s*(\d{3})/', $rule[2], $m)) {
                    $weight = (int) $m[1];
                }
            }
        }
        if (!$weight && function_exists('wp_get_global_styles')) {
            $value  = (string) wp_get_global_styles(['elements', 'heading', 'typography', 'fontWeight']);
            $weight = ctype_digit($value) ? (int) $value : 0;
        }
        $weight = (int) apply_filters('imm_tk_site_heading_weight', $weight);
        return $weight >= 100 && $weight <= 900 ? (int) (round($weight / 100) * 100) : 700;
    }

    /**
     * Main fonts of the website.
     *
     * @return array{heading: string, text: string}
     */
    public static function detect(): array
    {
        $found = ['heading' => '', 'text' => ''];

        // Breakdance writes its global typography as CSS variables.
        $css = trailingslashit(wp_upload_dir()['basedir']) . 'breakdance/css/global-settings.css';
        if (is_readable($css)) {
            $content = (string) file_get_contents($css); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            if (preg_match('/--bde-heading-font-family:\s*([^;}]+)/', $content, $m)) {
                $found['heading'] = self::first_family($m[1]);
            }
            if (preg_match('/--bde-body-font-family:\s*([^;}]+)/', $content, $m)) {
                $found['text'] = self::first_family($m[1]);
            }
        }

        // Block themes and themes with theme.json.
        if (function_exists('wp_get_global_styles')) {
            if ($found['heading'] === '') {
                $found['heading'] = self::resolve_preset(wp_get_global_styles(['elements', 'heading', 'typography', 'fontFamily']));
            }
            if ($found['text'] === '') {
                $found['text'] = self::resolve_preset(wp_get_global_styles(['typography', 'fontFamily']));
            }
        }
        if ($found['heading'] === '') {
            $found['heading'] = $found['text'];
        }
        return (array) apply_filters('imm_tk_site_fonts', $found);
    }

    /** First family of a CSS font-family list, without quotes; empty for generic families. */
    private static function first_family(string $css): string
    {
        $first = trim(explode(',', $css)[0], " \t\"'");
        if (strpos($first, 'var(') === 0 || in_array(strtolower($first), ['sans-serif', 'serif', 'monospace', 'system-ui', 'inherit', 'cursive'], true)) {
            return '';
        }
        return $first;
    }

    /** "var(--wp--preset--font-family--slug)" or "var:preset|font-family|slug" → family name. */
    private static function resolve_preset($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (!preg_match('/font-family(?:--|\|)([a-z0-9-]+)/', $value, $m)) {
            return self::first_family($value);
        }
        foreach (self::theme_families() as $family) {
            if (($family['slug'] ?? '') === $m[1]) {
                return self::first_family((string) ($family['fontFamily'] ?? ''));
            }
        }
        return '';
    }

    private static function theme_families(): array
    {
        if (!function_exists('wp_get_global_settings')) {
            return [];
        }
        $out = [];
        foreach ((array) wp_get_global_settings(['typography', 'fontFamilies']) as $origin) {
            foreach ((array) $origin as $family) {
                if (is_array($family)) {
                    $out[] = $family;
                }
            }
        }
        return $out;
    }

    /** TrueType/OpenType file of a family in a weight, or '' if none can be found. */
    public static function file(string $family, int $weight): string
    {
        $local = self::theme_file($family, $weight);
        if ($local !== '') {
            return $local;
        }
        // Not every family has every weight; fall back to the nearest common ones.
        foreach (array_unique([$weight, $weight > 500 ? 700 : 400, 400]) as $w) {
            $file = self::google_file($family, $w);
            if ($file !== '') {
                return $file;
            }
        }
        return '';
    }

    /** A .ttf/.otf the theme ships for this family (theme.json fontFace). */
    private static function theme_file(string $family, int $weight): string
    {
        $best = '';
        $diff = PHP_INT_MAX;
        foreach (self::theme_families() as $item) {
            if (strcasecmp(self::first_family((string) ($item['fontFamily'] ?? '')), $family) !== 0) {
                continue;
            }
            foreach ((array) ($item['fontFace'] ?? []) as $face) {
                if (($face['fontStyle'] ?? 'normal') !== 'normal') {
                    continue;
                }
                foreach ((array) ($face['src'] ?? []) as $src) {
                    if (!preg_match('/\.(ttf|otf)$/i', (string) $src)) {
                        continue;
                    }
                    $path = strpos($src, 'file:./') === 0 ? get_theme_file_path(substr($src, 7)) : '';
                    $w    = (int) explode(' ', (string) ($face['fontWeight'] ?? '400'))[0];
                    if ($path !== '' && is_readable($path) && abs($w - $weight) < $diff) {
                        $best = $path;
                        $diff = abs($w - $weight);
                    }
                }
            }
        }
        return $best;
    }

    /**
     * Downloads the TrueType file of a Google font once and keeps it in the uploads folder.
     * Failed lookups are not repeated for a day.
     */
    private static function google_file(string $family, int $weight): string
    {
        $dir  = trailingslashit(wp_upload_dir()['basedir']) . self::DIR;
        $path = $dir . '/' . sanitize_file_name(sanitize_title($family) . '-' . $weight . '.ttf');
        if (is_readable($path) && filesize($path) > 1000) {
            return $path;
        }
        $fail = 'imm_tk_font_' . md5($family . $weight);
        if (get_transient($fail) || !wp_mkdir_p($dir)) {
            return '';
        }
        // Without a browser user agent, Google Fonts answers with TrueType files.
        $css = wp_remote_get('https://fonts.googleapis.com/css2?family=' . rawurlencode($family) . ':wght@' . $weight, ['timeout' => 15]);
        $url = '';
        if (!is_wp_error($css) && wp_remote_retrieve_response_code($css) === 200 && preg_match('#url\((https://fonts\.gstatic\.com/[^)]+\.ttf)\)#', wp_remote_retrieve_body($css), $m)) {
            $url = $m[1];
        }
        if ($url !== '') {
            $file = wp_remote_get($url, ['timeout' => 30, 'stream' => true, 'filename' => $path]);
            if (!is_wp_error($file) && wp_remote_retrieve_response_code($file) === 200 && is_readable($path) && filesize($path) > 1000) {
                return $path;
            }
            wp_delete_file($path);
        }
        set_transient($fail, 1, DAY_IN_SECONDS);
        return '';
    }
}
