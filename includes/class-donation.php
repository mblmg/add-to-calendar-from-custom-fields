<?php
/**
 * Donations: a polite question some time after the plugin is in use, and a badge for those who donated.
 *
 * - The question appears only on the plugin's own admin pages, only for administrators, and only
 *   once the events are set up for two weeks. "Later" asks again after 30 days, "No, thanks" after
 *   90 days. Every feature stays available either way.
 * - "Donate via PayPal" opens PayPal in a new tab. On the next page the plugin asks whether the
 *   donation went through; "Yes, I donated" counts it. PayPal does not report back to the site, so
 *   the count is the administrator's own statement and only decides the badge.
 * - Badges: bronze heart after one donation, silver after two, gold from three. After a donation the
 *   plugin does not ask again; the badge panel on the overview offers to donate once more.
 * - The tab "Donate", last in the menu, is there from day one for those who want to donate right away.
 *
 * State per user (user meta etcf_donation), the start of use per site (option etcf_first_use).
 */

defined('ABSPATH') || exit;

class ETCF_Donation
{
    const URL       = 'https://www.paypal.com/donate/?hosted_button_id=77FT7TBQCZPPS';
    const META      = 'etcf_donation';
    const FIRST_USE = 'etcf_first_use';

    /** Days of use before the first question, and pauses after "Later" and "No, thanks". */
    const FIRST_ASK = 14;
    const LATER     = 30;
    const DECLINED  = 90;
    /** After a click on "Donate", ask for this many days whether it went through. */
    const CONFIRM = 7;

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'note_first_use']);
        add_action('admin_post_etcf_donate', [self::class, 'handle_donate']);
        add_action('admin_post_etcf_donation', [self::class, 'handle_answer']);
        add_filter('plugin_row_meta', [self::class, 'row_meta'], 10, 2);
    }

    /** The start of use is the first admin page load with events set up. */
    public static function note_first_use(): void
    {
        if (!get_option(self::FIRST_USE) && ETCF_Settings::is_configured()) {
            update_option(self::FIRST_USE, time(), false);
        }
    }

    /** @return array{count: int, last: int, next: int, clicked: int} */
    public static function state(int $user_id = 0): array
    {
        $saved = get_user_meta($user_id ?: get_current_user_id(), self::META, true);
        $saved = is_array($saved) ? $saved : [];
        return [
            'count'   => (int) ($saved['count'] ?? 0),    // Donations the user confirmed.
            'last'    => (int) ($saved['last'] ?? 0),     // Time of the last confirmed donation.
            'next'    => (int) ($saved['next'] ?? 0),     // Do not ask before this time.
            'clicked' => (int) ($saved['clicked'] ?? 0),  // Time of the last click on "Donate", until answered.
        ];
    }

    private static function update(array $changes): void
    {
        update_user_meta(get_current_user_id(), self::META, array_merge(self::state(), $changes));
    }

    /** Which box to show now: 'ask', 'confirm' or '' for none. */
    public static function prompt(): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $state = self::state();
        $now   = time();
        if ($state['clicked'] && $now - $state['clicked'] < self::CONFIRM * DAY_IN_SECONDS) {
            return 'confirm';
        }
        if ($state['count'] > 0) {
            return '';
        }
        $first = (int) get_option(self::FIRST_USE);
        if (!$first || $now - $first < self::FIRST_ASK * DAY_IN_SECONDS || $now < $state['next']) {
            return '';
        }
        return 'ask';
    }

    /** Address of an answer button. */
    private static function answer_url(string $answer): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'etcf_donation', 'answer' => $answer], admin_url('admin-post.php')), 'etcf_donation');
    }

    /** Address of the donate button: counts the click, then forwards to PayPal. */
    public static function donate_url(): string
    {
        return wp_nonce_url(add_query_arg('action', 'etcf_donate', admin_url('admin-post.php')), 'etcf_donate');
    }

    /** The question or the follow-up, at the top of the plugin's pages. */
    public static function render_prompt(): void
    {
        $prompt = self::prompt();
        if ($prompt === '') {
            return;
        }
        echo '<section class="etcf-panel etcf-donate">';
        echo '<span class="dashicons dashicons-heart etcf-donate-icon" aria-hidden="true"></span><div>';
        if ($prompt === 'ask') {
            echo '<h2>' . esc_html__('Has Event Toolkit made your work easier?', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p>' . esc_html__('The plugin is free and stays free. If it saves you time, a small donation helps me maintain it and keep developing it. Every feature stays available, with or without a donation.', 'event-toolkit-for-custom-fields') . '</p>';
            echo '<p class="etcf-actions">';
            echo '<a class="button button-primary" href="' . esc_url(self::donate_url()) . '" target="_blank" rel="noopener">' . esc_html__('Donate via PayPal', 'event-toolkit-for-custom-fields') . '</a> ';
            echo '<a class="button" href="' . esc_url(self::answer_url('later')) . '">' . esc_html__('Remind me later', 'event-toolkit-for-custom-fields') . '</a> ';
            echo '<a class="button-link" href="' . esc_url(self::answer_url('no')) . '">' . esc_html__('No, thanks', 'event-toolkit-for-custom-fields') . '</a>';
            echo '</p>';
        } else {
            echo '<h2>' . esc_html__('Thank you! Did the donation go through?', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p>' . esc_html__('Let the plugin know, and you get a small badge here in the admin area. Nobody checks it; it is just for you.', 'event-toolkit-for-custom-fields') . '</p>';
            echo '<p class="etcf-actions">';
            echo '<a class="button button-primary" href="' . esc_url(self::answer_url('done')) . '">' . esc_html__('Yes, I donated', 'event-toolkit-for-custom-fields') . '</a> ';
            echo '<a class="button" href="' . esc_url(self::answer_url('not_yet')) . '">' . esc_html__('Not yet', 'event-toolkit-for-custom-fields') . '</a>';
            echo '</p>';
        }
        echo '</div></section>';
    }

    /** The tab "Donate": open from day one, for everyone who does not want to wait for the question. */
    public static function render_page(): void
    {
        $state = self::state();
        echo '<div class="etcf-layout"><div class="etcf-main">';
        if ($state['clicked']) {
            self::render_prompt();
        }
        echo '<section class="etcf-panel etcf-donate">';
        echo '<span class="dashicons dashicons-heart etcf-donate-icon" aria-hidden="true"></span><div>';
        echo '<h2>' . esc_html__('Support Event Toolkit', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p>' . esc_html__('I develop Event Toolkit and offer it free of charge. A donation helps me maintain it, adapt it when Meta, Telegram and the other services change something, and add new features.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p>' . esc_html__('Every amount helps. Every feature stays available, with or without a donation.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p class="etcf-actions"><a class="button button-primary button-hero" href="' . esc_url(self::donate_url()) . '" target="_blank" rel="noopener">'
            . esc_html($state['count'] ? __('Donate again', 'event-toolkit-for-custom-fields') : __('Donate via PayPal', 'event-toolkit-for-custom-fields')) . '</a></p>';
        echo '<p class="etcf-panel-note">' . esc_html__('As a thank-you there is a badge here in the admin area: a bronze heart after the first donation, a silver one after the second, a gold one from the third.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '</div></section>';
        echo '</div><aside class="etcf-aside">';
        if ($state['count']) {
            echo '<section class="etcf-panel etcf-thanks">';
            self::render_badge_panel(false);
            echo '</section>';
        }
        echo '</aside></div>';
    }

    /** @return array{label: string, level: string}|null The badge for a number of donations. */
    public static function badge(int $count): ?array
    {
        if ($count >= 3) {
            return ['label' => __('Gold heart', 'event-toolkit-for-custom-fields'), 'level' => 'gold'];
        }
        if ($count === 2) {
            return ['label' => __('Silver heart', 'event-toolkit-for-custom-fields'), 'level' => 'silver'];
        }
        if ($count === 1) {
            return ['label' => __('Bronze heart', 'event-toolkit-for-custom-fields'), 'level' => 'bronze'];
        }
        return null;
    }

    /** Small badge next to the page title. */
    public static function render_title_badge(): void
    {
        $badge = current_user_can('manage_options') ? self::badge(self::state()['count']) : null;
        if ($badge) {
            printf(
                '<span class="etcf-heart etcf-heart--%s" title="%s"><span class="dashicons dashicons-heart" aria-hidden="true"></span>%s</span>',
                esc_attr($badge['level']),
                esc_attr__('Thank you for your donation', 'event-toolkit-for-custom-fields'),
                esc_html($badge['label'])
            );
        }
    }

    /** Panel on the overview for those who donated: badge, next level, donate again. */
    public static function render_badge_panel(bool $button = true): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $count = self::state()['count'];
        $badge = self::badge($count);
        if (!$badge) {
            return;
        }
        echo '<h2>' . esc_html__('Thank you for your support', 'event-toolkit-for-custom-fields') . '</h2>';
        printf(
            '<p class="etcf-heart-big etcf-heart--%s"><span class="dashicons dashicons-heart" aria-hidden="true"></span>%s</p>',
            esc_attr($badge['level']),
            esc_html($badge['label'])
        );
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of donations */
            _n('You donated %d time.', 'You donated %d times.', $count, 'event-toolkit-for-custom-fields'),
            $count
        )) . ' ';
        $next = $count < 3 ? self::badge($count + 1) : null;
        echo esc_html($next
            /* translators: %s: name of the next badge */
            ? sprintf(__('With another donation you get the next badge: %s.', 'event-toolkit-for-custom-fields'), $next['label'])
            : __('You have the highest badge. Thank you so much!', 'event-toolkit-for-custom-fields'));
        echo '</p>';
        if ($button) {
            echo '<p><a class="button" href="' . esc_url(ETCF_Admin::url('donate')) . '">' . esc_html__('Donate again', 'event-toolkit-for-custom-fields') . '</a></p>';
        }
    }

    public static function handle_donate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_donate');
        self::update(['clicked' => time()]);
        add_filter('allowed_redirect_hosts', function ($hosts) {
            $hosts[] = 'www.paypal.com';
            return $hosts;
        });
        wp_safe_redirect(self::URL);
        exit;
    }

    public static function handle_answer(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('etcf_donation');
        $state  = self::state();
        $now    = time();
        $answer = isset($_GET['answer']) ? sanitize_key(wp_unslash($_GET['answer'])) : '';
        switch ($answer) {
            case 'done':
                self::update(['count' => $state['count'] + 1, 'last' => $now, 'clicked' => 0]);
                break;
            case 'not_yet':
                // Asked again later, unless the user already has a badge.
                self::update(['clicked' => 0, 'next' => $now + self::LATER * DAY_IN_SECONDS]);
                break;
            case 'no':
                self::update(['clicked' => 0, 'next' => $now + self::DECLINED * DAY_IN_SECONDS]);
                break;
            default:
                self::update(['clicked' => 0, 'next' => $now + self::LATER * DAY_IN_SECONDS]);
        }
        wp_safe_redirect(wp_get_referer() ?: ETCF_Admin::url());
        exit;
    }

    /** "Donate" in the plugin's row on the Plugins page. */
    public static function row_meta(array $links, string $file): array
    {
        if ($file === plugin_basename(ETCF_FILE)) {
            $links[] = '<a href="' . esc_url(self::URL) . '" target="_blank" rel="noopener">' . esc_html__('Donate', 'event-toolkit-for-custom-fields') . '</a>';
        }
        return $links;
    }
}
