# Roadmap

Where the plugin stands and what comes next. Keep this file up to date when a feature ships or a decision is made, so work can continue from here at any time.

## Guiding principle

**Nothing that costs money to run.** No paid APIs, no per-message fees, no paid relay services, and no unofficial workarounds that break a platform's terms. A channel that cannot be automated under these rules gets share helpers instead (ready text and images, posted by a person).

## Status

- Current version: **2.1.0** (on GitHub `main`; not yet submitted to WordPress.org).
- Releases: see [README.md](README.md#releasing). Deployment to WordPress.org runs from the Release workflow once the plugin is approved.

## Shipped

**2.1.0**
- Own admin menu "Event Toolkit" with an overview: plan of the next 7 days (events and everything that goes out) next to the state of all channels.
- Story images (1080 × 1920 JPEG, GD) for the weekly preview and every event, in the fonts of the website (Breakdance / theme.json; TTF from the theme or Google Fonts; bundled Syne and Atkinson Hyperlegible Mono as fallback), heading weight as on the website.
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

- **Instagram with a real account.** The API client is tested against a mocked API only. Needs a business account and a Meta app (development mode) with a long-lived token. Check: container creation with `media_type=STORIES`, status polling, publishing, token refresh after 7 days, behaviour when Instagram cannot fetch the image.

## Next

### 1. Cancelled and postponed events (small)
One status field per event (setting: which meta field, and which values mean "cancelled" / "postponed").
- schema.org: `eventStatus` `EventCancelled` / `EventPostponed` / `EventRescheduled`.
- iCalendar: `STATUS:CANCELLED`, so subscribed calendars show the cancellation; raise `SEQUENCE`.
- Weekly preview, share texts and story images: mark the event as cancelled instead of advertising it.
- Telegram: a short notice when an event is cancelled (sent once). This is information, not promotion, so it is exempt from the reminder rhythm.

### 2. Mastodon and Bluesky (medium)
Both have free, official APIs.
- Mastodon: `POST /api/v1/statuses` with an access token of the account; media via `POST /api/v2/media`.
- Bluesky: AT Protocol, `com.atproto.server.createSession` with an app password, `com.atproto.repo.createRecord` (`app.bsky.feed.post`); link cards and images need `uploadBlob`.
- Same content as Telegram: the weekly preview at issue time, optionally a "Today" post. Reuse the share texts and story images.

### 3. QR code per event (small)
For posters and flyers, pointing to the event page. Generated on the own server (bundle a small GPL-compatible QR library or write a minimal encoder), offered as PNG/SVG download on the share page and as Breakdance dynamic data.

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
