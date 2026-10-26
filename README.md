# Add to Calendar from Custom Fields

WordPress plugin that turns the date and time fields you already have (ACF, ACPT or plain post meta) into calendar entries:

- an `.ics` file per event (Apple Calendar, Outlook, phone calendars)
- links for Google Calendar, Outlook.com and Outlook / Microsoft 365
- a subscribable calendar feed (`webcal://`) with all upcoming events
- schema.org Event markup (JSON-LD) on each event page for search engines
- all links as Breakdance dynamic data and as a shortcode

User documentation lives in [`readme.txt`](readme.txt), which is also what the WordPress.org plugin directory shows.

## Development

```
add-to-calendar-from-custom-fields.php   plugin bootstrap
includes/class-settings.php              settings (option imm_tk_settings)
includes/class-event.php                 reads an event from post meta, start/end rules
includes/class-ics.php                   iCalendar output (RFC 5545)
includes/class-links.php                 .ics, Google, Outlook and feed URLs
includes/class-endpoints.php             rewrite rules and file delivery
includes/class-schema.php                schema.org Event (JSON-LD) on event pages
includes/class-admin.php                 settings page
includes/shortcode.php                   [imm_calendar_link]
includes/breakdance*.php                 Breakdance dynamic data fields
languages/                               .pot template and bundled German translation
```

The internal prefix `imm_tk` / `IMM_TK_` is kept on purpose: existing sites store their settings under `imm_tk_settings` and reference the Breakdance fields as `imm_tk_*`.

### Translations

English is the source language. After changing strings, update `languages/add-to-calendar-from-custom-fields.pot` and the German `.po`, then compile the `.mo` (e.g. with `wp i18n make-mo languages` and `wp i18n make-php languages`).

## Releasing

1. Bump the version in three places: the plugin header (`Version:`), the `IMM_TK_VERSION` constant and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. Commit, then tag and push: `git tag 1.0.1 && git push --tags`
3. The **Release** workflow checks the version numbers, builds the zip and attaches it to a GitHub release.
4. Once the plugin is approved on WordPress.org, the same workflow also deploys to the plugin directory. Enable it by adding:
   - repository secrets `SVN_USERNAME` and `SVN_PASSWORD` (WordPress.org account)
   - repository variable `WPORG_DEPLOY` = `true`

Files listed in `.distignore` are not shipped.

## License

GPL-2.0-or-later
