<?php
/**
 * RSS feed of the weekly preview, for newsletter services (Brevo, CleverReach, Mailchimp …).
 *
 * Address: /{base}/{digest_feed_slug}.xml
 *
 * The feed contains at most one item: the current issue, with the whole preview as HTML.
 * Its GUID changes with every issue, so an RSS-to-email campaign sends exactly one mail per
 * issue. In weeks with no upcoming and no new events the feed has no item and nothing is sent.
 * With "only when something is new", weeks without newly published events stay empty as well.
 */

defined('ABSPATH') || exit;

class ETCF_Digest_Feed
{
    public static function url(): string
    {
        $base = ETCF_Settings::get('base_path');
        $slug = ETCF_Settings::get('digest_feed_slug');
        if (get_option('permalink_structure')) {
            return home_url('/' . $base . '/' . $slug . '.xml');
        }
        return add_query_arg('etcf_digest', '1', home_url('/'));
    }

    public static function serve(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention of caching plugins.
        }
        if (!ETCF_Settings::get('digest_feed_enabled')) {
            status_header(404);
            nocache_headers();
            exit;
        }

        $issue     = ETCF_Digest::current_issue();
        $selection = ETCF_Digest::select($issue);

        status_header(200);
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=900');
        header('X-Robots-Tag: noindex');
        echo self::render($issue, $selection); // phpcs:ignore WordPress.Security.EscapeOutput -- XML built with esc_xml/CDATA below.
        exit;
    }

    public static function render(DateTimeImmutable $issue, array $selection): string
    {
        $site  = (string) ETCF_Settings::get('feed_title') ?: get_bloginfo('name');
        $link  = ETCF_Digest::program_url();
        $title = ETCF_Digest::issue_title($issue);

        $xml   = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">';
        $xml[] = '<channel>';
        /* translators: %s: name of the site or calendar */
        $xml[] = '<title>' . esc_xml(sprintf(__('%s: weekly preview', 'event-toolkit-for-custom-fields'), $site)) . '</title>';
        $xml[] = '<link>' . esc_url($link) . '</link>';
        $xml[] = '<atom:link href="' . esc_url(self::url()) . '" rel="self" type="application/rss+xml"/>';
        $xml[] = '<description>' . esc_xml(__('Upcoming events and new additions to the programme, once a week.', 'event-toolkit-for-custom-fields')) . '</description>';
        $xml[] = '<language>' . esc_xml(get_bloginfo('language')) . '</language>';
        $xml[] = '<lastBuildDate>' . esc_xml($issue->format(DATE_RSS)) . '</lastBuildDate>';
        $xml[] = '<ttl>360</ttl>';

        $send = !ETCF_Digest::is_empty($selection);
        if ($send && ETCF_Settings::get('digest_feed_only_new')) {
            $send = ETCF_Digest::has_new($selection, $issue);
        }
        if ($send) {
            $lead  = ETCF_Digest::lead_event($selection);
            $image = $lead ? get_the_post_thumbnail_url($lead->id, 'large') : '';
            $names = array_map([ETCF_Digest::class, 'title'], array_merge($selection['week'], $selection['new']));

            $xml[] = '<item>';
            $xml[] = '<title>' . esc_xml($title) . '</title>';
            $xml[] = '<link>' . esc_url($link) . '</link>';
            $xml[] = '<guid isPermaLink="false">' . esc_xml(home_url('/') . '#weekly-preview-' . $issue->format('Y-m-d')) . '</guid>';
            $xml[] = '<pubDate>' . esc_xml($issue->format(DATE_RSS)) . '</pubDate>';
            $xml[] = '<description>' . esc_xml(implode(' · ', $names)) . '</description>';
            $xml[] = '<content:encoded>' . self::cdata(ETCF_Digest::email_html($selection)) . '</content:encoded>';
            if ($image) {
                $xml[] = '<media:content url="' . esc_url($image) . '" medium="image"/>';
            }
            $xml[] = '</item>';
        }

        $xml[] = '</channel>';
        $xml[] = '</rss>';
        return implode("\n", $xml) . "\n";
    }

    private static function cdata(string $html): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . ']]>';
    }
}
