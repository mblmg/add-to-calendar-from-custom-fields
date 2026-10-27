# Event Toolkit for Custom Fields

WordPress plugin that spreads the events you keep in custom fields (ACF, ACPT or plain post meta):

- an `.ics` file per event, links for Google Calendar, Outlook.com and Outlook / Microsoft 365
- a subscribable calendar feed (`webcal://`) with all upcoming events
- schema.org Event markup (JSON-LD) on each event page for search engines
- a weekly preview (upcoming events + new additions), posted to Telegram and provided as RSS feed for e-mail newsletters
- all calendar links as Breakdance dynamic data and as a shortcode
- abilities for AI agents (WordPress Abilities API, exposed by MCP adapters)

It replaces the earlier plugins "Add to Calendar from Custom Fields" and "Event Digest for Telegram" and takes over their settings.

User documentation lives in [`readme.txt`](readme.txt), which is also what the WordPress.org plugin directory shows.

## Development

```
event-toolkit-for-custom-fields.php        bootstrap, waits while a predecessor plugin is active
includes/class-settings.php                core settings (option imm_tk_settings), saved per tab
includes/class-event.php                   reads an event from post meta, start/end/doors rules
includes/class-ics.php                     iCalendar output (RFC 5545)
includes/class-links.php                   .ics, Google, Outlook and feed URLs
includes/class-endpoints.php               rewrite rules and file delivery
includes/class-schema.php                  schema.org Event (JSON-LD) on event pages
includes/class-digest.php                  weekly preview: issue times, event selection, e-mail HTML
includes/class-digest-feed.php             RSS feed of the weekly preview (one item per issue)
includes/telegram/                         Telegram: settings (imm_tg_settings), Bot API, schedule
includes/class-admin.php                   settings page with tabs
includes/class-abilities.php               abilities (WordPress Abilities API) for AI agents / MCP
includes/shortcode.php                     [imm_calendar_link]
includes/breakdance*.php                   Breakdance dynamic data fields
languages/                                 .pot template and bundled German translation
```

The internal prefixes `imm_tk` / `IMM_TK_` and `imm_tg` / `IMM_TG_` are kept on purpose: existing sites store their settings under `imm_tk_settings` and `imm_tg_settings` and reference the Breakdance fields as `imm_tk_*`.

### Weekly preview

An issue belongs to a scheduled time (weekday and time in the settings). It lists the events starting within the next days and the events published during the week before the issue. Because both parts depend only on the issue time, Telegram and the newsletter feed always show the same content. The feed's item GUID changes per issue, so RSS-to-email campaigns send one mail per issue.

### Translations

English is the source language. After changing strings, update `languages/event-toolkit-for-custom-fields.pot` and the German `.po`, then compile the `.mo` and `.l10n.php` (e.g. with `wp i18n make-mo languages` and `wp i18n make-php languages`).

## Releasing

1. Bump the version in three places: the plugin header (`Version:`), the `IMM_TK_VERSION` constant and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. Publish a GitHub release with the version as new tag (e.g. `2.0.1`).
3. The **Release** workflow checks the version numbers, builds the zip and attaches it to the release.
4. Once the plugin is approved on WordPress.org, the same workflow also deploys it there, and sites get updates through WordPress.org. Enable it by adding:
   - repository secrets `SVN_USERNAME` and `SVN_PASSWORD` (WordPress.org account)
   - repository variable `WPORG_DEPLOY` = `true`

Files listed in `.distignore` are not shipped.

## License

GPL-2.0-or-later
