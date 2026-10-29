# Roadmap

Where the plugin stands and what comes next. Keep this file up to date when a feature ships or a decision is made, so work can continue from here at any time.

## Guiding principle

**Nothing that costs money to run.** No paid APIs, no per-message fees, no paid relay services, and no unofficial workarounds that break a platform's terms. A channel that cannot be automated under these rules gets share helpers instead (ready text and images, posted by a person).

## Status

- Current version: **2.2.0** (on GitHub `main`; not yet submitted to WordPress.org).
- Releases: see [README.md](README.md#releasing). Deployment to WordPress.org runs from the Release workflow once the plugin is approved.

## Shipped

**2.2.0**
- Cancelled and postponed events from a status field: schema.org `eventStatus`, iCalendar `STATUS` and `SEQUENCE`, title prefix in calendars and all texts, story images say "Cancelled" with a grey picture, no reminder stories; one notice on Telegram, Mastodon and Bluesky (action `etcf_status_notice`).
- Mastodon and Bluesky: weekly preview, optional "Today" post, cancellation notice, story images attached; texts fitted to 500 / 300 characters, Bluesky link facets.
- QR code per event page: own encoder (byte mode, versions 1–40, error correction raised where it fits; checked with OpenCV and the `qrcode` reference library), SVG and PNG, share page, link types `qr_svg` / `qr_png`.
- Sharing without a login: share button for event pages (`[event_toolkit_share_button]`; Web Share with text and a story image showing the date, copy on computers) and the share kit for members on a password-protected page (`[event_toolkit_share_page]`, not cached, noindex). Story images on the website come from signed URLs (HMAC of the spec), so only images the site handed out can be rendered. Decided against Shariff: it shares URL and title only, no texts or images, and on phones the system share sheet does more.
- Share page in its own menu (capability `edit_posts`), today first, dashboard box; story settings in their own tab.
- Page "Newsletter": guides for Brevo (RSS, `{{ item.CONTENT_ENCODED }}`), Mailchimp (`*|RSSITEM:CONTENT_FULL|*`), MailerLite (paid plans), other services; direct sending through Brevo (`emailCampaigns` + `sendNow`, `{{ unsubscribe }}`) or Emailit (`campaigns`, recipients, `send`, `{{unsubscribe_url}}`) at issue time, test mail, mail preview.

**2.1.0**
- Own admin menu "Event Toolkit" with an overview: plan of the next 7 days (events and everything that goes out) next to the state of all channels.
- Story images (1080 × 1920 JPEG, GD) for the weekly preview and every event, in the fonts of the website (Breakdance / theme.json; TTF from the theme or Google Fonts; no bundled fonts: fallback is a neutral font already on the server, DM Sans from Twenty Twenty-Two or a system font), heading weight as on the website.
- Share page: texts for WhatsApp (formatted) and Signal (plain), copy buttons, wa.me link, image downloads, Web Share on phones; optional reminder mail at issue time.
- Instagram stories through the official Instagram API (Instagram login): weekly overview, every event the day before and on the day; weekly token refresh, retries, 7-day plan and log.
- Telegram: optional silent "Today" message on event days.
- Newsletter feed: optionally only in weeks in which an event was added.

**2.0.0**
- Merge of "Add to Calendar from Custom Fields" and "Event Digest for Telegram"; settings are taken over.
- Weekly preview with fixed issue times, shared by Telegram and the newsletter feed (RSS, one item per issue).
- Abilities for the WordPress Abilities API (list-events, get-event, get-weekly-preview, post-weekly-preview-to-telegram).

**1.x**
- .ics per event, Google and Outlook links, subscribable calendar feed (webcal), schema.org Event markup, Breakdance dynamic data, shortcode.

## Needs verification

All API clients below are tested against mocked APIs only.
- **Brevo and Emailit direct sending** with real accounts: campaign creation, recipients, sending, test mail; Emailit response shape (`id` vs. `data.id`).
- **Mastodon and Bluesky** with real accounts: media upload, image descriptions, Bluesky facets and aspect ratio.
- **Instagram with a real account.** The API client is tested against a mocked API only. Needs a business account and a Meta app (development mode) with a long-lived token. Check: container creation with `media_type=STORIES`, status polling, publishing, token refresh after 7 days, behaviour when Instagram cannot fetch the image.

## Next

Ideas, nothing decided yet. Same rule: nothing that costs money to run.
- Newsletter signup form that adds contacts to Brevo or Emailit directly (double opt-in handled by the service), as shortcode and Breakdance element.
- Instagram story for cancellations (currently: no promotion stories, notice only on Telegram, Mastodon and Bluesky).
- Abilities for the new features (post to Mastodon/Bluesky, send newsletter test, QR code URL).

## Design decisions

### Reminder rhythm
Reminders should help people come without wearing them out: whoever gets too much mutes the channel or unsubscribes and then also misses the next weekly preview. Repetition works up to about three contacts and then turns into annoyance; varied content wears out more slowly. So the frequency follows how intrusive each channel is:
- **E-mail newsletter** (most intrusive): at most once a week, no reminders. Option to send only when something was added.
- **Telegram** (push notifications): the weekly preview; optionally one silent "Today" message per event day, bundled, and not on the issue day.
- **Instagram stories** (pull, no notifications, gone after 24 h, each story reaches only a fraction of followers): weekly overview, the day before and the day itself, posted at the same time of day so they do not overlap. No day-before story on the issue day. More than three events on one day share one story. "Tomorrow" and "Today" look different.
- **Cancellations** always go out.

### Not planned
- **WhatsApp channels or groups:** no official API for channels; the official Cloud API charges per recipient in groups; unofficial services risk a ban. Covered by the share helpers.
- **Signal:** no official bot API, and its terms forbid automated messages. Covered by the share helpers.
- Paid relay services (Buffer, Later, whapi and the like).
