# Event Toolkit for Custom Fields

WordPress plugin that spreads the events you keep in custom fields (ACF, ACPT or plain post meta):

- an `.ics` file per event, links for Google Calendar, Outlook.com and Outlook / Microsoft 365
- a subscribable calendar feed (`webcal://`) with all upcoming events
- schema.org Event markup (JSON-LD) on each event page for search engines
- a weekly preview (upcoming events + new additions), posted to Telegram and provided as RSS feed for e-mail newsletters
- story images (1080 × 1920) in the website's fonts, posted as Instagram stories (official API) and ready to share by hand in WhatsApp and Signal
- all calendar links as Breakdance dynamic data and as a shortcode
- abilities for AI agents (WordPress Abilities API, exposed by MCP adapters)

User documentation lives in [`readme.txt`](readme.txt), which is also what the WordPress.org plugin directory shows. Plans and design decisions are in [`ROADMAP.md`](ROADMAP.md).

## Development

```
event-toolkit-for-custom-fields.php        bootstrap
includes/class-settings.php                core settings (option etcf_settings), saved per tab
includes/class-event.php                   reads an event from post meta, start/end/doors rules
includes/class-ics.php                     iCalendar output (RFC 5545)
includes/class-links.php                   .ics, Google, Outlook and feed URLs
includes/class-endpoints.php               rewrite rules and file delivery
includes/class-schema.php                  schema.org Event (JSON-LD) on event pages
includes/class-digest.php                  weekly preview: issue times, event selection, e-mail HTML
includes/class-digest-feed.php             RSS feed of the weekly preview (one item per issue)
includes/class-fonts.php                   fonts of the website for the story images (detection, Google Fonts download)
includes/class-sharepic.php                story images with GD (frames, rendering, cache in uploads/event-toolkit/stories)
includes/class-share.php                   share texts for messengers, image endpoints (admin, signed public URLs), reminder mail
includes/class-share-kit.php               the share kit (today, weekly preview, later), in the admin and on the website
includes/class-front.php                   shortcodes for the website: share button on event pages, share page for members
includes/telegram/                         Telegram: settings (etcf_tg_settings), Bot API, weekly and "Today" schedule
includes/instagram/                        Instagram: settings and API (etcf_ig_settings), daily story schedule
includes/social/                           Mastodon and Bluesky (etcf_so_settings)
includes/newsletter/                       direct sending through Brevo or Emailit (etcf_nl_settings)
includes/class-status.php                  cancelled / postponed events, one-time notices
includes/class-qr.php                      QR encoder (SVG, PNG, cache in uploads/event-toolkit/qr)
assets/                                    share script, admin and website styles
includes/class-admin.php                   admin menu "Event Toolkit": overview and one page per area
includes/class-abilities.php               abilities (WordPress Abilities API) for AI agents / MCP
includes/shortcode.php                     [etcf_calendar_link]
includes/breakdance*.php                   Breakdance dynamic data fields
languages/                                 .pot template and German translation (not shipped, see Translations)
```

Everything global uses the prefix `etcf` (WordPress.org requires a distinct prefix of at least four characters): classes `ETCF_*`, constants `ETCF_*`, options, hooks, filters, cron events, nonces, admin-post actions and transients `etcf_*`, shortcodes `etcf_*`, Breakdance fields `etcf_*`, abilities `etcf/*`, script and style handles, CSS classes and custom properties `etcf-*`. The iCalendar UID of an event is `etcf-{ID}@host`; it must not change once calendars subscribe, or they list the events twice.

### Weekly preview

An issue belongs to a scheduled time (weekday and time in the settings). It lists the events starting within the next days and the events published during the week before the issue. Because both parts depend only on the issue time, Telegram and the newsletter feed always show the same content. The feed's item GUID changes per issue, so RSS-to-email campaigns send one mail per issue.

### Reminder rhythm

Channels differ in how intrusive they are, so they get different rhythms (see the roadmap in the project for the reasoning): the newsletter at most weekly; Telegram the weekly preview plus an optional silent "Today" message; Instagram stories (pull, no notification) the weekly overview, the day before and the day itself, without the day-before story on the issue day and with one shared story for more than three events a day.

### Translations

English is the source language. WordPress.org delivers translations from translate.wordpress.org, so the plugin ships no language files and does not call `load_plugin_textdomain()` (`languages/` is in `.distignore`). The folder stays in the repository: the German `.po` can be imported on translate.wordpress.org, and for test sites the compiled `.mo` and `.l10n.php` go to `wp-content/languages/plugins/`. After changing strings, update the `.pot` and the German `.po`, then compile with `wp i18n make-mo languages` and `wp i18n make-php languages`.

## Releasing

1. Bump the version in three places: the plugin header (`Version:`), the `ETCF_VERSION` constant and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. Publish a GitHub release with the version as new tag (e.g. `2.0.1`).
3. The **Release** workflow checks the version numbers, builds the zip and attaches it to the release.
4. Once the plugin is approved on WordPress.org, the same workflow also deploys it there, and sites get updates through WordPress.org. Enable it by adding:
   - repository secrets `SVN_USERNAME` and `SVN_PASSWORD` (WordPress.org account)
   - repository variable `WPORG_DEPLOY` = `true`

Files listed in `.distignore` are not shipped.

## License

GPL-2.0-or-later
