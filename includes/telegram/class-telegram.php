<?php
/**
 * Telegram channel of the weekly preview: builds the message and talks to the Bot API.
 */

defined('ABSPATH') || exit;

class IMM_TG_Telegram
{
    /**
     * Calls a Bot API method.
     *
     * @return array|WP_Error The "result" part of the response.
     */
    public static function call(string $method, array $params = [], string $token = '')
    {
        $token = $token !== '' ? $token : (string) IMM_TG_Settings::get('token');
        if ($token === '') {
            return new WP_Error('imm_tg_no_token', __('No bot token saved.', 'event-toolkit-for-custom-fields'));
        }
        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/' . $method, [
            'timeout' => 20,
            'body'    => $params,
        ]);
        if (is_wp_error($response)) {
            // Never pass on the request URL: it contains the token.
            return new WP_Error('imm_tg_http', __('Telegram could not be reached.', 'event-toolkit-for-custom-fields'));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['ok'])) {
            $description = is_array($body) && !empty($body['description']) ? $body['description'] : wp_remote_retrieve_response_message($response);
            /* translators: %s: error message from Telegram */
            return new WP_Error('imm_tg_api', sprintf(__('Telegram reports: %s', 'event-toolkit-for-custom-fields'), $description));
        }
        return $body['result'];
    }

    /**
     * Posts a message. With $preview_url, Telegram shows the preview (image, title,
     * description) of that page, like when a link is posted by hand.
     *
     * @return array|WP_Error
     */
    public static function send(string $html, string $preview_url = '', bool $silent = false)
    {
        $position = (string) IMM_TG_Settings::get('link_preview');
        if ($preview_url !== '' && $position !== 'none') {
            $preview = [
                'url'                => $preview_url,
                'prefer_large_media' => true,
                'show_above_text'    => $position === 'above',
            ];
        } else {
            $preview = ['is_disabled' => true];
        }
        $params = [
            'chat_id'              => IMM_TG_Settings::get('chat_id'),
            'text'                 => $html,
            'parse_mode'           => 'HTML',
            'link_preview_options' => wp_json_encode($preview),
        ];
        if ($silent) {
            $params['disable_notification'] = 'true';
        }
        $thread = (string) IMM_TG_Settings::get('thread_id');
        if ($thread !== '') {
            $params['message_thread_id'] = $thread;
        }
        return self::call('sendMessage', $params);
    }

    /**
     * Chats and topics the bot has recently seen, to find the chat ID and topic ID.
     *
     * @return array|WP_Error List of ['id', 'title', 'type', 'topics' => [id => name]].
     */
    public static function recent_chats()
    {
        $updates = self::call('getUpdates', [
            'allowed_updates' => wp_json_encode(['message', 'channel_post', 'my_chat_member']),
            'limit'           => 100,
        ]);
        if (is_wp_error($updates)) {
            return $updates;
        }
        $chats = [];
        foreach ($updates as $update) {
            $item = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
            if (!is_array($item) || empty($item['chat']['id'])) {
                continue;
            }
            $chat = $item['chat'];
            $id   = (string) $chat['id'];
            if (!isset($chats[$id])) {
                $title = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
                $chats[$id] = ['id' => $id, 'title' => $title, 'type' => $chat['type'] ?? '', 'topics' => []];
            }
            if (!empty($item['is_topic_message']) && !empty($item['message_thread_id'])) {
                $name = $item['reply_to_message']['forum_topic_created']['name']
                    ?? $item['forum_topic_created']['name']
                    ?? '';
                $chats[$id]['topics'][(string) $item['message_thread_id']] = $name;
            }
        }
        return array_values($chats);
    }

    const MAX_LENGTH = 4000; // Telegram allows 4096 characters per message.

    /**
     * Builds and posts the digest for an issue.
     *
     * @return array|WP_Error|null Telegram result, error, or null if there was nothing to post.
     */
    public static function post(DateTimeImmutable $issue, ?DateTimeImmutable $now = null)
    {
        $selection = IMM_TK_Digest::select($issue, $now);
        $message   = self::message($selection);
        if ($message === '') {
            return null;
        }
        $lead = IMM_TK_Digest::lead_event($selection);
        return self::send($message, $lead ? $lead->url : '');
    }

    /** The digest in Telegram HTML. Empty string if there is nothing to post. */
    public static function message(array $selection): string
    {
        if (IMM_TK_Digest::is_empty($selection)) {
            return '';
        }

        $blocks = [];
        $intro  = trim((string) IMM_TK_Settings::get('digest_intro'));
        if ($intro !== '') {
            $blocks[] = self::esc($intro);
        }

        if ($selection['week']) {
            $items = [];
            foreach ($selection['week'] as $event) {
                $lines   = ['<b>' . self::esc(IMM_TK_Digest::date($event)) . '</b>', self::link($event)];
                $details = array_filter([IMM_TK_Digest::time($event), IMM_TK_Digest::venue($event)]);
                if ($details) {
                    $lines[] = self::esc(implode(', ', $details));
                }
                $items[] = implode("\n", $lines);
            }
            $blocks[] = '<b>' . self::esc(IMM_TK_Digest::heading_week()) . "</b>\n\n" . implode("\n\n", $items);
        }

        if ($selection['new']) {
            $items = [];
            foreach ($selection['new'] as $event) {
                $items[] = self::esc(IMM_TK_Digest::date($event)) . ': ' . self::link($event);
            }
            $blocks[] = '<b>' . self::esc(IMM_TK_Digest::heading_new()) . "</b>\n" . implode("\n", $items);
        }

        $url      = IMM_TK_Digest::program_url();
        $label    = preg_replace('#^https?://(www\.)?#', '', untrailingslashit($url));
        $blocks[] = sprintf(self::esc(IMM_TK_Digest::footer_text()), '<a href="' . esc_url($url) . '">' . self::esc($label) . '</a>');

        $message = implode("\n\n", $blocks);

        // Too long: drop events from the end of the lists until it fits.
        if (mb_strlen($message) > self::MAX_LENGTH) {
            if ($selection['new']) {
                array_pop($selection['new']);
            } else {
                array_pop($selection['week']);
            }
            return self::message($selection);
        }

        return (string) apply_filters('imm_tg_message', $message, $selection);
    }

    /**
     * Posts today's events as one silent message (no notification sound).
     *
     * @return array|WP_Error|null Telegram result, error, or null if there is nothing today.
     */
    public static function post_today(DateTimeImmutable $now)
    {
        $events  = array_values(array_filter(IMM_TK_Share::events_on($now), function (IMM_TK_Event $e) use ($now) {
            return $e->end > $now;
        }));
        $message = self::today_message($events);
        if ($message === '') {
            return null;
        }
        return self::send($message, $events[0]->url, true);
    }

    /** "Today" message in Telegram HTML. Empty string without events. */
    public static function today_message(array $events): string
    {
        if (!$events) {
            return '';
        }
        $items = [];
        foreach ($events as $event) {
            $lines   = [self::link($event)];
            $details = array_filter([IMM_TK_Digest::time($event), IMM_TK_Digest::venue($event)]);
            if ($details) {
                $lines[] = self::esc(implode(', ', $details));
            }
            $items[] = implode("\n", $lines);
        }
        $message = '<b>' . self::esc(__('Today', 'event-toolkit-for-custom-fields')) . "</b>\n\n" . implode("\n\n", $items);
        return (string) apply_filters('imm_tg_today_message', $message, $events);
    }

    private static function link(IMM_TK_Event $event): string
    {
        return '<a href="' . esc_url($event->url) . '">' . self::esc(IMM_TK_Digest::title($event)) . '</a>';
    }

    /** Escapes text for Telegram's HTML mode (only &, < and > are special). */
    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}
