<?php
/**
 * Updates from GitHub releases, for copies installed from GitHub.
 *
 * Only loaded when the plugin header has an "Update URI" pointing to GitHub. The copy
 * published on WordPress.org has neither this header nor this file (the release workflow
 * removes both), so it gets its updates from WordPress.org as usual.
 */

defined('ABSPATH') || exit;

class IMM_TK_Updater
{
    const REPO      = 'mblmg/event-toolkit-for-custom-fields';
    const SLUG      = 'event-toolkit-for-custom-fields';
    const TRANSIENT = 'imm_tk_latest_release';

    public static function init(): void
    {
        add_filter('update_plugins_github.com', [self::class, 'check'], 10, 3);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        // "Check again" on Dashboard → Updates also refreshes the release info.
        add_action('delete_site_transient_update_plugins', function () {
            delete_site_transient(self::TRANSIENT);
        });
    }

    /**
     * @param array|false $update
     * @param array       $plugin_data
     * @param string      $plugin_file
     * @return array|false
     */
    public static function check($update, $plugin_data, $plugin_file)
    {
        if ($plugin_file !== plugin_basename(IMM_TK_FILE)) {
            return $update;
        }
        $release = self::latest();
        if (!$release) {
            return $update;
        }
        return [
            'id'           => $plugin_data['UpdateURI'] ?? '',
            'slug'         => self::SLUG,
            'plugin'       => $plugin_file,
            'version'      => $release['version'],
            'new_version'  => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.3',
            'requires_php' => '7.4',
        ];
    }

    /**
     * Content of the "View details" window in the plugin list.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public static function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $release = self::latest();
        if (!$release) {
            return $result;
        }
        return (object) [
            'name'          => 'Event Toolkit for Custom Fields',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://i-make.media/">i-make.media</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '6.3',
            'requires_php'  => '7.4',
            'last_updated'  => $release['date'],
            'download_link' => $release['package'],
            'sections'      => [
                'changelog' => '<pre>' . esc_html($release['notes']) . '</pre>',
            ],
        ];
    }

    /**
     * Latest release from GitHub, cached for 12 hours (1 hour after an error).
     *
     * @return array{version: string, package: string, url: string, notes: string, date: string}|null
     */
    public static function latest(): ?array
    {
        $cached = get_site_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached['version'] !== '' ? $cached : null;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
        $release = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $package = '';
            foreach ((array) ($body['assets'] ?? []) as $asset) {
                if (($asset['name'] ?? '') === self::SLUG . '.zip') {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
            $version = ltrim((string) ($body['tag_name'] ?? ''), 'vV');
            if ($package !== '' && preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
                $release = [
                    'version' => $version,
                    'package' => $package,
                    'url'     => (string) ($body['html_url'] ?? 'https://github.com/' . self::REPO),
                    'notes'   => (string) ($body['body'] ?? ''),
                    'date'    => (string) ($body['published_at'] ?? ''),
                ];
            }
        }

        set_site_transient(
            self::TRANSIENT,
            $release ?: ['version' => ''],
            $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
        );
        return $release;
    }
}
