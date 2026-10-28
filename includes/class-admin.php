<?php
/**
 * Settings page under Settings → Event Toolkit, with one tab per area.
 */

defined('ABSPATH') || exit;

class IMM_TK_Admin
{
    const PAGE = 'event-toolkit-for-custom-fields';

    public static function init(): void
    {
        add_action('admin_menu', function () {
            add_options_page(
                __('Event Toolkit for Custom Fields', 'event-toolkit-for-custom-fields'),
                __('Event Toolkit', 'event-toolkit-for-custom-fields'),
                'manage_options',
                self::PAGE,
                [self::class, 'render']
            );
        });
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
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'settings_page_' . self::PAGE) {
                return;
            }
            wp_enqueue_script('imm-tk-share', plugins_url('assets/admin-share.js', IMM_TK_FILE), [], IMM_TK_VERSION, true);
            wp_enqueue_style('imm-tk-admin', plugins_url('assets/admin.css', IMM_TK_FILE), [], IMM_TK_VERSION);
        });
        add_filter('plugin_action_links_' . plugin_basename(IMM_TK_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'event-toolkit-for-custom-fields') . '</a>');
            return $links;
        });
    }

    private static function url(string $tab = '', array $args = []): string
    {
        $args = array_merge(['page' => self::PAGE], $tab !== '' ? ['tab' => $tab] : [], $args);
        return add_query_arg($args, admin_url('options-general.php'));
    }

    private static function tabs(): array
    {
        return [
            'events'   => __('Events', 'event-toolkit-for-custom-fields'),
            'calendar' => __('Calendar', 'event-toolkit-for-custom-fields'),
            'search'   => __('Search engines', 'event-toolkit-for-custom-fields'),
            'digest'    => __('Weekly preview', 'event-toolkit-for-custom-fields'),
            'share'     => __('Share', 'event-toolkit-for-custom-fields'),
            'telegram'  => 'Telegram',
            'instagram' => 'Instagram',
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the visible tab.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'events';
        $tab = isset($tabs[$tab]) ? $tab : 'events';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Event Toolkit for Custom Fields', 'event-toolkit-for-custom-fields'); ?></h1>
            <p><?php esc_html_e('Reads your events from existing fields (ACF, ACPT or plain post meta) and spreads them: calendar links, search engines, Telegram and newsletters. Nothing has to be entered twice.', 'event-toolkit-for-custom-fields'); ?></p>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $id => $label) : ?>
                    <a href="<?php echo esc_url(self::url($id)); ?>" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php settings_errors(); ?>
            <?php
            if ($tab === 'telegram') {
                self::render_telegram();
            } elseif ($tab === 'instagram') {
                self::render_instagram();
            } elseif ($tab === 'share') {
                self::render_share();
                echo '<h2 id="imm-tk-share-settings">' . esc_html__('Design and reminder', 'event-toolkit-for-custom-fields') . '</h2>';
                self::render_core_form('share');
            } else {
                self::render_core_form($tab);
                switch ($tab) {
                    case 'events':
                        self::render_field_finder((string) IMM_TK_Settings::get('post_type'));
                        self::render_event_preview();
                        break;
                    case 'calendar':
                        self::render_usage();
                        break;
                    case 'search':
                        self::render_schema_preview();
                        break;
                    case 'digest':
                        self::render_digest_info();
                        break;
                }
            }
            ?>
        </div>
        <?php
    }

    /** Form with the core settings of one tab. */
    private static function render_core_form(string $tab): void
    {
        $s      = IMM_TK_Settings::all();
        $fields = self::fields();
        $keys   = array_keys(IMM_TK_Settings::tabs()[$tab]);
        $name   = IMM_TK_Settings::OPTION;

        if ($tab !== 'events' && !IMM_TK_Settings::is_configured()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('First enter the post type and the date field in the tab "Events".', 'event-toolkit-for-custom-fields') . '</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields('imm_tk');
        echo '<input type="hidden" name="' . esc_attr($name . '[_tab]') . '" value="' . esc_attr($tab) . '">';
        echo '<table class="form-table" role="presentation">';
        foreach ($keys as $key) {
            if ($key === 'digest_time') {
                continue; // Rendered together with the weekday.
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
        echo '</table>';
        submit_button(__('Save settings', 'event-toolkit-for-custom-fields'));
        echo '</form>';
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
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('Field name', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Value', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
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
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
            . '<th>' . esc_html__('Event', 'event-toolkit-for-custom-fields') . '</th>'
            . '<th>' . esc_html__('Start', 'event-toolkit-for-custom-fields') . '</th>'
            . '<th>' . esc_html__('End', 'event-toolkit-for-custom-fields') . '</th>'
            . '<th>' . esc_html__('Location', 'event-toolkit-for-custom-fields') . '</th>'
            . '<th>' . esc_html__('File', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($events as $e) {
            if ($e->all_day) {
                $start = wp_date($date_format, $e->start->getTimestamp(), $e->start->getTimezone());
                $end   = wp_date($date_format, $e->end->modify('-1 day')->getTimestamp(), $e->end->getTimezone()) . ' ' . __('(all day)', 'event-toolkit-for-custom-fields');
            } else {
                $start = wp_date($date_format . ' ' . $time_format, $e->start->getTimestamp(), $e->start->getTimezone());
                $end   = wp_date($date_format . ' ' . $time_format, $e->end->getTimestamp(), $e->end->getTimezone());
            }
            echo '<tr><td>' . esc_html(IMM_TK_Digest::title($e)) . '</td><td>' . esc_html($start) . '</td><td>' . esc_html($end) . '</td><td>' . esc_html($e->location) . '</td><td><a href="' . esc_url(IMM_TK_Links::event_ics_url($e->id)) . '">.ics</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_usage(): void
    {
        if (IMM_TK_Settings::is_configured()) {
            echo '<p>' . esc_html__('Subscription URL:', 'event-toolkit-for-custom-fields') . ' <code>' . esc_html(IMM_TK_Links::get('feed')) . '</code></p>';
        }
        echo '<h2>' . esc_html__('Usage', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p><strong>Breakdance:</strong> ' . esc_html__('In the link field of a button or text link, choose the dynamic data category "Add to Calendar".', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<p><strong>' . esc_html__('Shortcode:', 'event-toolkit-for-custom-fields') . '</strong> <code>[imm_calendar_link type="ics" text="Add to calendar"]</code>. ' . esc_html__('Without "text" only the URL is returned.', 'event-toolkit-for-custom-fields') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>type</th><th>' . esc_html__('Link', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
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
            echo '<h2>' . esc_html__('Newsletter feed', 'event-toolkit-for-custom-fields') . '</h2>';
            echo '<p><code>' . esc_html(IMM_TK_Digest_Feed::url()) . '</code></p>';
            echo '<p>' . esc_html__('Enter this address in an RSS campaign of your newsletter service (e.g. Brevo, CleverReach or Mailchimp). The feed contains one entry per issue with the whole preview, so the service sends one mail per week. In weeks with no upcoming and no new events, the feed stays empty and no mail is sent.', 'event-toolkit-for-custom-fields') . '</p>';
        }

        $selection = self::live_selection();
        echo '<h2>' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p>' . esc_html(self::preview_note()) . '</p>';
        if (IMM_TK_Digest::is_empty($selection)) {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        echo '<div style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:8px 24px">' . wp_kses_post(IMM_TK_Digest::email_html($selection)) . '</div>';
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
        echo '<p>' . wp_kses(sprintf(
            /* translators: %s: link to the tab "Weekly preview" */
            __('Posts the weekly preview to a Telegram group, topic or channel. Schedule and content are set in the tab %s.', 'event-toolkit-for-custom-fields'),
            '<a href="' . esc_url(self::url('digest')) . '">' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</a>'
        ), ['a' => ['href' => []]]) . '</p>';
        self::render_telegram_status();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('imm_tg'); ?>
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
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        self::render_chat_finder($notice === 'chats');
        self::render_telegram_preview();
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
        echo '<table class="widefat striped" style="max-width:900px;margin:1em 0"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th style="width:12em">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
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
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('Chat', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Type', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Chat ID', 'event-toolkit-for-custom-fields') . '</th><th>' . esc_html__('Topics (ID: name)', 'event-toolkit-for-custom-fields') . '</th></tr></thead><tbody>';
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
        echo '<h2 id="imm-tg-preview">' . esc_html__('Preview', 'event-toolkit-for-custom-fields') . '</h2>';
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        $selection = self::live_selection();
        $message   = IMM_TG_Telegram::message($selection);
        echo '<p>' . esc_html(self::preview_note()) . '</p>';
        if ($message === '') {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }

        // Approximation of Telegram's link preview: the featured image of the next event.
        $position = (string) IMM_TG_Settings::get('link_preview');
        $event    = IMM_TK_Digest::lead_event($selection);
        $image    = ($event && $position !== 'none') ? get_the_post_thumbnail_url($event->id, 'medium_large') : '';
        $figure   = $image ? '<img src="' . esc_url($image) . '" alt="" style="display:block;width:100%;height:auto;border-radius:6px;margin:8px 0">' : '';
        $html     = '<div style="max-width:480px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:12px 16px;line-height:1.5">'
            . ($position === 'above' ? $figure : '')
            . nl2br($message)
            . ($position === 'below' ? $figure : '')
            . '</div>';
        echo wp_kses($html, [
            'div' => ['style' => []],
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
        echo '<p>' . esc_html__('WhatsApp channels and Signal groups cannot be posted to automatically without costs or breaking their rules. Here you find everything ready to post by hand: the text (with WhatsApp formatting) and story images for status updates, Signal stories and Instagram.', 'event-toolkit-for-custom-fields') . '</p>';
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        if (!IMM_TK_Sharepic::available()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Story images need the PHP extension GD with FreeType and JPEG support. Ask your host to enable it. The texts work without it.', 'event-toolkit-for-custom-fields') . '</p></div>';
        }
        echo '<p class="imm-tk-share-hint" data-imm-share-hint hidden>' . esc_html__('On your phone, "Share" hands the images and the text directly to WhatsApp, Signal or Instagram.', 'event-toolkit-for-custom-fields') . '</p>';

        $selection = self::live_selection();
        echo '<h2>' . esc_html__('Weekly preview', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p>' . esc_html(self::preview_note()) . '</p>';
        if (IMM_TK_Digest::is_empty($selection)) {
            echo '<p>' . esc_html__('Right now there would be nothing in it: no events in the coming days and nothing new.', 'event-toolkit-for-custom-fields') . '</p>';
        } else {
            self::share_card(IMM_TK_Sharepic::week_frames($selection), IMM_TK_Share::week_text($selection), IMM_TK_Share::week_text($selection, true));
        }

        echo '<h2>' . esc_html__('Single events', 'event-toolkit-for-custom-fields') . '</h2>';
        echo '<p>' . esc_html__('One announcement the day before and one on the day. Share the one that fits today.', 'event-toolkit-for-custom-fields') . '</p>';
        $days = IMM_TK_Share::upcoming_days(14);
        if (!$days) {
            echo '<p>' . esc_html__('No events in the next 14 days.', 'event-toolkit-for-custom-fields') . '</p>';
            return;
        }
        foreach ($days as $date => $events) {
            echo '<h3>' . esc_html(IMM_TK_Sharepic::long_date($events[0])) . '</h3>';
            echo '<div class="imm-tk-share-pair">';
            foreach (['tomorrow' => __('The day before', 'event-toolkit-for-custom-fields'), 'today' => __('On the day', 'event-toolkit-for-custom-fields')] as $variant => $label) {
                $text  = [];
                $plain = [];
                foreach ($events as $e) {
                    $text[]  = IMM_TK_Share::event_text($e, $variant);
                    $plain[] = IMM_TK_Share::event_text($e, $variant, true);
                }
                echo '<div class="imm-tk-share-col"><h4>' . esc_html($label) . '</h4>';
                self::share_card(IMM_TK_Sharepic::day_frames($events, $variant), implode("\n\n", $text), implode("\n\n", $plain));
                echo '</div>';
            }
            echo '</div>';
        }
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
        echo '<textarea class="large-text code" rows="8" readonly>' . esc_textarea($text) . '</textarea>';
        echo '<p class="imm-tk-share-buttons">';
        echo '<button type="button" class="button button-primary" data-imm-share hidden>' . esc_html__('Share …', 'event-toolkit-for-custom-fields') . '</button> ';
        echo '<button type="button" class="button" data-imm-copy="formatted">' . esc_html__('Copy text', 'event-toolkit-for-custom-fields') . '</button> ';
        echo '<button type="button" class="button" data-imm-copy="plain">' . esc_html__('Copy without formatting (Signal)', 'event-toolkit-for-custom-fields') . '</button> ';
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

        echo '<p>' . esc_html__('Posts stories to your Instagram account through the official Instagram API, free of charge: the weekly overview on the day of the weekly preview, and for every event one story the day before and one on the day.', 'event-toolkit-for-custom-fields') . '</p>';
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
        echo '<table class="widefat striped" style="max-width:900px;margin:1em 0"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th style="width:12em">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('imm_ig'); ?>
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
            <?php submit_button(__('Save settings', 'event-toolkit-for-custom-fields')); ?>
        </form>
        <?php
        if (IMM_IG_Instagram::is_ready()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px">';
            echo '<input type="hidden" name="action" value="imm_ig_check">';
            wp_nonce_field('imm_ig_check');
            submit_button(__('Check connection', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
            echo '</form>';
            if (IMM_IG_Instagram::get('enabled')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
                echo '<input type="hidden" name="action" value="imm_ig_post_now">';
                wp_nonce_field('imm_ig_post_now');
                submit_button(__('Post today\'s stories now', 'event-toolkit-for-custom-fields'), 'secondary', 'submit', false);
                echo '</form>';
                echo '<p class="description">' . esc_html__('Posts the stories planned for today that are not online yet. Stories already posted are not posted twice.', 'event-toolkit-for-custom-fields') . '</p>';
            }
        }

        self::render_instagram_plan();

        if ($state['log']) {
            echo '<h2>' . esc_html__('Recent activity', 'event-toolkit-for-custom-fields') . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
            foreach (array_slice($state['log'], 0, 15) as $entry) {
                echo '<tr><td style="width:12em">' . esc_html(wp_date($format, (int) $entry[0])) . '</td><td' . (empty($entry[2]) ? ' style="color:#b32d2e"' : '') . '>' . esc_html((string) $entry[1]) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Connect Instagram', 'event-toolkit-for-custom-fields') . '</h2><ol>';
        echo '<li>' . esc_html__('In the Instagram app, switch the account to a business account (Settings → Account type and tools). This is free.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . wp_kses(__('On <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a>, create an app of the type "Business" and add the product "Instagram" with "API setup with Instagram login".', 'event-toolkit-for-custom-fields'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]) . '</li>';
        echo '<li>' . esc_html__('Under "Generate access tokens", add your Instagram account and generate a token. The app can stay in development mode; it is only used for your own account, so Meta does not need to review it.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '<li>' . esc_html__('Paste the token above and save. The plugin reads the account and renews the token every week. If the site is unreachable for more than 60 days, generate a new token.', 'event-toolkit-for-custom-fields') . '</li>';
        echo '</ol>';
        echo '<p>' . esc_html__('Instagram downloads the story images from your site, so the uploads folder must be publicly reachable (no maintenance mode or password protection).', 'event-toolkit-for-custom-fields') . '</p>';
    }

    /** The stories of the next seven days, with images. */
    private static function render_instagram_plan(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        echo '<h2>' . esc_html__('Plan for the next 7 days', 'event-toolkit-for-custom-fields') . '</h2>';
        $tz     = wp_timezone();
        $run    = IMM_IG_Scheduler::next_run(new DateTimeImmutable('today', $tz));
        $posted = (array) IMM_IG_Instagram::state()['posted'];
        $any    = false;
        echo '<table class="widefat striped imm-tk-plan" style="max-width:900px"><tbody>';
        for ($i = 0; $i < 7; $i++) {
            $day   = $run->modify('+' . $i . ' days');
            $items = IMM_IG_Scheduler::plan($day);
            if (!$items) {
                continue;
            }
            $any = true;
            echo '<tr><th style="width:12em">' . esc_html(wp_date('D, ' . get_option('date_format'), $day->getTimestamp(), $tz)) . '</th><td>';
            foreach ($items as $item) {
                $done = isset($posted[$item['key']]) ? ' ✓' : '';
                $img  = IMM_TK_Sharepic::available() ? '<img src="' . esc_url(IMM_TK_Share::image_url($item['spec'])) . '" alt="" width="54" height="96" loading="lazy">' : '';
                echo '<span class="imm-tk-plan-item">' . wp_kses($img, ['img' => ['src' => [], 'alt' => [], 'width' => [], 'height' => [], 'loading' => []]]) . '<span>' . esc_html($item['label'] . $done) . '</span></span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$any) {
            echo '<p>' . esc_html__('No stories in the next 7 days.', 'event-toolkit-for-custom-fields') . '</p>';
        }
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

