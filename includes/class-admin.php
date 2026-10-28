<?php
/**
 * Admin pages: an own menu "Event Toolkit" with an overview and one page per area.
 */

defined('ABSPATH') || exit;

class IMM_TK_Admin
{
    /** Slug of the main page (overview); the other pages are PAGE . '-' . area. */
    const PAGE = 'event-toolkit';
    /** Slug of the former page under Settings, redirected to the new menu. */
    const OLD_PAGE = 'event-toolkit-for-custom-fields';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', function () {
            register_setting('imm_tk', IMM_TK_Settings::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [IMM_TK_Settings::class, 'sanitize'],
                'default'           => IMM_TK_Settings::defaults(),
            ]);
            register_setting('imm_ig', IMM_IG_Instagram::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [IMM_IG_Instagram::class, 'sanitize'],
                'default'           => IMM_IG_Instagram::defaults(),
            ]);
            register_setting('imm_tg', IMM_TG_Settings::OPTION, [
                'type'              => 'array',
                'sanitize_callback' => [IMM_TG_Settings::class, 'sanitize'],
                'default'           => IMM_TG_Settings::defaults(),
            ]);
        });

        // Rebuild the rewrite rules after saving (base path and file names may have changed).
        add_action('update_option_' . IMM_TK_Settings::OPTION, function () {
            IMM_TK_Endpoints::add_rewrite_rules();
            flush_rewrite_rules();
        });
        add_action('admin_post_imm_tg_find_chats', [self::class, 'handle_find_chats']);
        add_action('admin_post_imm_tg_send_test', [self::class, 'handle_send_test']);
        add_action('admin_post_imm_ig_check', [self::class, 'handle_ig_check']);
        add_action('admin_post_imm_ig_post_now', [self::class, 'handle_ig_post_now']);
        add_action('admin_enqueue_scripts', function () {
            if (self::current_tab() === '') {
                return;
            }
            wp_enqueue_script('imm-tk-share', plugins_url('assets/admin-share.js', IMM_TK_FILE), [], IMM_TK_VERSION, true);
            wp_enqueue_style('imm-tk-admin', plugins_url('assets/admin.css', IMM_TK_FILE), [], IMM_TK_VERSION);
        });
        add_filter('plugin_action_links_' . plugin_basename(IMM_TK_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url('events')) . '">' . esc_html__('Settings', 'event-toolkit-for-custom-fields') . '</a>');
            return $links;
        });
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Event Toolkit', 'event-toolkit-for-custom-fields'),
            __('Event Toolkit', 'event-toolkit-for-custom-fields'),
            'manage_options',
            self::PAGE,
            [self::class, 'render'],
            'dashicons-megaphone',
            26
        );
        foreach (self::tabs() as $tab => $label) {
            add_submenu_page(self::PAGE, $label . ' ‹ ' . __('Event Toolkit', 'event-toolkit-for-custom-fields'), $label, 'manage_options', self::slug($tab), [self::class, 'render']);
        }
        // The former page under Settings stays registered, but hidden, and only redirects.
        $old = add_options_page(__('Event Toolkit', 'event-toolkit-for-custom-fields'), __('Event Toolkit', 'event-toolkit-for-custom-fields'), 'manage_options', self::OLD_PAGE, '__return_null');
        remove_submenu_page('options-general.php', self::OLD_PAGE);
        if ($old) {
            add_action('load-' . $old, [self::class, 'redirect_old_page']);
        }
    }

    /** Bookmarks and links to the former page under Settings keep working. */
    public static function redirect_old_page(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'events';
        wp_safe_redirect(self::url(isset(self::tabs()[$tab]) ? $tab : 'events'));
        exit;
    }

    private static function slug(string $tab): string
    {
        return $tab === 'overview' ? self::PAGE : self::PAGE . '-' . $tab;
    }

    /** Address of an admin page of the plugin. */
    public static function url(string $tab = 'overview', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::slug($tab)], $args), admin_url('admin.php'));
    }

    /** The plugin page that is open, or '' on other admin pages. */
    private static function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the page.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        foreach (array_keys(self::tabs()) as $tab) {
            if ($page === self::slug($tab)) {
                return $tab;
            }
        }
        return '';
    }

    private static function tabs(): array
    {
        return [
            'overview'  => __('Overview', 'event-toolkit-for-custom-fields'),
            'events'    => __('Events', 'event-toolkit-for-custom-fields'),
            'calendar'  => __('Calendar', 'event-toolkit-for-custom-fields'),
            'search'    => __('Search engines', 'event-toolkit-for-custom-fields'),
            'digest'    => __('Weekly preview', 'event-toolkit-for-custom-fields'),
            'share'     => __('Share', 'event-toolkit-for-custom-fields'),
            'telegram'  => 'Telegram',
            'instagram' => 'Instagram',
        ];
    }

    /** One sentence per page: what it is for. */
    private static function intros(): array
    {
        return [
            'overview'  => __('What goes out in the coming days, and which channels are switched on.', 'event-toolkit-for-custom-fields'),
            'events'    => __('Where your events keep date, time and place. Everything else is built from these fields.', 'event-toolkit-for-custom-fields'),
            'calendar'  => __('Calendar files for single events and a calendar people can subscribe to.', 'event-toolkit-for-custom-fields'),
            'search'    => __('Event markup on every event page, so search engines can list date and place.', 'event-toolkit-for-custom-fields'),
            'digest'    => __('Once a week: the events of the coming days and what is new in the programme. Telegram, newsletter and Instagram use this schedule.', 'event-toolkit-for-custom-fields'),
            'share'     => __('Texts and story images for channels you post to by hand, like WhatsApp and Signal.', 'event-toolkit-for-custom-fields'),
            'telegram'  => __('A bot posts the weekly preview to your group, topic or channel.', 'event-toolkit-for-custom-fields'),
            'instagram' => __('Stories through the official Instagram API, free of charge: the week, the day before and the day itself.', 'event-toolkit-for-custom-fields'),
        ];
    }

    /** Settings of each page in sections: [title, description, keys]. */
    private static function groups(): array
    {
        return [
            'events'   => [
                [__('Where the events are', 'event-toolkit-for-custom-fields'), '', ['post_type', 'date_key', 'end_date_key']],
                [__('Times', 'event-toolkit-for-custom-fields'), '', ['start_keys', 'doors_key', 'end_time_key', 'default_duration']],
                [__('Place and details', 'event-toolkit-for-custom-fields'), '', ['location_keys', 'description_key', 'ticket_url_key', 'price_key']],
            ],
            'calendar' => [
                [__('Addresses and subscription', 'event-toolkit-for-custom-fields'), '', ['base_path', 'feed_slug', 'feed_title', 'feed_past_days']],
            ],
            'search'   => [
                [__('Markup', 'event-toolkit-for-custom-fields'), '', ['schema_enabled', 'currency', 'country']],
                [__('Default venue and organizer', 'event-toolkit-for-custom-fields'), __('Used for events without own location fields.', 'event-toolkit-for-custom-fields'), ['venue_name', 'venue_address', 'organizer_name', 'organizer_url']],
            ],
            'digest'   => [
                [__('Schedule and content', 'event-toolkit-for-custom-fields'), '', ['digest_weekday', 'digest_days_ahead', 'digest_include_new', 'digest_intro', 'digest_heading_week', 'digest_heading_new', 'digest_program_url']],
                [__('Newsletter feed', 'event-toolkit-for-custom-fields'), '', ['digest_feed_enabled', 'digest_feed_slug', 'digest_feed_only_new']],
            ],
            'share'    => [
                [__('Story images', 'event-toolkit-for-custom-fields'), '', ['sharepic_paper', 'sharepic_ink', 'sharepic_accent', 'sharepic_footer', 'sharepic_fonts', 'sharepic_heading_font', 'sharepic_heading_weight', 'sharepic_text_font']],
                [__('Reminder mail', 'event-toolkit-for-custom-fields'), '', ['share_reminder', 'share_reminder_to']],
            ],
        ];
    }

    /** Label, help text and input type of every core setting. */
    private static function fields(): array
    {
        $doors_help = __('If this field is also one of the start time fields, calendars still start at the doors time, but search engines and the weekly preview show the start of the programme and the doors time separately.', 'event-toolkit-for-custom-fields');
        return [
            'post_type'        => [__('Post type', 'event-toolkit-for-custom-fields'), __('Slug of the post type that holds the events, e.g. <code>event</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'date_key'         => [__('Date field', 'event-toolkit-for-custom-fields'), __('Field name (meta key) of the date. Required.', 'event-toolkit-for-custom-fields'), 'text'],
            'end_date_key'     => [__('End date field (optional)', 'event-toolkit-for-custom-fields'), __('Only needed for events spanning several days.', 'event-toolkit-for-custom-fields'), 'text'],
            'start_keys'       => [__('Start time fields', 'event-toolkit-for-custom-fields'), __('One or more fields, separated by commas. The first field with a value is used. If none has a value, the event is added as an all-day event.', 'event-toolkit-for-custom-fields'), 'text'],
            'end_time_key'     => [__('End time field (optional)', 'event-toolkit-for-custom-fields'), __('If the end time is earlier than the start time, the event ends on the next day.', 'event-toolkit-for-custom-fields'), 'text'],
            'doors_key'        => [__('Doors time field (optional)', 'event-toolkit-for-custom-fields'), $doors_help, 'text'],
            'default_duration' => [__('Default duration in minutes', 'event-toolkit-for-custom-fields'), __('Used when no end time is set.', 'event-toolkit-for-custom-fields'), 'number'],
            'location_keys'    => [__('Location fields (optional)', 'event-toolkit-for-custom-fields'), __('One or more fields, separated by commas. The first is read as venue name, the others as address.', 'event-toolkit-for-custom-fields'), 'text'],
            'description_key'  => [__('Description field (optional)', 'event-toolkit-for-custom-fields'), __('Field for the description. Empty = the post excerpt, or the beginning of the post content if there is no excerpt. In calendars, the link to the post is always added.', 'event-toolkit-for-custom-fields'), 'text'],
            'ticket_url_key'   => [__('Ticket link field (optional)', 'event-toolkit-for-custom-fields'), __('Field with a link to buy tickets or register.', 'event-toolkit-for-custom-fields'), 'text'],
            'price_key'        => [__('Price field (optional)', 'event-toolkit-for-custom-fields'), __('Field with the price, e.g. <code>12</code>, <code>12,50 €</code> or <code>free</code>.', 'event-toolkit-for-custom-fields'), 'text'],

            'base_path'        => [__('Base path', 'event-toolkit-for-custom-fields'), __('Results in the addresses /<em>base</em>/event-123.ics and /<em>base</em>/<em>feed</em>.ics.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_slug'        => [__('Feed file name', 'event-toolkit-for-custom-fields'), __('Without .ics.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_title'       => [__('Subscription calendar name', 'event-toolkit-for-custom-fields'), __('The name subscribers see in their calendar app.', 'event-toolkit-for-custom-fields'), 'text'],
            'feed_past_days'   => [__('Past days in the feed', 'event-toolkit-for-custom-fields'), __('How long past events stay visible in the subscription.', 'event-toolkit-for-custom-fields'), 'number'],

            'schema_enabled'   => [__('Structured data', 'event-toolkit-for-custom-fields'), __('Adds schema.org event markup (JSON-LD) to each event page, so search engines like Google can show the event with date and place in their event listings. Turn it off if another plugin already does this.', 'event-toolkit-for-custom-fields'), 'check', __('Output structured data on event pages', 'event-toolkit-for-custom-fields')],
            'currency'         => [__('Currency', 'event-toolkit-for-custom-fields'), __('Three-letter code, e.g. <code>EUR</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'country'          => [__('Country', 'event-toolkit-for-custom-fields'), __('Two-letter code of the venue country, e.g. <code>DE</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'venue_name'       => [__('Default venue name', 'event-toolkit-for-custom-fields'), __('Used when an event has no location fields filled. Not repeated in the weekly preview.', 'event-toolkit-for-custom-fields'), 'text'],
            'venue_address'    => [__('Default venue address', 'event-toolkit-for-custom-fields'), __('Format: <code>Street 1, 12345 City</code>.', 'event-toolkit-for-custom-fields'), 'text'],
            'organizer_name'   => [__('Organizer', 'event-toolkit-for-custom-fields'), __('Name of the organizer. Empty = no organizer.', 'event-toolkit-for-custom-fields'), 'text'],
            'organizer_url'    => [__('Organizer website', 'event-toolkit-for-custom-fields'), '', 'url'],

            'digest_weekday'      => [__('Day and time', 'event-toolkit-for-custom-fields'), __('When a new issue starts. Telegram posts at this time; the newsletter feed switches to the new issue.', 'event-toolkit-for-custom-fields'), 'weekday'],
            'digest_days_ahead'   => [__('Days ahead', 'event-toolkit-for-custom-fields'), __('Events starting within this many days are listed in the first part.', 'event-toolkit-for-custom-fields'), 'number'],
            'digest_include_new'  => [__('New events', 'event-toolkit-for-custom-fields'), '', 'check', __('Also list events published during the week before the issue', 'event-toolkit-for-custom-fields')],
            'digest_intro'        => [__('Intro (optional)', 'event-toolkit-for-custom-fields'), '', 'textarea'],
            'digest_heading_week' => [__('Heading for the coming days', 'event-toolkit-for-custom-fields'), '', 'text', __('This week', 'event-toolkit-for-custom-fields')],
            'digest_heading_new'  => [__('Heading for new events', 'event-toolkit-for-custom-fields'), '', 'text', __('New in the programme', 'event-toolkit-for-custom-fields')],
            'digest_program_url'  => [__('Programme page', 'event-toolkit-for-custom-fields'), __('Linked at the end of every issue.', 'event-toolkit-for-custom-fields'), 'url'],
            'digest_feed_enabled' => [__('Newsletter feed', 'event-toolkit-for-custom-fields'), '', 'check', __('Provide the weekly preview as RSS feed for newsletter services', 'event-toolkit-for-custom-fields')],
            'digest_feed_slug'    => [__('Feed file name', 'event-toolkit-for-custom-fields'), __('Without .xml.', 'event-toolkit-for-custom-fields'), 'text'],
            'digest_feed_only_new' => [__('Only with news', 'event-toolkit-for-custom-fields'), __('Weeks in which no event was added stay empty in the feed, so no newsletter goes out. Fewer mails, and each one has something new.', 'event-toolkit-for-custom-fields'), 'check', __('Send the newsletter only when an event was added during the past week', 'event-toolkit-for-custom-fields')],

            'sharepic_paper'    => [__('Background', 'event-toolkit-for-custom-fields'), __('Light colour of the story images. "Today" images swap background and text colour.', 'event-toolkit-for-custom-fields'), 'color'],
            'sharepic_ink'      => [__('Text', 'event-toolkit-for-custom-fields'), '', 'color'],
            'sharepic_accent'   => [__('Accent', 'event-toolkit-for-custom-fields'), __('Only used for the web address at the bottom.', 'event-toolkit-for-custom-fields'), 'color'],
            'sharepic_footer'   => [__('Bottom line', 'event-toolkit-for-custom-fields'), __('Usually your web address. Empty = the address of the programme page.', 'event-toolkit-for-custom-fields'), 'text'],
            'sharepic_fonts'    => [__('Fonts', 'event-toolkit-for-custom-fields'), '', 'choice'],
            'sharepic_heading_font'   => [__('Font for headings', 'event-toolkit-for-custom-fields'), '', 'font'],
            'sharepic_heading_weight' => [__('Weight of headings', 'event-toolkit-for-custom-fields'), '', 'choice'],
            'sharepic_text_font'      => [__('Font for text', 'event-toolkit-for-custom-fields'), __('Empty = the font detected on the website. If the theme does not ship the font as .ttf or .otf file, it is downloaded once from Google Fonts. Fonts that are not available there fall back to the bundled ones.', 'event-toolkit-for-custom-fields'), 'font'],
            'share_reminder'    => [__('Reminder mail', 'event-toolkit-for-custom-fields'), __('At the time of the weekly preview, a mail with the text to share and a link to this page.', 'event-toolkit-for-custom-fields'), 'check', __('Send a reminder to share the weekly preview', 'event-toolkit-for-custom-fields')],
            'share_reminder_to' => [__('Reminder to', 'event-toolkit-for-custom-fields'), '', 'email'],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tabs = self::tabs();
        $tab  = self::current_tab() ?: 'overview';
        ?>
        <div class="wrap imm-tk">
            <header class="imm-tk-head">
                <h1><?php echo esc_html($tab === 'overview' ? __('Event Toolkit', 'event-toolkit-for-custom-fields') : $tabs[$tab]); ?></h1>
                <p class="imm-tk-intro"><?php echo esc_html(self::intros()[$tab]); ?></p>
            </header>
            <nav class="imm-tk-nav" aria-label="<?php esc_attr_e('Event Toolkit', 'event-toolkit-for-custom-fields'); ?>">
                <?php foreach ($tabs as $id => $label) : ?>
                    <a href="<?php echo esc_url(self::url($id)); ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <hr class="wp-header-end">
            <?php
            settings_errors();
            if ($tab !== 'overview' && $tab !== 'events' && !IMM_TK_Settings::is_configured()) {
                echo '<div class="notice notice-info inline"><p>' . wp_kses(sprintf(
                    /* translators: %s: link to the page "Events" */
                    __('First tell the plugin where your events are: post type and date field on the page %s.', 'event-toolkit-for-custom-fields'),
                    '<a href="' . esc_url(self::url('events')) . '">' . esc_html__('Events', 'event-toolkit-for-custom-fields') . '</a>'
                ), ['a' => ['href' => []]]) . '</p></div>';
            }
            switch ($tab) {
                case 'overview':
                    self::render_overview();
                    break;
                case 'telegram':
                    self::render_telegram();
                    break;
                case 'instagram':
                    self::render_instagram();
                    break;
                case 'share':
                    self::render_share();
                    echo '<h2 class="imm-tk-section-title" id="imm-tk-share-settings">' . esc_html__('Design and reminder', 'event-toolkit-for-custom-fields') . '</h2>';
                    echo '<div class="imm-tk-narrow">';
                    self::render_core_form('share');
                    echo '</div>';
                    break;
                default:
                    echo '<div class="imm-tk-layout"><div class="imm-tk-main">';
                    self::render_core_form($tab);
                    echo '</div><aside class="imm-tk-aside">';
                    switch ($tab) {
                        case 'events':
                            self::panel([self::class, 'render_event_preview']);
                            self::panel(function () {
                                self::render_field_finder((string) IMM_TK_Settings::get('post_type'));
                            }, 'imm-tk-panel--scroll');
                            break;
                        case 'calendar':
                            self::panel([self::class, 'render_usage']);
                            break;
                        case 'search':
                            self::panel([self::class, 'render_schema_preview']);
                            break;
                        case 'digest':
                            self::render_digest_info();
                            break;
                    }
                    echo '</aside></div>';
            }
            ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ Overview */

    private static function render_overview(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            echo '<section class="imm-tk-panel imm-tk-empty">';
            echo '<h2>' . esc_html__('Start with your events', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p>' . esc_html__('Tell the plugin which post type holds your events and which field has the date. Everything else (calendar, search engines, Telegram, Instagram, newsletter) builds on that.', 'event-toolkit-for-custom-fields') . '</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(self::url('events')) . '">' . esc_html__('Set up events', 'event-toolkit-for-custom-fields') . '</a></p>';
            echo '</section>';
            return;
        }
        $tz  = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);

        echo '<div class="imm-tk-layout imm-tk-layout--overview"><div class="imm-tk-main">';
        echo '<h2 class="imm-tk-section-title">' . esc_html__('The next 7 days', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<ol class="imm-tk-week">';
        for ($i = 0; $i < 7; $i++) {
            $day    = $now->setTime(0, 0)->modify('+' . $i . ' days');
            $events = array_values(array_filter(IMM_TK_Share::events_on($day), function (IMM_TK_Event $e) use ($now) {
                return $e->end > $now;
            }));
            $out    = self::outgoing($day, $events, $now);
            echo '<li class="imm-tk-week-day' . ($events || $out ? '' : ' is-quiet') . '">';
            self::date_badge($day, $i === 0);
            echo '<div class="imm-tk-week-body">';
            if ($events) {
                echo '<ul class="imm-tk-week-events">';
                foreach ($events as $e) {
                    $edit = get_edit_post_link($e->id);
                    $name = esc_html(IMM_TK_Digest::title($e));
                    echo '<li><span class="imm-tk-week-time">' . esc_html(IMM_TK_Sharepic::start_time($e)) . '</span> '
                        . ($edit ? '<a href="' . esc_url($edit) . '">' . $name . '</a>' : $name) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $name is escaped above.
                }
                echo '</ul>';
            }
            if ($out) {
                echo '<ul class="imm-tk-week-out">';
                foreach ($out as $item) {
                    echo '<li><span class="imm-tk-week-time">' . esc_html($item['time']) . '</span> <strong>' . esc_html($item['channel']) . '</strong> ' . esc_html($item['label']);
                    if (!empty($item['spec']) && IMM_TK_Sharepic::available()) {
                        $url = IMM_TK_Share::image_url($item['spec']);
                        echo ' <a class="imm-tk-thumb" href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="" width="27" height="48" loading="lazy"></a>';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            if (!$events && !$out) {
                echo '<p class="imm-tk-week-none">' . esc_html__('No events, nothing goes out.', 'event-toolkit-for-custom-fields') . '</p>';
            }
            echo '</div></li>';
        }
        echo '</ol>';
        echo '</div><aside class="imm-tk-aside">';
        self::panel([self::class, 'render_channels']);
        echo '</aside></div>';
    }

    /**
     * What the plugin sends out on a day, sorted by time.
     *
     * @return array<int, array{time: string, channel: string, label: string, spec?: array}>
     */
    private static function outgoing(DateTimeImmutable $day, array $events, DateTimeImmutable $now): array
    {
        $tf    = get_option('time_format') ?: 'H:i';
        $tz    = $day->getTimezone();
        $items = [];
        $at    = function (string $hm) use ($day): DateTimeImmutable {
            [$h, $m] = array_map('intval', explode(':', $hm));
            return $day->setTime($h, $m);
        };
        $issue_day = (int) $day->format('N') === (int) IMM_TK_Settings::get('digest_weekday');

        if ($issue_day) {
            $issue = $at((string) IMM_TK_Settings::get('digest_time'));
            if ($issue > $now) {
                $empty = IMM_TK_Digest::is_empty(IMM_TK_Digest::select($issue, $issue));
                $label = $empty ? __('Weekly preview (skipped: nothing in it)', 'event-toolkit-for-custom-fields') : __('Weekly preview', 'event-toolkit-for-custom-fields');
                if (IMM_TG_Settings::get('enabled') && IMM_TG_Settings::is_ready()) {
                    $items[] = ['sort' => $issue, 'channel' => 'Telegram', 'label' => $label];
                }
                if (IMM_TK_Settings::get('digest_feed_enabled')) {
                    $items[] = ['sort' => $issue, 'channel' => __('Newsletter', 'event-toolkit-for-custom-fields'), 'label' => $label];
                }
                if (IMM_TK_Settings::get('share_reminder') && !$empty) {
                    $items[] = ['sort' => $issue, 'channel' => __('Mail', 'event-toolkit-for-custom-fields'), 'label' => __('Reminder to share', 'event-toolkit-for-custom-fields')];
                }
            }
        }
        if ($events && !$issue_day && IMM_TG_Settings::get('today') && IMM_TG_Settings::is_ready()) {
            $time = $at((string) IMM_TG_Settings::get('today_time'));
            if ($time > $now) {
                $items[] = ['sort' => $time, 'channel' => 'Telegram', 'label' => __('"Today" message, silent', 'event-toolkit-for-custom-fields')];
            }
        }
        if (IMM_IG_Scheduler::active()) {
            $time = $at((string) IMM_IG_Instagram::get('time'));
            if ($time > $now) {
                foreach (IMM_IG_Scheduler::plan($time) as $story) {
                    $items[] = ['sort' => $time, 'channel' => 'Instagram', 'label' => $story['label'], 'spec' => $story['spec']];
                }
            }
        }
        usort($items, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        foreach ($items as &$item) {
            $item['time'] = wp_date($tf, $item['sort']->getTimestamp(), $tz);
            unset($item['sort']);
        }
        return $items;
    }

    /** Channels and their state. */
    private static function render_channels(): void
    {
        global $wp_locale;
        $tf      = get_option('time_format') ?: 'H:i';
        $weekday = $wp_locale->get_weekday((int) IMM_TK_Settings::get('digest_weekday') % 7);
        $digest  = sprintf(
            /* translators: 1: weekday, 2: time */
            __('every %1$s at %2$s', 'event-toolkit-for-custom-fields'),
            $weekday,
            wp_date($tf, IMM_TK_Digest::next_issue()->getTimestamp())
        );
        $off  = __('off', 'event-toolkit-for-custom-fields');
        $todo = __('not set up yet', 'event-toolkit-for-custom-fields');

        $tg_state = !IMM_TG_Settings::is_ready() ? 'todo' : (IMM_TG_Settings::get('enabled') ? 'on' : 'off');
        $ig_state = !IMM_IG_Instagram::is_ready() ? 'todo' : (IMM_IG_Instagram::get('enabled') ? 'on' : 'off');
        $channels = [
            ['calendar', __('Calendar', 'event-toolkit-for-custom-fields'), 'on', __('calendar files for every event and a subscription', 'event-toolkit-for-custom-fields')],
            ['search', __('Search engines', 'event-toolkit-for-custom-fields'), IMM_TK_Settings::get('schema_enabled') ? 'on' : 'off', IMM_TK_Settings::get('schema_enabled') ? __('event markup on every event page', 'event-toolkit-for-custom-fields') : $off],
            ['digest', __('Weekly preview', 'event-toolkit-for-custom-fields'), 'on', $digest],
            ['digest', __('Newsletter feed', 'event-toolkit-for-custom-fields'), IMM_TK_Settings::get('digest_feed_enabled') ? 'on' : 'off', IMM_TK_Settings::get('digest_feed_enabled') ? (IMM_TK_Settings::get('digest_feed_only_new') ? __('only in weeks with new events', 'event-toolkit-for-custom-fields') : __('every week', 'event-toolkit-for-custom-fields')) : $off],
            ['telegram', 'Telegram', $tg_state, $tg_state === 'on' ? $digest . (IMM_TG_Settings::get('today') ? ', ' . __('plus "Today" on event days', 'event-toolkit-for-custom-fields') : '') : ($tg_state === 'off' ? $off : $todo)],
            ['instagram', 'Instagram', $ig_state, $ig_state === 'on' ? sprintf(
                /* translators: 1: Instagram username, 2: time */
                __('@%1$s, daily at %2$s', 'event-toolkit-for-custom-fields'),
                (string) IMM_IG_Instagram::get('username'),
                (string) IMM_IG_Instagram::get('time')
            ) : ($ig_state === 'off' ? $off : $todo)],
            ['share', __('Reminder to share', 'event-toolkit-for-custom-fields'), IMM_TK_Settings::get('share_reminder') ? 'on' : 'off', IMM_TK_Settings::get('share_reminder') ? (string) IMM_TK_Settings::get('share_reminder_to') : $off],
        ];
        $labels = ['on' => __('On', 'event-toolkit-for-custom-fields'), 'off' => __('Off', 'event-toolkit-for-custom-fields'), 'todo' => __('To do', 'event-toolkit-for-custom-fields')];

        echo '<h2>' . esc_html__('Channels', 'event-toolkit-for-custom-fields') . '</h2><ul class="imm-tk-channels">';
        foreach ($channels as [$tab, $name, $state, $detail]) {
            echo '<li class="is-' . esc_attr($state) . '"><a href="' . esc_url(self::url($tab)) . '"><span class="imm-tk-channel-name">' . esc_html($name) . '</span>'
                . '<span class="imm-tk-badge">' . esc_html($labels[$state]) . '</span>'
                . '<span class="imm-tk-channel-detail">' . esc_html($detail) . '</span></a></li>';
        }
        echo '</ul>';
        echo '<p class="imm-tk-feed"><label for="imm-tk-feed-url">' . esc_html__('Calendar subscription address', 'event-toolkit-for-custom-fields') . '</label>'
            . '<input id="imm-tk-feed-url" type="text" class="large-text code" readonly value="' . esc_attr(IMM_TK_Links::get('webcal')) . '" data-imm-select></p>';
    }

    /** Runs a render function and wraps its output in a panel, unless it printed nothing. */
    private static function panel(callable $render, string $class = ''): void
    {
        ob_start();
        $render();
        $html = trim((string) ob_get_clean());
        if ($html !== '') {
            echo '<section class="imm-tk-panel' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">';
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the render function.
            echo '</section>';
        }
    }

    /**
     * Label/value rows as a definition list.
     *
     * @param array<string, string> $rows
     */
    private static function status_list(array $rows): void
    {
        echo '<dl class="imm-tk-status">';
        foreach ($rows as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl>';
    }

    /** Form with the core settings of one tab. */
    private static function render_core_form(string $tab): void
    {
        $s      = IMM_TK_Settings::all();
        $keys   = array_keys(IMM_TK_Settings::tabs()[$tab]);
        $name   = IMM_TK_Settings::OPTION;

        echo '<form method="post" action="options.php">';
        settings_fields('imm_tk');
        echo '<input type="hidden" name="' . esc_attr($name . '[_tab]') . '" value="' . esc_attr($tab) . '">';
        $groups = self::groups()[$tab] ?? [['', '', $keys]];
        foreach ($groups as [$title, $description, $group_keys]) {
            echo '<section class="imm-tk-panel">';
            if ($title !== '') {
                echo '<h2>' . esc_html($title) . '</h2>';
            }
            if ($description !== '') {
                echo '<p class="imm-tk-panel-note">' . esc_html($description) . '</p>';
            }
            echo '<table class="form-table" role="presentation">';
            foreach (array_intersect($group_keys, $keys) as $key) {
                self::render_field($key, $s);
            }
            echo '</table></section>';
        }
        submit_button(__('Save settings', 'event-toolkit-for-custom-fields'));
        echo '</form>';
    }

    /** One settings row. */
    private static function render_field(string $key, array $s): void
    {
        $fields = self::fields();
        $name   = IMM_TK_Settings::OPTION;
        if ($key === 'digest_time') {
            return; // Rendered together with the weekday.
        }
        [$label, $help, $type] = $fields[$key];
        $extra = $fields[$key][3] ?? '';
        $id    = 'imm-tk-' . $key;
        $field = $name . '[' . $key . ']';
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        switch ($type) {
            case 'check':
                echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="1" ' . checked(!empty($s[$key]), true, false) . '> ' . esc_html($extra) . '</label>';
                break;
            case 'textarea':
                echo '<textarea class="large-text" rows="3" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">' . esc_textarea($s[$key]) . '</textarea>';
                break;
            case 'weekday':
                global $wp_locale;
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">';
                for ($day = 1; $day <= 7; $day++) {
                    echo '<option value="' . (int) $day . '" ' . selected((int) $s[$key], $day, false) . '>' . esc_html($wp_locale->get_weekday($day % 7)) . '</option>';
                }
                echo '</select> <input type="time" name="' . esc_attr($name . '[digest_time]') . '" value="' . esc_attr($s['digest_time']) . '">';
                break;
            case 'choice':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field) . '">';
                foreach (IMM_TK_Settings::choices()[$key] as $value => $choice) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected((string) $s[$key], (string) $value, false) . '>' . esc_html($choice) . '</option>';
                }
                echo '</select>';
                if ($key === 'sharepic_fonts') {
                    $detected = IMM_TK_Fonts::detect();
                    $names    = array_unique(array_filter([$detected['heading'], $detected['text']]));
                    echo '<p class="description">' . esc_html($names
                        /* translators: %s: font names */
                        ? sprintf(__('Detected on the website: %s.', 'event-toolkit-for-custom-fields'), implode(', ', $names))
                        : __('No fonts detected on the website. Enter them below, or the bundled fonts are used.', 'event-toolkit-for-custom-fields')) . '</p>';
                }
                break;
            case 'font':
                $detected = IMM_TK_Fonts::detect()[$key === 'sharepic_heading_font' ? 'heading' : 'text'];
                echo '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '" placeholder="' . esc_attr($detected) . '">';
                break;
            case 'color':
                echo '<input type="color" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '">';
                break;
            default:
                $input = ['number' => 'number', 'url' => 'url', 'email' => 'email'][$type] ?? 'text';
                echo '<input type="' . esc_attr($input) . '" class="' . ($input === 'number' ? 'small-text' : 'regular-text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" value="' . esc_attr($s[$key]) . '"' . ($extra !== '' ? ' placeholder="' . esc_attr($extra) . '"' : '') . '>';
        }
        if ($help !== '') {
            echo '<p class="description">' . wp_kses($help, ['code' => [], 'em' => []]) . '</p>';
        }
        echo '</td></tr>';
    }

    /** Lists the fields of a sample post so nobody has to hunt for field names. */
    private static function render_field_finder(string $post_type): void
    {
        if ($post_type === '') {
            return;
        }
        $posts = get_posts(['post_type' => $post_type, 'numberposts' => 1, 'post_status' => 'publish']);
        if (!$posts) {
            return;
        }
        $meta = get_post_meta($posts[0]->ID);
        echo '<h2>' . esc_html__('Available fields', 'event-toolkit-for-custom-fields') . '</h2>';
        /* translators: %s: title of a sample post */
        echo '<p>' . esc_html(sprintf(__('Field names and values of the sample post "%s":', 'event-toolkit-for-custom-fields'), get_the_title($posts[0]))) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Field name', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Value', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($meta as $key => $values) {
            // Hide internal fields and ACF/ACPT helper fields.
            if ($key[0] === '_' || preg_match('/_(id|type|format|label)$/', $key)) {
                continue;
            }
            $value = is_string($values[0]) ? $values[0] : '';
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . esc_html(wp_html_excerpt($value, 80, '…')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_event_preview(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        $events = array_slice(IMM_TK_Endpoints::feed_events(), 0, 5);
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        if (!$events) {
            echo '<p>' . esc_html__('No events found in the feed period. Are the post type and the date field correct?', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        echo '<p class="imm-tk-panel-note">' . esc_html__('How the plugin reads the next events. If something is off, check the field names.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<ul class="imm-tk-check">';
        foreach ($events as $e) {
            if ($e->all_day) {
                $when = wp_date($date_format, $e->start->getTimestamp(), $e->start->getTimezone()) . ' ' . __('(all day)', 'event-toolkit-for-custom-fields');
            } else {
                $when = wp_date($date_format . ', ' . $time_format, $e->start->getTimestamp(), $e->start->getTimezone())
                    . ' – ' . wp_date(($e->end->format('Y-m-d') === $e->start->format('Y-m-d') ? '' : $date_format . ', ') . $time_format, $e->end->getTimestamp(), $e->end->getTimezone());
            }
            echo '<li><strong>' . esc_html(IMM_TK_Digest::title($e)) . '</strong>'
                . '<span>' . esc_html($when) . '</span>'
                . ($e->location !== '' ? '<span>' . esc_html($e->location) . '</span>' : '')
                . '<a href="' . esc_url(IMM_TK_Links::event_ics_url($e->id)) . '">' . esc_html__('Calendar file (.ics)', 'event-toolkit-for-custom-fields') . '</a></li>';
        }
        echo '</ul>';
    }

    private static function render_usage(): void
    {
        if (IMM_TK_Settings::is_configured()) {
            echo '<h2>' . esc_html__('Subscription address', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p><input type="text" class="large-text code" readonly data-imm-select value="' . esc_attr(IMM_TK_Links::get('feed')) . '"></p>';
        }
        echo '<h2>' . esc_html__('Usage', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p><strong>Breakdance:</strong> ' . esc_html__('In the link field of a button or text link, choose the dynamic data category "Add to Calendar".', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p><strong>' . esc_html__('Shortcode:', 'event-toolkit-for-custom-fields') . '</strong> <code>[imm_calendar_link type="ics" text="Add to calendar"]</code>. ' . esc_html__('Without "text" only the URL is returned.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>type</th><th>' . esc_html__('Link', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach (IMM_TK_Links::types() as $type => $label) {
            echo '<tr><td><code>' . esc_html($type) . '</code></td><td>' . esc_html($label) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_schema_preview(): void
    {
        if (!IMM_TK_Settings::is_configured() || !IMM_TK_Settings::get('schema_enabled')) {
            return;
        }
        $now  = new DateTimeImmutable('now', wp_timezone());
        $next = null;
        foreach (IMM_TK_Endpoints::feed_events() as $e) {
            if ($e->end >= $now) {
                $next = $e;
                break;
            }
        }
        if (!$next) {
            return;
        }
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        /* translators: %s: event title */
        echo '<p>' . esc_html(sprintf(__('This is what search engines read on the page of "%s":', 'event-toolkit-for-custom-fields'), IMM_TK_Digest::title($next))) . '</p>';
        echo '<pre style="max-width:900px;max-height:400px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:12px">'
            . esc_html(wp_json_encode(IMM_TK_Schema::build($next), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            . '</pre>';
        echo '<p><a href="' . esc_url('https://search.google.com/test/rich-results?url=' . rawurlencode($next->url)) . '" target="_blank" rel="noopener">'
            . esc_html__('Check this page with Google\'s Rich Results Test', 'event-toolkit-for-custom-fields') . '</a></p>';
    }

    /** The preview as if an issue went out right now. */
    private static function live_selection(): array
    {
        $now = new DateTimeImmutable('now', wp_timezone());
        return IMM_TK_Digest::select($now, $now);
    }

    private static function preview_note(): string
    {
        /* translators: %s: date and time of the next issue */
        return sprintf(__('This is what the weekly preview would look like if it went out now. Next issue: %s.', 'event-toolkit-for-custom-fields'), self::next_issue_text());
    }

    /** Next issue time as text, e.g. "Monday, November 2, 2026, 10:00". */
    private static function next_issue_text(): string
    {
        $next = IMM_TK_Digest::next_issue();
        return wp_date(get_option('date_format') . ', ' . get_option('time_format'), $next->getTimestamp(), $next->getTimezone());
    }

    private static function render_digest_info(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        if (IMM_TK_Settings::get('digest_feed_enabled')) {
            self::panel(function () {
                echo '<h2>' . esc_html__('Newsletter feed', 'event-toolkit-for-custom-fields') . '</h2>';
                echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr(IMM_TK_Digest_Feed::url()) . '" data-imm-select></p>';
                echo '<p>' . esc_html__('Enter this address in an RSS campaign of your newsletter service (e.g. Brevo, CleverReach or Mailchimp). The feed contains one entry per issue with the whole preview, so the service sends one mail per week. In weeks with no upcoming and no new events, the feed stays empty and no mail is sent.', 'event-toolkit-for-custom-fields') . '</p>';
            });
        }
        self::panel(function () {
            $selection = self::live_selection();
            echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p class="imm-tk-panel-note">' . esc_html(self::preview_note()) . '</p>';
            if (IMM_TK_Digest::is_empty($selection)) {
                echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
                return;
            }
            echo '<div class="imm-tk-mail">' . wp_kses_post(IMM_TK_Digest::email_html($selection)) . '</div>';
        });
    }

    /* ------------------------------------------------------------------ Telegram */

    private static function render_telegram(): void
    {
        $s     = IMM_TG_Settings::all();
        $name  = IMM_TG_Settings::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show.
        $notice = isset($_GET['imm_tg']) ? sanitize_key(wp_unslash($_GET['imm_tg'])) : '';

        self::render_telegram_notice($notice);
        echo '<div class="imm-tk-layout"><div class="imm-tk-main">';
        echo '<p>' . wp_kses(sprintf(
            /* translators: %s: link to the page "Weekly preview" */
            __('Schedule and content of the posts come from the page %s.', 'event-toolkit-for-custom-fields'),
            '<a href="' . esc_url(self::url('digest')) . '">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</a>'
        ), ['a' => ['href' => []]]) . '</p>';
        self::render_telegram_status();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('imm_tg'); ?>
            <section class="imm-tk-panel">
            <h2><?php esc_html_e('Bot and posts', 'event-toolkit-for-custom-fields'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Weekly digest', 'event-toolkit-for-custom-fields'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($field('enabled')); ?>" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Post automatically every week', 'event-toolkit-for-custom-fields'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-tg-token"><?php esc_html_e('Bot token', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="password" class="regular-text" id="imm-tg-token" name="<?php echo esc_attr($field('token')); ?>" value="" autocomplete="off"
                               placeholder="<?php echo $s['token'] !== '' ? esc_attr__('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : '123456789:AA…'; ?>">
                        <?php if ($s['token'] !== '') : ?>
                            <label style="margin-left:1em"><input type="checkbox" name="<?php echo esc_attr($field('delete_token')); ?>" value="1"> <?php esc_html_e('Delete token', 'event-toolkit-for-custom-fields'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php echo wp_kses(__('Create a bot with <code>@BotFather</code> in Telegram (command <code>/newbot</code>) and paste the token it gives you. Keep the token secret.', 'event-toolkit-for-custom-fields'), ['code' => []]); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-tg-chat"><?php esc_html_e('Chat ID', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="imm-tg-chat" name="<?php echo esc_attr($field('chat_id')); ?>" value="<?php echo esc_attr($s['chat_id']); ?>">
                        <p class="description"><?php esc_html_e('ID of the group or channel, e.g. -1001234567890. Use "Find chats" below to look it up.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-tg-thread"><?php esc_html_e('Topic ID (optional)', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="text" class="small-text" id="imm-tg-thread" name="<?php echo esc_attr($field('thread_id')); ?>" value="<?php echo esc_attr($s['thread_id']); ?>">
                        <p class="description"><?php esc_html_e('For groups with topics: the topic the digest goes to. Empty = the general chat.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-tg-preview-pos"><?php esc_html_e('Link preview', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <select id="imm-tg-preview-pos" name="<?php echo esc_attr($field('link_preview')); ?>">
                            <option value="above" <?php selected($s['link_preview'], 'above'); ?>><?php esc_html_e('Above the text', 'event-toolkit-for-custom-fields'); ?></option>
                            <option value="below" <?php selected($s['link_preview'], 'below'); ?>><?php esc_html_e('Below the text', 'event-toolkit-for-custom-fields'); ?></option>
                            <option value="none" <?php selected($s['link_preview'], 'none'); ?>><?php esc_html_e('No preview', 'event-toolkit-for-custom-fields'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Shows the preview of the next event page with its image, as when you post a link yourself. Image, title and description come from the page (e.g. set by your SEO plugin).', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('On event days', 'event-toolkit-for-custom-fields'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('today')); ?>" value="1" <?php checked(!empty($s['today'])); ?>> <?php esc_html_e('Short "Today" post at', 'event-toolkit-for-custom-fields'); ?></label>
                        <input type="time" name="<?php echo esc_attr($field('today_time')); ?>" value="<?php echo esc_attr($s['today_time']); ?>">
                        <p class="description"><?php esc_html_e('One silent message per day with all events of the day: members see it in the chat, but their phone does not ring. Not on the day of the weekly preview, which already lists them. Fewer notifications mean fewer members who mute the chat.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
            </table>
            </section>
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        self::panel(function () use ($notice) {
            self::render_chat_finder($notice === 'chats');
        });
        echo '</div><aside class="imm-tk-aside">';
        self::panel([self::class, 'render_telegram_preview']);
        echo '</aside></div>';
    }

    private static function render_telegram_notice(string $notice): void
    {
        $messages = [
            'sent'  => ['success', __('The preview was posted to the chat.', 'event-toolkit-for-custom-fields')],
            'empty' => ['warning', __('Nothing to post: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields')],
        ];
        if ($notice === 'error') {
            $error = get_transient('imm_tg_error_' . get_current_user_id());
            delete_transient('imm_tg_error_' . get_current_user_id());
            $messages['error'] = ['error', (string) $error];
        }
        if (isset($messages[$notice])) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
        }
    }

    private static function render_telegram_status(): void
    {
        $state  = IMM_TG_Settings::state();
        $next   = wp_next_scheduled(IMM_TG_Scheduler::HOOK);
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $rows   = [
            __('Next digest', 'event-toolkit-for-custom-fields') => $next ? wp_date($format, $next) : __('not scheduled (turn on the weekly digest and enter token and chat ID)', 'event-toolkit-for-custom-fields'),
            __('Last posted', 'event-toolkit-for-custom-fields') => $state['last_sent'] ? wp_date($format, $state['last_sent']) : '–',
        ];
        if ($state['last_run']) {
            $rows[__('Last run', 'event-toolkit-for-custom-fields')] = wp_date($format, $state['last_run']) . ': ' . $state['last_result'];
        }
        self::status_list($rows);
    }

    private static function render_chat_finder(bool $show_result): void
    {
        echo '<h2 id="imm-tg-chats">' . esc_html__('Find chats', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__('Add the bot to your group (or as administrator to your channel) and allow it to post messages.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . wp_kses(__('In a group with topics: open the topic and send <code>/start@YourBotName</code> there. In a channel: post any message. The bot does not answer; it only reads the chat ID.', 'event-toolkit-for-custom-fields'), ['code' => []]) . '</li>';
        echo '<li>' . esc_html__('Click the button. The chats and topics the bot has seen in the last 24 hours appear below.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '</ol>';

        if (IMM_TG_Settings::get('token') === '') {
            echo '<p><em>' . esc_html__('Save the bot token first.', 'event-toolkit-for-custom-fields') . '</em></p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="imm_tg_find_chats">';
        wp_nonce_field('imm_tg_find_chats');
        submit_button(__('Find chats', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
        echo '</form>';

        if (!$show_result) {
            return;
        }
        $data = get_transient('imm_tg_chats_' . get_current_user_id());
        if (!$data) {
            return;
        }
        if (is_wp_error($data['chats'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($data['chats']->get_error_message()) . '</p></div>';
            return;
        }
        if ($data['bot']) {
            /* translators: %s: bot username */
            echo '<p>' . esc_html(sprintf(__('Bot: @%s', 'event-toolkit-for-custom-fields'), $data['bot'])) . '</p>';
        }
        if (!$data['chats']) {
            echo '<p>' . esc_html__('The bot has not seen any chat yet. Follow the steps above and try again.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Chat', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Type', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Chat ID', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Topics (ID: name)', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($data['chats'] as $chat) {
            $topics = [];
            foreach ($chat['topics'] as $id => $topic) {
                $topics[] = '<code>' . esc_html($id) . '</code>' . ($topic !== '' ? ': ' . esc_html($topic) : '');
            }
            echo '<tr><td>' . esc_html($chat['title']) . '</td><td>' . esc_html($chat['type']) . '</td><td><code>' . esc_html($chat['id']) . '</code></td><td>' . wp_kses(implode('<br>', $topics), ['code' => [], 'br' => []]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_telegram_preview(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        echo '<h2 id="imm-tg-preview">' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        $selection = self::live_selection();
        $message   = IMM_TG_Telegram::message($selection);
        echo '<p class="imm-tk-panel-note">' . esc_html(self::preview_note()) . '</p>';
        if ($message === '') {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }

        // Approximation of Telegram's link preview: the featured image of the next event.
        $position = (string) IMM_TG_Settings::get('link_preview');
        $event    = IMM_TK_Digest::lead_event($selection);
        $image    = ($event && $position !== 'none') ? get_the_post_thumbnail_url($event->id, 'medium_large') : '';
        $figure   = $image ? '<img src="' . esc_url($image) . '" alt="" style="display:block;width:100%;height:auto;border-radius:6px;margin:8px 0">' : '';
        $html     = '<div class="imm-tk-bubble">'
            . ($position === 'above' ? $figure : '')
            . nl2br($message)
            . ($position === 'below' ? $figure : '')
            . '</div>';
        echo wp_kses($html, [
            'div' => ['class' => []],
            'img' => ['src' => [], 'alt' => [], 'style' => []],
            'a'   => ['href' => []],
            'b'   => [],
            'br'  => [],
        ]);

        if (!IMM_TG_Settings::is_ready()) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
        echo '<input type="hidden" name="action" value="imm_tg_send_test">';
        wp_nonce_field('imm_tg_send_test');
        submit_button(__('Post this preview to the chat now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('Goes to the chat and topic above. Does not change the weekly schedule.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '</form>';
    }

    /** Asks Telegram which chats and topics the bot has seen. */
    public static function handle_find_chats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('imm_tg_find_chats');

        $me    = IMM_TG_Telegram::call('getMe');
        $chats = is_wp_error($me) ? $me : IMM_TG_Telegram::recent_chats();
        set_transient('imm_tg_chats_' . get_current_user_id(), [
            'bot'   => is_wp_error($me) ? '' : ($me['username'] ?? ''),
            'chats' => $chats,
        ], HOUR_IN_SECONDS);

        wp_safe_redirect(self::url('telegram', ['imm_tg' => 'chats']) . '#imm-tg-chats');
        exit;
    }

    /** Posts the preview (as if an issue went out now) to the chat. Does not count as the weekly post. */
    public static function handle_send_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('imm_tg_send_test');

        $now    = new DateTimeImmutable('now', wp_timezone());
        $result = IMM_TG_Telegram::post($now, $now);
        if ($result === null) {
            $status = 'empty';
        } elseif (is_wp_error($result)) {
            set_transient('imm_tg_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS);
            $status = 'error';
        } else {
            $status = 'sent';
        }
        wp_safe_redirect(self::url('telegram', ['imm_tg' => $status]) . '#imm-tg-preview');
        exit;
    }
    /* ------------------------------------------------------------------ Share */

    private static function render_share(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        echo '<p>' . esc_html__('WhatsApp channels and Signal groups cannot be posted to automatically without costs or breaking their rules. Everything here is ready to post by hand: the text with WhatsApp formatting, and story images for status updates, Signal stories and Instagram.', 'event-toolkit-for-custom-fields') . '</p>';
        if (!IMM_TK_Sharepic::available()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Story images need the PHP extension GD with FreeType and JPEG support. Ask your host to enable it. The texts work without it.', 'event-toolkit-for-custom-fields') . '</p></div>';
        }
        echo '<p class="imm-tk-share-hint" data-imm-share-hint hidden>' . esc_html__('On your phone, "Share" hands the images and the text directly to WhatsApp, Signal or Instagram.', 'event-toolkit-for-custom-fields') . '</p>';

        $selection = self::live_selection();
        echo '<h2 class="imm-tk-section-title">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p class="imm-tk-panel-note">' . esc_html(self::preview_note()) . '</p>';
        if (IMM_TK_Digest::is_empty($selection)) {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
        } else {
            self::share_card(IMM_TK_Sharepic::week_frames($selection), IMM_TK_Share::week_text($selection), IMM_TK_Share::week_text($selection, true));
        }

        echo '<h2 class="imm-tk-section-title">' . esc_html__('Single events', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p class="imm-tk-panel-note">' . esc_html__('One announcement the day before and one on the day. Share the one that fits today.', 'event-toolkit-for-custom-fields') . '</p>';
        $days = IMM_TK_Share::upcoming_days(14);
        if (!$days) {
            echo '<p>' . esc_html__('No events in the next 14 days.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        foreach ($days as $date => $events) {
            echo '<section class="imm-tk-day">';
            self::date_badge($events[0]->start);
            echo '<div class="imm-tk-share-pair">';
            foreach (['tomorrow' => __('The day before', 'event-toolkit-for-custom-fields'), 'today' => __('On the day', 'event-toolkit-for-custom-fields')] as $variant => $label) {
                $text  = [];
                $plain = [];
                foreach ($events as $e) {
                    $text[]  = IMM_TK_Share::event_text($e, $variant);
                    $plain[] = IMM_TK_Share::event_text($e, $variant, true);
                }
                echo '<div class="imm-tk-share-col"><h3>' . esc_html($label) . '</h3>';
                self::share_card(IMM_TK_Sharepic::day_frames($events, $variant), implode("\n\n", $text), implode("\n\n", $plain));
                echo '</div>';
            }
            echo '</div></section>';
        }
    }

    /** Day of the month, large, with weekday and month: a page of a tear-off calendar. */
    private static function date_badge(DateTimeImmutable $day, bool $today = false): void
    {
        $ts = $day->getTimestamp();
        $tz = $day->getTimezone();
        echo '<div class="imm-tk-date' . ($today ? ' is-today' : '') . '">';
        echo '<span class="imm-tk-date-day">' . esc_html(wp_date('j', $ts, $tz)) . '</span>';
        echo '<span class="imm-tk-date-rest">' . esc_html(wp_date('l', $ts, $tz)) . '<br>' . esc_html(wp_date('F', $ts, $tz)) . '</span>';
        echo '</div>';
    }

    /** Images, text and buttons for one thing to share. */
    private static function share_card(array $frames, string $text, string $plain): void
    {
        $images = IMM_TK_Sharepic::available() ? $frames : [];
        echo '<div class="imm-tk-share" data-plain="' . esc_attr($plain) . '" data-copied="' . esc_attr__('Copied', 'event-toolkit-for-custom-fields') . '">';
        if ($images) {
            echo '<div class="imm-tk-share-images">';
            foreach ($images as $spec) {
                $full = IMM_TK_Share::image_url($spec);
                echo '<figure><a href="' . esc_url($full) . '" target="_blank" rel="noopener"><img src="' . esc_url($full) . '" data-full="' . esc_url($full) . '" alt="" width="135" height="240" loading="lazy"></a>'
                    . '<figcaption><a href="' . esc_url(IMM_TK_Share::image_url($spec, true)) . '">' . esc_html__('Download', 'event-toolkit-for-custom-fields') . '</a></figcaption></figure>';
            }
            echo '</div>';
        }
        echo '<div class="imm-tk-share-body">';
        echo '<textarea class="large-text" rows="8" readonly aria-label="' . esc_attr__('Text to share', 'event-toolkit-for-custom-fields') . '">' . esc_textarea($text) . '</textarea>';
        echo '<p class="imm-tk-share-buttons">';
        echo '<button type="button" class="button button-primary" data-imm-share hidden>' . esc_html__('Share …', 'event-toolkit-for-custom-fields') . '</button> ';
        echo '<button type="button" class="button" data-imm-copy="formatted">' . esc_html__('Copy text', 'event-toolkit-for-custom-fields') . '</button> ';
        echo '<button type="button" class="button" data-imm-copy="plain">' . esc_html__('Copy for Signal', 'event-toolkit-for-custom-fields') . '</button> ';
        echo '<a class="button" href="' . esc_url(IMM_TK_Share::whatsapp_url($text)) . '" target="_blank" rel="noopener">' . esc_html__('Open in WhatsApp', 'event-toolkit-for-custom-fields') . '</a>';
        echo '</p></div></div>';
    }

    /* ------------------------------------------------------------------ Instagram */

    private static function render_instagram(): void
    {
        $s     = IMM_IG_Instagram::all();
        $state = IMM_IG_Instagram::state();
        $name  = IMM_IG_Instagram::OPTION;
        $field = function (string $key) use ($name): string {
            return $name . '[' . $key . ']';
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show.
        $notice = isset($_GET['imm_ig']) ? sanitize_key(wp_unslash($_GET['imm_ig'])) : '';
        if ($notice !== '') {
            $message = get_transient('imm_ig_notice_' . get_current_user_id());
            delete_transient('imm_ig_notice_' . get_current_user_id());
            if (is_array($message)) {
                printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($message[0]), esc_html($message[1]));
            }
        }

        echo '<div class="imm-tk-layout"><div class="imm-tk-main">';
        echo '<p>' . esc_html__('Stories posted through the API cannot contain link stickers. The images show your web address instead.', 'event-toolkit-for-custom-fields') . '</p>';
        if (!IMM_TK_Sharepic::available()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Story images need the PHP extension GD with FreeType and JPEG support. Ask your host to enable it.', 'event-toolkit-for-custom-fields') . '</p></div>';
        }

        // Status.
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $next   = wp_next_scheduled(IMM_IG_Scheduler::HOOK);
        $rows   = [
            __('Account', 'event-toolkit-for-custom-fields')     => $s['username'] !== '' ? '@' . $s['username'] : __('not connected', 'event-toolkit-for-custom-fields'),
            __('Next stories', 'event-toolkit-for-custom-fields') => $next ? wp_date($format, $next) : __('not scheduled (turn on automatic stories and save an access token)', 'event-toolkit-for-custom-fields'),
        ];
        if ($s['token'] !== '' && $state['token_expires']) {
            $rows[__('Access token', 'event-toolkit-for-custom-fields')] = sprintf(
                /* translators: %s: date */
                __('valid until about %s, renewed automatically every week', 'event-toolkit-for-custom-fields'),
                wp_date(get_option('date_format'), (int) $state['token_expires'])
            );
        }
        if ($state['account_type'] !== '' && $state['account_type'] !== 'BUSINESS') {
            $rows[__('Note', 'event-toolkit-for-custom-fields')] = __('Instagram allows stories through the API only for business accounts. Switch the account type in the Instagram app (free of charge).', 'event-toolkit-for-custom-fields');
        }
        self::status_list($rows);
        $connected = IMM_IG_Instagram::is_ready();
        if (!$connected) {
            self::panel([self::class, 'render_instagram_setup']);
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('imm_ig'); ?>
            <section class="imm-tk-panel">
            <h2><?php esc_html_e('Connection and schedule', 'event-toolkit-for-custom-fields'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic stories', 'event-toolkit-for-custom-fields'); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr($field('enabled')); ?>" value="1" <?php checked(!empty($s['enabled'])); ?>> <?php esc_html_e('Post stories automatically', 'event-toolkit-for-custom-fields'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-ig-token"><?php esc_html_e('Access token', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="password" class="regular-text" id="imm-ig-token" name="<?php echo esc_attr($field('token')); ?>" value="" autocomplete="off"
                               placeholder="<?php echo $s['token'] !== '' ? esc_attr__('Saved. Leave empty to keep it.', 'event-toolkit-for-custom-fields') : 'IGAA…'; ?>">
                        <?php if ($s['token'] !== '') : ?>
                            <label style="margin-left:1em"><input type="checkbox" name="<?php echo esc_attr($field('delete_token')); ?>" value="1"> <?php esc_html_e('Delete token', 'event-toolkit-for-custom-fields'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('See "Connect Instagram" below. Keep the token secret.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="imm-ig-time"><?php esc_html_e('Time of day', 'event-toolkit-for-custom-fields'); ?></label></th>
                    <td>
                        <input type="time" id="imm-ig-time" name="<?php echo esc_attr($field('time')); ?>" value="<?php echo esc_attr($s['time']); ?>">
                        <p class="description"><?php esc_html_e('All stories of a day go out at this time. Stories stay visible for 24 hours, so the story of the day before ends when the one of the day begins.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Stories', 'event-toolkit-for-custom-fields'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('weekly')); ?>" value="1" <?php checked(!empty($s['weekly'])); ?>> <?php esc_html_e('Weekly overview, on the day of the weekly preview', 'event-toolkit-for-custom-fields'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('tomorrow')); ?>" value="1" <?php checked(!empty($s['tomorrow'])); ?>> <?php esc_html_e('Every event the day before', 'event-toolkit-for-custom-fields'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($field('today')); ?>" value="1" <?php checked(!empty($s['today'])); ?>> <?php esc_html_e('Every event on the day', 'event-toolkit-for-custom-fields'); ?></label>
                        <p class="description"><?php esc_html_e('So that followers do not see the same event too often: on the day of the weekly overview there is no extra story for the next day, and more than three events on one day share one story.', 'event-toolkit-for-custom-fields'); ?></p>
                    </td>
                </tr>
            </table>
            </section>
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        if ($connected) {
            echo '<div class="imm-tk-actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="imm_ig_check">';
            wp_nonce_field('imm_ig_check');
            submit_button(__('Check connection', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
            echo '</form>';
            if (IMM_IG_Instagram::get('enabled')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="imm_ig_post_now">';
                wp_nonce_field('imm_ig_post_now');
                submit_button(__('Post today\'s stories now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '</div>';
            if (IMM_IG_Instagram::get('enabled')) {
                echo '<p class="description">' . esc_html__('Posts the stories planned for today that are not online yet. Stories already posted are not posted twice.', 'event-toolkit-for-custom-fields') . '</p>';
            }
            echo '<details class="imm-tk-details"><summary>' . esc_html__('Connect Instagram', 'event-toolkit-for-custom-fields') . '</summary>';
            self::render_instagram_setup();
            echo '</details>';
        }

        echo '</div><aside class="imm-tk-aside">';
        self::panel([self::class, 'render_instagram_plan']);
        if ($state['log']) {
            self::panel(function () use ($state, $format) {
                echo '<h2>' . esc_html__('Recent activity', 'event-toolkit-for-custom-fields') . '</h2><ul class="imm-tk-log">';
                foreach (array_slice($state['log'], 0, 15) as $entry) {
                    echo '<li' . (empty($entry[2]) ? ' class="is-error"' : '') . '><time>' . esc_html(wp_date($format, (int) $entry[0])) . '</time> ' . esc_html((string) $entry[1]) . '</li>';
                }
                echo '</ul>';
            });
        }
        echo '</aside></div>';
    }

    /** Steps to connect an Instagram account. */
    private static function render_instagram_setup(): void
    {
        echo '<h2>' . esc_html__('Connect Instagram', 'event-toolkit-for-custom-fields') . '</h2><ol class="imm-tk-steps">';
        echo '<li>' . esc_html__('In the Instagram app, switch the account to a business account (Settings → Account type and tools). This is free.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . wp_kses(__('On <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a>, create an app of the type "Business" and add the product "Instagram" with "API setup with Instagram login".', 'event-toolkit-for-custom-fields'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]) . '</li>';
        echo '<li>' . esc_html__('Under "Generate access tokens", add your Instagram account and generate a token. The app can stay in development mode; it is only used for your own account, so Meta does not need to review it.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . esc_html__('Paste the token above and save. The plugin reads the account and renews the token every week. If the site is unreachable for more than 60 days, generate a new token.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '</ol>';
        echo '<p class="imm-tk-panel-note">' . esc_html__('Instagram downloads the story images from your site, so the uploads folder must be publicly reachable (no maintenance mode or password protection).', 'event-toolkit-for-custom-fields') . '</p>';
    }

    /** The stories of the next seven days, with images. */
    private static function render_instagram_plan(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        echo '<h2>' . esc_html__('Plan for the next 7 days', 'event-toolkit-for-custom-fields') . '</h2>';
        if (!IMM_IG_Scheduler::active()) {
            echo '<p class="imm-tk-panel-note">' . esc_html__('This is what would go out once the stories are switched on.', 'event-toolkit-for-custom-fields') . '</p>';
        }
        $tz     = wp_timezone();
        $run    = IMM_IG_Scheduler::next_run(new DateTimeImmutable('today', $tz));
        $posted = (array) IMM_IG_Instagram::state()['posted'];
        $any    = false;
        echo '<ol class="imm-tk-plan">';
        for ($i = 0; $i < 7; $i++) {
            $day   = $run->modify('+' . $i . ' days');
            $items = IMM_IG_Scheduler::plan($day);
            if (!$items) {
                continue;
            }
            $any = true;
            echo '<li><h3>' . esc_html(wp_date('l, ' . get_option('date_format') . ', ' . get_option('time_format'), $day->getTimestamp(), $tz)) . '</h3><ul>';
            foreach ($items as $item) {
                self::story_chip($item['spec'], $item['label'], isset($posted[$item['key']]));
            }
            echo '</ul></li>';
        }
        echo '</ol>';
        if (!$any) {
            echo '<p>' . esc_html__('No stories in the next 7 days.', 'event-toolkit-for-custom-fields') . '</p>';
        }
    }

    /** A story thumbnail with its label. */
    private static function story_chip(array $spec, string $label, bool $done = false): void
    {
        echo '<li class="imm-tk-story">';
        if (IMM_TK_Sharepic::available()) {
            $url = IMM_TK_Share::image_url($spec);
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="" width="45" height="80" loading="lazy"></a>';
        }
        echo '<span>' . esc_html($label);
        if ($done) {
            echo ' <em class="imm-tk-done">' . esc_html__('posted', 'event-toolkit-for-custom-fields') . '</em>';
        }
        echo '</span></li>';
    }

    public static function handle_ig_check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('imm_ig_check');
        $me = IMM_IG_Instagram::me();
        if (is_wp_error($me)) {
            $notice = ['error', $me->get_error_message()];
        } else {
            IMM_IG_Instagram::update_state(['account_type' => (string) ($me['account_type'] ?? '')]);
            $limit  = IMM_IG_Instagram::call('GET', IMM_IG_Instagram::get('user_id') . '/content_publishing_limit', ['fields' => 'quota_usage,config']);
            $used   = is_wp_error($limit) ? '' : (string) ($limit['data'][0]['quota_usage'] ?? '');
            $notice = ['success', sprintf(
                /* translators: 1: Instagram username, 2: number of posts in the last 24 hours */
                __('Connected with @%1$s. Posts through the API in the last 24 hours: %2$s.', 'event-toolkit-for-custom-fields'),
                (string) ($me['username'] ?? ''),
                $used !== '' ? $used : '–'
            )];
        }
        set_transient('imm_ig_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('instagram', ['imm_ig' => 'notice']));
        exit;
    }

    public static function handle_ig_post_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'event-toolkit-for-custom-fields'));
        }
        check_admin_referer('imm_ig_post_now');
        $result = IMM_IG_Scheduler::post_due();
        if ($result['errors']) {
            $log    = IMM_IG_Instagram::state()['log'];
            $notice = ['error', (string) ($log[0][1] ?? __('Error', 'event-toolkit-for-custom-fields'))];
        } elseif ($result['posted']) {
            /* translators: %d: number of stories */
            $notice = ['success', sprintf(__('Stories posted: %d', 'event-toolkit-for-custom-fields'), $result['posted'])];
        } else {
            $notice = ['info', __('Nothing to post: no stories planned for today, or all of them are online already.', 'event-toolkit-for-custom-fields')];
        }
        set_transient('imm_ig_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url('instagram', ['imm_ig' => 'notice']));
        exit;
    }
}

