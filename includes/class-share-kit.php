<?php
/**
 * The share kit: ready-made texts and story images for what is due today, the weekly preview
 * and the coming days. Shown on the share page in the admin and, through a shortcode, on a page
 * of the website for members without a login (see IMM_TK_Front).
 */

defined('ABSPATH') || exit;

class IMM_TK_Share_Kit
{
    /** Options of the current rendering, see render(). */
    private static $o = [];

    /**
     * @param array $options context: 'admin' or 'front'; button / button_secondary: CSS classes.
     */
    public static function render(array $options = []): void
    {
        self::$o = $options + [
            'context'          => 'admin',
            'button'           => 'button button-primary',
            'button_secondary' => 'button',
        ];
        $admin = self::$o['context'] === 'admin';

        if (!IMM_TK_Sharepic::available()) {
            self::note(__('Story images need the PHP extension GD with FreeType and JPEG support. Ask your host to enable it. The texts work without it.', 'event-toolkit-for-custom-fields'), 'warning');
        }
        // Which hint appears depends on what the browser can share (see share.js).
        echo '<p class="imm-tk-share-hint" data-imm-share-hint="files" hidden>' . esc_html__('"Share images and text" hands both to WhatsApp, Signal, Instagram or another app on this phone.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p class="imm-tk-share-hint" data-imm-share-hint="text" hidden>' . esc_html__('This browser can only hand text to other apps, not images. Save the images with "Download" and add them in the app, or open this page in Chrome or Safari to share both at once.', 'event-toolkit-for-custom-fields') . '</p>';

        $tz       = wp_timezone();
        $now      = new DateTimeImmutable('now', $tz);
        $today    = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $days     = IMM_TK_Share::upcoming_days(14);
        $h2       = $admin ? 'h2' : 'h3';
        $h3       = $admin ? 'h3' : 'h4';

        // 1. What fits today: tonight's events, and tomorrow's events as "tomorrow".
        echo '<' . $h2 . ' class="imm-tk-section-title">' . esc_html__('Share today', 'event-toolkit-for-custom-fields') . '</' . $h2 . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag name.
        $due = [
            'today'    => [$days[$today->format('Y-m-d')] ?? [], __('Today', 'event-toolkit-for-custom-fields')],
            'tomorrow' => [$days[$tomorrow->format('Y-m-d')] ?? [], __('Tomorrow', 'event-toolkit-for-custom-fields')],
        ];
        $any = false;
        foreach ($due as $variant => [$events, $label]) {
            $events = array_values(array_filter($events, function (IMM_TK_Event $e) use ($now) {
                return $e->end > $now;
            }));
            if (!$events) {
                continue;
            }
            $any = true;
            echo '<section class="imm-tk-day imm-tk-due">';
            self::date_badge($events[0]->start, $variant === 'today');
            echo '<div><' . $h3 . ' class="imm-tk-due-label">' . esc_html($label) . '</' . $h3 . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag name.
            self::share_card(IMM_TK_Sharepic::day_frames($events, $variant), self::event_texts($events, $variant));
            self::render_qr_codes($events);
            echo '</div></section>';
        }
        if (!$any) {
            echo '<p class="imm-tk-panel-note">' . esc_html__('No events today or tomorrow. The weekly preview below can be shared any time.', 'event-toolkit-for-custom-fields') . '</p>';
        }

        // 2. The weekly preview.
        $now_selection = IMM_TK_Digest::select($now, $now);
        echo '<' . $h2 . ' class="imm-tk-section-title">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</' . $h2 . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag name.
        $note = $admin ? IMM_TK_Admin::preview_note() : __('The coming days and what is new in the programme. Fits any day of the week.', 'event-toolkit-for-custom-fields');
        echo '<p class="imm-tk-panel-note">' . esc_html($note) . '</p>';
        if (IMM_TK_Digest::is_empty($now_selection)) {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
        } else {
            self::share_card(IMM_TK_Sharepic::week_frames($now_selection), IMM_TK_Share::week_text($now_selection, true));
        }

        // 3. Later days, to prepare.
        $later = array_filter($days, function ($date) use ($tomorrow) {
            return $date > $tomorrow->format('Y-m-d');
        }, ARRAY_FILTER_USE_KEY);
        echo '<' . $h2 . ' class="imm-tk-section-title">' . esc_html__('Later', 'event-toolkit-for-custom-fields') . '</' . $h2 . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag name.
        echo '<p class="imm-tk-panel-note">' . esc_html__('One announcement the day before and one on the day, for the next two weeks.', 'event-toolkit-for-custom-fields') . '</p>';
        if (!$later) {
            echo '<p>' . esc_html__('No further events in the next 14 days.', 'event-toolkit-for-custom-fields') . '</p>';
        }
        foreach ($later as $date => $events) {
            echo '<section class="imm-tk-day">';
            self::date_badge($events[0]->start);
            echo '<div><div class="imm-tk-share-pair">';
            foreach (['tomorrow' => __('The day before', 'event-toolkit-for-custom-fields'), 'today' => __('On the day', 'event-toolkit-for-custom-fields')] as $variant => $label) {
                echo '<div class="imm-tk-share-col"><' . $h3 . '>' . esc_html($label) . '</' . $h3 . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag name.
                self::share_card(IMM_TK_Sharepic::day_frames($events, $variant), self::event_texts($events, $variant));
                echo '</div>';
            }
            echo '</div>';
            self::render_qr_codes($events);
            echo '</div></section>';
        }

        if ($admin) {
            echo '<p class="imm-tk-panel-note imm-tk-share-why">' . esc_html__('Why by hand? WhatsApp channels and Signal groups cannot be posted to automatically without costs or breaking their rules.', 'event-toolkit-for-custom-fields') . '</p>';
        }
    }

    private static function note(string $text, string $type): void
    {
        if (self::$o['context'] === 'admin') {
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($text) . '</p></div>';
        } else {
            echo '<p class="imm-tk-share-hint">' . esc_html($text) . '</p>';
        }
    }

    private static function image_url(array $spec, bool $download = false): string
    {
        return self::$o['context'] === 'admin' ? IMM_TK_Share::image_url($spec, $download) : IMM_TK_Share::public_image_url($spec, $download);
    }

    /** Share texts of several events. */
    private static function event_texts(array $events, string $variant): string
    {
        $text = [];
        foreach ($events as $e) {
            $text[] = IMM_TK_Share::event_text($e, $variant, true);
        }
        return implode("\n\n", $text);
    }

    /** QR codes of the event pages, for posters and flyers. */
    private static function render_qr_codes(array $events): void
    {
        echo '<div class="imm-tk-qr-row">';
        foreach ($events as $e) {
            $svg = IMM_TK_QR::file($e->id, 'svg');
            $png = IMM_TK_QR::file($e->id, 'png');
            if (is_wp_error($svg)) {
                continue;
            }
            echo '<figure class="imm-tk-qr"><img src="' . esc_url($svg['url']) . '" alt="" width="72" height="72" loading="lazy">'
                . '<figcaption><strong>' . esc_html__('QR code for posters', 'event-toolkit-for-custom-fields') . '</strong>'
                . (count($events) > 1 ? '<br>' . esc_html(IMM_TK_Digest::title($e)) : '')
                . '<br><a href="' . esc_url($svg['url']) . '" download>SVG</a>'
                . (is_wp_error($png) ? '' : ' &nbsp; <a href="' . esc_url($png['url']) . '" download>PNG</a>')
                . '</figcaption></figure>';
        }
        echo '</div>';
    }

    /** Day of the month, large, with weekday and month: a page of a tear-off calendar. */
    public static function date_badge(DateTimeImmutable $day, bool $today = false): void
    {
        $ts = $day->getTimestamp();
        $tz = $day->getTimezone();
        echo '<div class="imm-tk-date' . ($today ? ' is-today' : '') . '">';
        echo '<span class="imm-tk-date-day">' . esc_html(wp_date('j', $ts, $tz)) . '</span>';
        // Full names, and short ones for narrow screens.
        echo '<span class="imm-tk-date-rest">'
            . '<span class="imm-tk-long">' . esc_html(wp_date('l', $ts, $tz)) . '<br>' . esc_html(wp_date('F', $ts, $tz)) . '</span>'
            . '<span class="imm-tk-short" aria-hidden="true">' . esc_html(wp_date('D', $ts, $tz)) . '<br>' . esc_html(wp_date('M', $ts, $tz)) . '</span>'
            . '</span>';
        echo '</div>';
    }

    /**
     * Images, text and buttons for one thing to share. The text is plain (no WhatsApp formatting),
     * so it fits every messenger. "Share …" only appears on phones that can hand content to apps.
     */
    private static function share_card(array $frames, string $text): void
    {
        $images = IMM_TK_Sharepic::available() ? $frames : [];
        echo '<div class="imm-tk-share" data-copied="' . esc_attr__('Copied', 'event-toolkit-for-custom-fields') . '" data-error="' . esc_attr__('Sharing did not work:', 'event-toolkit-for-custom-fields') . '">';
        if ($images) {
            echo '<div class="imm-tk-share-images">';
            foreach ($images as $spec) {
                $full = self::image_url($spec);
                echo '<figure><a href="' . esc_url($full) . '" target="_blank" rel="noopener"><img src="' . esc_url($full) . '" data-full="' . esc_url($full) . '" alt="" width="135" height="240" loading="lazy"></a>'
                    . '<figcaption><a href="' . esc_url(self::image_url($spec, true)) . '">' . esc_html__('Download', 'event-toolkit-for-custom-fields') . '</a></figcaption></figure>';
            }
            echo '</div>';
        }
        echo '<div class="imm-tk-share-body">';
        echo '<textarea class="large-text" rows="8" readonly aria-label="' . esc_attr__('Text to share', 'event-toolkit-for-custom-fields') . '">' . esc_textarea($text) . '</textarea>';
        echo '<p class="imm-tk-share-buttons">';
        $label = $images ? __('Share images and text …', 'event-toolkit-for-custom-fields') : __('Share text …', 'event-toolkit-for-custom-fields');
        echo '<button type="button" class="' . esc_attr(self::$o['button']) . '" data-imm-share hidden data-label-text="' . esc_attr__('Share text …', 'event-toolkit-for-custom-fields') . '">' . esc_html($label) . '</button> ';
        echo '<button type="button" class="' . esc_attr(self::$o['button_secondary']) . '" data-imm-copy>' . esc_html__('Copy text', 'event-toolkit-for-custom-fields') . '</button>';
        echo '</p><p class="imm-tk-share-error" role="alert" hidden></p></div></div>';
    }
}
