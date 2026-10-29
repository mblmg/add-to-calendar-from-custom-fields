<?php
/**
 * Instagram: settings, state and the official Instagram API.
 *
 * Stories are published in two steps: create a media container from a public JPEG URL
 * (media_type=STORIES), then publish the container. The API is free of charge; the account
 * must be a professional (business) account, and the access token comes from the site owner's
 * own Meta app. Two kinds of token work:
 *
 * - Unified Login (all apps created since 2026): a system user access token of the business
 *   portfolio (EAA…), on graph.facebook.com. It usually does not expire; the Instagram account
 *   is read from /me/assigned_instagram_accounts.
 * - Instagram Login (older apps): an Instagram user token (IGAA…), on graph.instagram.com.
 *   It expires after 60 days and is renewed here every week.
 */

defined('ABSPATH') || exit;

class ETCF_Instagram
{
    const OPTION = 'etcf_ig_settings';
    const STATE  = 'etcf_ig_state';
    /** API host for Instagram user tokens (Instagram Login). */
    const API_INSTAGRAM = 'https://graph.instagram.com/';
    /** API host for system user tokens (Unified Login) and Facebook tokens. */
    const API_FACEBOOK = 'https://graph.facebook.com/v26.0/';

    /** Set while the plugin itself saves the settings (token refresh), to skip the checks in sanitize(). */
    private static $internal = false;

    /* ------------------------------------------------------------ settings */

    public static function defaults(): array
    {
        return [
            'enabled'  => 0,
            'token'    => '',
            'user_id'  => '',
            'username' => '',
            'time'     => '10:00',
            'weekly'   => 1,
            'tomorrow' => 1,
            'today'    => 1,
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_intersect_key(wp_parse_args(is_array($saved) ? $saved : [], self::defaults()), self::defaults());
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function sanitize($input): array
    {
        if (self::$internal && is_array($input)) {
            return $input;
        }
        $old   = self::all();
        $input = is_array($input) ? $input : [];
        $out   = $old;

        foreach (['enabled', 'weekly', 'tomorrow', 'today'] as $key) {
            $out[$key] = empty($input[$key]) ? 0 : 1;
        }
        $time        = trim((string) ($input['time'] ?? ''));
        $out['time'] = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : $old['time'];

        // An empty token field keeps the saved token. A new token is checked right away.
        $token = trim((string) ($input['token'] ?? ''));
        if (!empty($input['delete_token'])) {
            $out['token']    = '';
            $out['user_id']  = '';
            $out['username'] = '';
            self::update_state(['token_saved' => 0, 'token_refreshed' => 0, 'token_expires' => 0]);
        } elseif ($token !== '' && $token !== $old['token']) {
            $account = self::account($token);
            if (is_wp_error($account)) {
                self::settings_error(sprintf(
                    /* translators: %s: error message */
                    __('The access token was not saved: %s', 'event-toolkit-for-custom-fields'),
                    $account->get_error_message()
                ));
            } else {
                $out['token']    = $token;
                $out['user_id']  = $account['id'];
                $out['username'] = $account['username'];
                self::update_state([
                    'token_saved'     => time(),
                    'token_refreshed' => 0,
                    // Instagram user tokens last 60 days; system user tokens usually do not expire.
                    'token_expires'   => self::is_instagram_token($token) ? time() + 60 * DAY_IN_SECONDS : 0,
                    'account_type'    => $account['account_type'],
                ]);
            }
        }
        return $out;
    }

    private static function settings_error(string $message): void
    {
        if (function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'etcf_ig', $message);
        }
    }

    public static function is_ready(): bool
    {
        return self::get('token') !== '' && self::get('user_id') !== '';
    }

    public static function state(): array
    {
        $state = get_option(self::STATE, []);
        return wp_parse_args(is_array($state) ? $state : [], [
            'posted'          => [],  // key => Unix time, for stories already published.
            'log'             => [],  // Recent results: [time, text, ok].
            'retries'         => [],  // Y-m-d => number of retries that day.
            'token_saved'     => 0,
            'token_refreshed' => 0,
            'token_expires'   => 0,
            'account_type'    => '',
        ]);
    }

    public static function update_state(array $changes): void
    {
        update_option(self::STATE, array_merge(self::state(), $changes), false);
    }

    public static function log(string $text, bool $ok = true): void
    {
        $log = self::state()['log'];
        array_unshift($log, [time(), $text, $ok]);
        self::update_state(['log' => array_slice($log, 0, 30)]);
    }

    /* ------------------------------------------------------------ API */

    /**
     * Calls the Instagram API.
     *
     * @return array|WP_Error
     */
    public static function call(string $method, string $path, array $params = [], string $token = '')
    {
        $token                  = $token !== '' ? $token : (string) self::get('token');
        $params['access_token'] = $token;
        $url  = (self::is_instagram_token($token) ? self::API_INSTAGRAM : self::API_FACEBOOK) . ltrim($path, '/');
        $args = ['timeout' => 30];
        if ($method === 'GET') {
            $response = wp_remote_get(add_query_arg(array_map('rawurlencode', $params), $url), $args);
        } else {
            $args['body'] = $params;
            $response     = wp_remote_post($url, $args);
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            /* translators: %d: HTTP status code */
            return new WP_Error('etcf_ig_api', sprintf(__('Instagram answered with HTTP status %d.', 'event-toolkit-for-custom-fields'), wp_remote_retrieve_response_code($response)));
        }
        if (isset($body['error'])) {
            $message = (string) ($body['error']['error_user_msg'] ?? $body['error']['message'] ?? 'unknown error');
            /* translators: %s: error message from Instagram */
            return new WP_Error('etcf_ig_api', sprintf(__('Instagram reports: %s', 'event-toolkit-for-custom-fields'), $message), $body['error']);
        }
        return $body;
    }

    /** An Instagram user token (Instagram Login) rather than a system user token (Unified Login). */
    public static function is_instagram_token(string $token): bool
    {
        return strncmp($token, 'IG', 2) === 0;
    }

    /**
     * The Instagram account a token posts to.
     *
     * @return array|WP_Error ['id' => …, 'username' => …, 'account_type' => … (empty if unknown)]
     */
    public static function account(string $token = '')
    {
        $token = $token !== '' ? $token : (string) self::get('token');
        if (self::is_instagram_token($token)) {
            $me = self::call('GET', 'me', ['fields' => 'user_id,username,account_type'], $token);
            if (is_wp_error($me)) {
                return $me;
            }
            return [
                'id'           => (string) ($me['user_id'] ?? $me['id'] ?? ''),
                'username'     => (string) ($me['username'] ?? ''),
                'account_type' => (string) ($me['account_type'] ?? ''),
            ];
        }
        // System user token: /me is the system user; the shared Instagram accounts are listed separately.
        $list = self::call('GET', 'me/assigned_instagram_accounts', ['fields' => 'id,username'], $token);
        if (is_wp_error($list)) {
            return $list;
        }
        $first = $list['data'][0] ?? null;
        if (!is_array($first) || empty($first['id'])) {
            return new WP_Error('etcf_ig_no_account', __('The token works, but no Instagram account is shared with it. Generate the token again and select the Instagram account.', 'event-toolkit-for-custom-fields'));
        }
        return ['id' => (string) $first['id'], 'username' => (string) ($first['username'] ?? ''), 'account_type' => ''];
    }

    /**
     * Refreshes a long-lived Instagram user token (possible once it is 24 hours old, until it expires).
     *
     * @return true|WP_Error
     */
    public static function refresh_token()
    {
        $result = self::call('GET', 'refresh_access_token', ['grant_type' => 'ig_refresh_token']);
        if (is_wp_error($result)) {
            return $result;
        }
        if (empty($result['access_token'])) {
            return new WP_Error('etcf_ig_refresh', __('Instagram did not return a new token.', 'event-toolkit-for-custom-fields'));
        }
        $settings          = self::all();
        $settings['token'] = (string) $result['access_token'];
        self::$internal = true;
        update_option(self::OPTION, $settings);
        self::$internal = false;
        self::update_state([
            'token_refreshed' => time(),
            'token_expires'   => time() + (int) ($result['expires_in'] ?? 60 * DAY_IN_SECONDS),
        ]);
        return true;
    }

    /** Refreshes the token if the last refresh is more than a week ago. */
    public static function maybe_refresh_token(): void
    {
        $state = self::state();
        $last  = max((int) $state['token_saved'], (int) $state['token_refreshed']);
        if (!self::is_ready() || !self::is_instagram_token((string) self::get('token')) || !$last || time() - $last < 7 * DAY_IN_SECONDS) {
            return;
        }
        $result = self::refresh_token();
        if (is_wp_error($result)) {
            /* translators: %s: error message */
            self::log(sprintf(__('Token could not be renewed: %s', 'event-toolkit-for-custom-fields'), $result->get_error_message()), false);
        }
    }

    /**
     * Publishes a JPEG at a public URL as story.
     *
     * @return string|WP_Error ID of the published story.
     */
    public static function publish_story(string $image_url)
    {
        $user      = (string) self::get('user_id');
        $container = self::call('POST', $user . '/media', ['media_type' => 'STORIES', 'image_url' => $image_url]);
        if (is_wp_error($container)) {
            return $container;
        }
        $id = (string) ($container['id'] ?? '');
        if ($id === '') {
            return new WP_Error('etcf_ig_container', __('Instagram did not create the story.', 'event-toolkit-for-custom-fields'));
        }

        // Images are usually ready at once; wait a little if not.
        for ($i = 0; $i < 6; $i++) {
            $status = self::call('GET', $id, ['fields' => 'status_code']);
            $code   = is_wp_error($status) ? '' : (string) ($status['status_code'] ?? '');
            if ($code === 'FINISHED' || $code === '') {
                break;
            }
            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                /* translators: %s: status reported by Instagram */
                return new WP_Error('etcf_ig_status', sprintf(__('Instagram could not process the image (%s).', 'event-toolkit-for-custom-fields'), $code));
            }
            sleep(5);
        }

        $published = self::call('POST', $user . '/media_publish', ['creation_id' => $id]);
        if (is_wp_error($published)) {
            return $published;
        }
        return (string) ($published['id'] ?? '');
    }
}
