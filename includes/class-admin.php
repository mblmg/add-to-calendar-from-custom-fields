<?php
/**
 * Settings page under Settings → Add to Calendar.
 */

defined('ABSPATH') || exit;

class IMM_TK_Admin
{
    const PAGE = 'add-to-calendar-from-custom-fields';

    public static function init(): void
    {
        add_action('admin_menu', function () {
            add_options_page(
                __('Add to Calendar from Custom Fields', 'add-to-calendar-from-custom-fields'),
                __('Add to Calendar', 'add-to-calendar-from-custom-fields'),
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
        });
        // Rebuild the rewrite rules after saving.
        add_action('update_option_' . IMM_TK_Settings::OPTION, function () {
            IMM_TK_Endpoints::add_rewrite_rules();
            flush_rewrite_rules();
        });
        add_filter('plugin_action_links_' . plugin_basename(IMM_TK_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', 'add-to-calendar-from-custom-fields') . '</a>');
            return $links;
        });
    }

    private static function fields(): array
    {
        return [
            'post_type'        => [__('Post type', 'add-to-calendar-from-custom-fields'), __('Slug of the post type that holds the events, e.g. <code>event</code>.', 'add-to-calendar-from-custom-fields')],
            'date_key'         => [__('Date field', 'add-to-calendar-from-custom-fields'), __('Field name (meta key) of the date. Required.', 'add-to-calendar-from-custom-fields')],
            'end_date_key'     => [__('End date field (optional)', 'add-to-calendar-from-custom-fields'), __('Only needed for events spanning several days.', 'add-to-calendar-from-custom-fields')],
            'start_keys'       => [__('Start time fields', 'add-to-calendar-from-custom-fields'), __('One or more fields, separated by commas. The first field with a value is used. If none has a value, the event is added as an all-day event.', 'add-to-calendar-from-custom-fields')],
            'end_time_key'     => [__('End time field (optional)', 'add-to-calendar-from-custom-fields'), __('If the end time is earlier than the start time, the event ends on the next day.', 'add-to-calendar-from-custom-fields')],
            'default_duration' => [__('Default duration in minutes', 'add-to-calendar-from-custom-fields'), __('Used when no end time is set.', 'add-to-calendar-from-custom-fields')],
            'location_keys'    => [__('Location fields (optional)', 'add-to-calendar-from-custom-fields'), __('One or more fields, separated by commas. They are combined into one location.', 'add-to-calendar-from-custom-fields')],
            'description_key'  => [__('Description field (optional)', 'add-to-calendar-from-custom-fields'), __('Field for the calendar description. Empty = the post excerpt. The link to the post is always added.', 'add-to-calendar-from-custom-fields')],
            'base_path'        => [__('Base path', 'add-to-calendar-from-custom-fields'), __('Results in the addresses /<em>base</em>/event-123.ics and /<em>base</em>/<em>feed</em>.ics.', 'add-to-calendar-from-custom-fields')],
            'feed_slug'        => [__('Feed file name', 'add-to-calendar-from-custom-fields'), __('Without .ics.', 'add-to-calendar-from-custom-fields')],
            'feed_title'       => [__('Subscription calendar name', 'add-to-calendar-from-custom-fields'), __('The name subscribers see in their calendar app.', 'add-to-calendar-from-custom-fields')],
            'feed_past_days'   => [__('Past days in the feed', 'add-to-calendar-from-custom-fields'), __('How long past events stay visible in the subscription.', 'add-to-calendar-from-custom-fields')],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s    = IMM_TK_Settings::all();
        $name = IMM_TK_Settings::OPTION;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Add to Calendar from Custom Fields', 'add-to-calendar-from-custom-fields'); ?></h1>
            <p><?php esc_html_e('Reads the event data from existing fields (ACF, ACPT or plain post meta). Nothing has to be entered twice.', 'add-to-calendar-from-custom-fields'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('imm_tk'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (self::fields() as $key => [$label, $help]) : ?>
                        <tr>
                            <th scope="row"><label for="imm-tk-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <input type="<?php echo in_array($key, ['default_duration', 'feed_past_days'], true) ? 'number' : 'text'; ?>"
                                       class="regular-text" id="imm-tk-<?php echo esc_attr($key); ?>"
                                       name="<?php echo esc_attr($name . '[' . $key . ']'); ?>"
                                       value="<?php echo esc_attr($s[$key]); ?>">
                                <p class="description"><?php echo wp_kses($help, ['code' => [], 'em' => []]); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(__('Save settings', 'add-to-calendar-from-custom-fields')); ?>
            </form>

            <?php self::render_field_finder($s['post_type']); ?>
            <?php self::render_preview(); ?>
            <?php self::render_usage(); ?>
        </div>
        <?php
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
        echo '<h2>' . esc_html__('Available fields', 'add-to-calendar-from-custom-fields') . '</h2>';
        /* translators: %s: title of a sample post */
        echo '<p>' . esc_html(sprintf(__('Field names and values of the sample post "%s":', 'add-to-calendar-from-custom-fields'), get_the_title($posts[0]))) . '</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('Field name', 'add-to-calendar-from-custom-fields') . '</th><th>' . esc_html__('Value', 'add-to-calendar-from-custom-fields') . '</th></tr></thead><tbody>';
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

    private static function render_preview(): void
    {
        if (!IMM_TK_Settings::is_configured()) {
            return;
        }
        $events = array_slice(IMM_TK_Endpoints::feed_events(), 0, 5);
        echo '<h2>' . esc_html__('Preview', 'add-to-calendar-from-custom-fields') . '</h2>';
        echo '<p>' . esc_html__('Subscription URL:', 'add-to-calendar-from-custom-fields') . ' <code>' . esc_html(IMM_TK_Links::get('feed')) . '</code></p>';
        if (!$events) {
            echo '<p>' . esc_html__('No events found in the feed period. Are the post type and the date field correct?', 'add-to-calendar-from-custom-fields') . '</p>';
            return;
        }
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
            . '<th>' . esc_html__('Event', 'add-to-calendar-from-custom-fields') . '</th>'
            . '<th>' . esc_html__('Start', 'add-to-calendar-from-custom-fields') . '</th>'
            . '<th>' . esc_html__('End', 'add-to-calendar-from-custom-fields') . '</th>'
            . '<th>' . esc_html__('Location', 'add-to-calendar-from-custom-fields') . '</th>'
            . '<th>' . esc_html__('File', 'add-to-calendar-from-custom-fields') . '</th></tr></thead><tbody>';
        foreach ($events as $e) {
            if ($e->all_day) {
                $start = wp_date($date_format, $e->start->getTimestamp(), $e->start->getTimezone());
                $end   = wp_date($date_format, $e->end->modify('-1 day')->getTimestamp(), $e->end->getTimezone()) . ' ' . esc_html__('(all day)', 'add-to-calendar-from-custom-fields');
            } else {
                $start = wp_date($date_format . ' ' . $time_format, $e->start->getTimestamp(), $e->start->getTimezone());
                $end   = wp_date($date_format . ' ' . $time_format, $e->end->getTimestamp(), $e->end->getTimezone());
            }
            echo '<tr><td>' . esc_html($e->title) . '</td><td>' . esc_html($start) . '</td><td>' . esc_html($end) . '</td><td>' . esc_html($e->location) . '</td><td><a href="' . esc_url(IMM_TK_Links::event_ics_url($e->id)) . '">.ics</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_usage(): void
    {
        echo '<h2>' . esc_html__('Usage', 'add-to-calendar-from-custom-fields') . '</h2>';
        echo '<p><strong>Breakdance:</strong> ' . esc_html__('In the link field of a button or text link, choose the dynamic data category "Add to Calendar".', 'add-to-calendar-from-custom-fields') . '</p>';
        echo '<p><strong>' . esc_html__('Shortcode:', 'add-to-calendar-from-custom-fields') . '</strong> <code>[imm_calendar_link type="ics" text="Add to calendar"]</code>. ' . esc_html__('Without "text" only the URL is returned.', 'add-to-calendar-from-custom-fields') . '</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>type</th><th>' . esc_html__('Link', 'add-to-calendar-from-custom-fields') . '</th></tr></thead><tbody>';
        foreach (IMM_TK_Links::types() as $type => $label) {
            echo '<tr><td><code>' . esc_html($type) . '</code></td><td>' . esc_html($label) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
