=== Event Toolkit for Custom Fields ===
Contributors: mblmg
Tags: events, calendar, telegram, instagram, newsletter
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spread the events you keep in custom fields: calendar links and feeds, search engine markup, Telegram, Instagram stories and newsletters.

== Description ==

Your events already have a date, a start time and a location in custom fields. This plugin takes exactly those fields and gets the events out to where people are, without entering anything twice.

**Calendars**

* An .ics file per event for Apple Calendar, Outlook, Thunderbird and most phone calendars
* Links that open Google Calendar, Outlook.com or Outlook / Microsoft 365 with the event filled in
* A subscribable calendar feed with all upcoming events. Calendar apps re-fetch it regularly, so new and changed events show up for subscribers automatically.

**Search engines**

* Structured data (schema.org Event as JSON-LD) on each event page, so search engines like Google can list the event with date, time and place

**Weekly preview**

Once a week, a preview of what is coming up: the events of the next days, plus the events added to the programme during the past week. In weeks without either, nothing goes out. The same preview is available on two channels:

* **Telegram:** a bot posts it to a group, a topic in a group or a channel, with the link preview and image of the next event
* **E-mail newsletter:** an RSS feed with one entry per week, ready for the RSS campaigns of newsletter services such as Brevo, CleverReach or Mailchimp. Optionally only in weeks in which an event was added.
* **Telegram, on event days (optional):** one short, silent "Today" message with the events of the day

**Instagram stories**

* Story images (1080 × 1920) in the look of your website: its fonts, your colours, the event picture as a polaroid
* Posted automatically through the official Instagram API, free of charge: the weekly overview, and every event the day before and on the day
* Built not to overwhelm followers: no extra story for tomorrow on the day of the weekly overview, and many events on one day share one story

**Share by hand**

* WhatsApp channels and Signal groups cannot be automated without costs or breaking their rules. The tab "Share" has everything ready: the text with WhatsApp formatting, a plain version for Signal, the story images, and a "Share" button that hands both to the apps on your phone.
* Optional reminder mail at the time of the weekly preview.

**Page builders**

* All calendar links as dynamic data in Breakdance (category "Add to Calendar") and as a shortcode for any other theme or builder

**AI agents**

* Abilities for the WordPress Abilities API: list upcoming events, get the details of an event, get the weekly preview, and post the weekly preview to Telegram. MCP adapters offer them to AI agents as tools, so agents get the events ready-made instead of reading raw custom fields.

The plugin reads regular post meta, so it works with Advanced Custom Fields (ACF), ACPT, Meta Box text fields and plain custom fields. Dates are recognised in the common storage formats: 2026-12-09 (ACPT), 20261209 (ACF), 09.12.2026, 12/09/2026 and Unix timestamps.

= How start and end are calculated =

* Start time: the first field with a value from the list of start time fields. Example: "doors, start" uses the doors time and falls back to the start time.
* If one of them is set as doors time field, search engines and the weekly preview show the doors time and the start of the programme separately.
* No start time at all: the event is added as an all-day event.
* End time: if it is earlier than the start time, the event ends on the next day.
* No end time: start plus the default duration (3 hours unless you change it).

= Addresses =

* Single event: /calendar/event-{ID}.ics
* Subscription feed: /calendar/events.ics (linked as webcal://)
* Weekly preview for newsletters: /calendar/weekly.xml

Base path and file names can be changed in the settings.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Event Toolkit.
3. In the tab "Events", enter the post type of your events and the names of your date, time and location fields. The tab lists the field names of a sample event and shows a preview of the next events, so you can check the result right away.
4. Add the calendar links to your event template:
   * Breakdance: choose the dynamic data category "Add to Calendar" in the link field of a button or text link.
   * Any other theme or builder: use the shortcode, e.g. `[imm_calendar_link type="ics" text="Add to calendar"]`.
5. Optional: set day and time of the weekly preview, connect a Telegram bot, enter the newsletter feed address in your newsletter service, and connect Instagram.

== Frequently Asked Questions ==

= Which link types are available? =

`ics`, `google`, `outlook`, `office365` for a single event, and `feed`, `webcal`, `feed_google` for the subscription. Without the `text` attribute the shortcode returns only the URL.

= Does a downloaded .ics file update itself? =

No. A downloaded file is a one-time copy. For automatic updates, offer the subscription link (webcal). Subscribed calendars re-fetch the feed regularly; Google Calendar can take up to a day.

= Does it work with page caching? =

Yes. The calendar files and feeds tell caching plugins not to cache them.

= What does the structured data contain? =

Name, start and end, doors time, description (description field, excerpt or the beginning of the post content), featured image, venue and address, organizer, and optionally the ticket link and price. The first location field is read as venue name, the other location fields as address. An address like "Main Street 1, 12345 City" is split into street, postal code and city. The settings page shows the generated data for the next event and links to Google's Rich Results Test.

If another plugin already outputs event markup for the same posts, turn the option off in the settings to avoid duplicates.

= How do I connect Telegram? =

1. In Telegram, create a bot with @BotFather (`/newbot`) and paste the token in the tab "Telegram".
2. Add the bot to your group (or as administrator to your channel). In a group with topics, send `/start@YourBotName` in the topic the preview should go to. The bot does not answer; it only reads the chat ID.
3. Click "Find chats", copy the chat ID (and topic ID) into the settings and save.
4. Check the preview, post it once to test, then turn on "Post automatically every week".

= How do I connect Instagram? =

1. Switch your Instagram account to a business account (free, in the Instagram app). Instagram only allows stories through the API for business accounts.
2. On developers.facebook.com, create an app of the type "Business" and add the product "Instagram" with "API setup with Instagram login".
3. Under "Generate access tokens", add your account and generate a token. The app can stay in development mode, because it only posts to your own account.
4. Paste the token in the tab "Instagram", check the plan for the next days and turn on automatic stories.

The plugin renews the token every week. Instagram downloads the images from your site, so the uploads folder must be publicly reachable.

= Can the stories contain a link? =

No. Instagram does not allow link stickers in stories posted through the API. The images show your web address instead (bottom line in the tab "Share").

= Which fonts do the story images use? =

The main fonts of your website: the plugin reads them from Breakdance or from the theme (theme.json). Image rendering needs TrueType or OpenType files, while websites usually load WOFF2. If the theme does not ship a .ttf or .otf, the plugin downloads the font once from Google Fonts. Fonts that are not available there fall back to the bundled fonts Syne and Atkinson Hyperlegible Mono. You can also enter other fonts in the tab "Share".

= Why can't the plugin post to WhatsApp or Signal? =

WhatsApp has no interface for channels, and its business API charges per message and recipient in groups. Signal has no bot interface, and its terms forbid automated messages. Services that work around this break those rules and risk your number being blocked. The tab "Share" makes posting by hand quick instead.

= How do I use the newsletter feed? =

Create an RSS campaign in your newsletter service and enter the feed address shown in the tab "Weekly preview". The feed has one entry per week with the whole preview as HTML, so the service sends one mail per week. In weeks without upcoming or new events the feed stays empty, and no mail is sent.

= The Telegram post came late. =

WordPress runs scheduled tasks when someone visits the site. On sites with few visitors, set up a real cron job at your host that calls `wp-cron.php` every 15 minutes.

= I used "Add to Calendar from Custom Fields" or "Event Digest for Telegram". =

This plugin replaces both. Install it, then deactivate the old plugins: all settings, the calendar addresses and the Breakdance fields are taken over. While an old plugin is still active, this plugin waits and shows a notice.

= Which abilities are there for AI agents? =

* `event-toolkit/list-events`: upcoming events in a period, with date, time (doors and start), venue, image, ticket link and calendar links
* `event-toolkit/get-event`: one event by post ID or URL
* `event-toolkit/get-weekly-preview`: the weekly preview as data, as Telegram text and as e-mail HTML
* `event-toolkit/post-weekly-preview-to-telegram`: posts the weekly preview to the configured chat

The first three only return what visitors can see on the website anyway. Posting to Telegram is limited to administrators. The abilities appear for AI agents once an MCP adapter is installed (for example the MCP Adapter plugin).

= Developer hooks =

* Filter `imm_tk_event` ($event, $post): change the event data before output.
* Filter `imm_tk_feed_query_args` ($args): change the query for the subscription feed and the weekly preview.
* Filter `imm_tk_schema` ($data, $event, $post): change the structured data, e.g. add performers. Return an empty array to output nothing.
* Filter `imm_tk_digest_events` ($selection, $issue): change the events of a weekly preview (`week` and `new`).
* Filter `imm_tk_digest_email_html` ($html, $selection): change the newsletter HTML.
* Filter `imm_tg_message` ($message, $selection): change the Telegram message (Telegram HTML).
* Filter `imm_tg_today_message` ($message, $events): change the silent "Today" message.
* Filter `imm_tk_share_text` ($text): change the texts in the tab "Share".
* Filter `imm_tk_sharepic_fonts` ($files): font files of the story images (`display`, `text`, `bold`).
* Filter `imm_tk_site_fonts` ($fonts): the detected fonts of the website (`heading`, `text`).
* Filter `imm_ig_plan` ($items, $now): change the stories planned for a day.

== External services ==

The plugin does not load external scripts and does not track visitors. The .ics files, the subscription feed, the newsletter feed and the story images are generated on your own server. External services are only involved as described below.

**Calendar links.** The optional calendar links send the visitor to a third-party calendar service. This only happens when a visitor clicks such a link. The link contains the event title, date and time, location, description and the URL of the event page.

* Google Calendar ("Google Calendar" link and "subscribe in Google Calendar" link): provided by Google LLC. [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy)
* Outlook.com and Outlook / Microsoft 365 ("Outlook" links): provided by Microsoft Corporation. [Services Agreement](https://www.microsoft.com/servicesagreement), [Privacy Statement](https://privacy.microsoft.com/privacystatement)

If you do not want to offer these services, simply do not place these links on your site.

**Telegram (optional).** Only when you enter a bot token, the plugin connects to the Telegram Bot API (api.telegram.org), provided by Telegram:

* When the weekly preview is due, or when an administrator clicks "Post this preview to the chat now", the plugin sends the message (event titles, dates, times, venues and links to your site) together with the bot token and the chat ID to Telegram. Telegram then loads the page of the next event to build the link preview.
* When an administrator clicks "Find chats", the plugin asks Telegram for the bot's recent updates (chats and topics the bot was added to or received commands in).

No data about visitors of your site is sent. [Terms of Service](https://telegram.org/tos), [Bot Developer Terms](https://telegram.org/tos/bot-developers), [Privacy Policy](https://telegram.org/privacy)

**Instagram (optional).** Only when you save an Instagram access token, the plugin connects to the Instagram API (graph.instagram.com), provided by Meta Platforms:

* When you save the token or click "Check connection", it asks for the account name and type.
* At the set time of day, or when an administrator clicks "Post today's stories now", it sends the address of a story image on your site; Instagram then downloads the image (event title, date, time, venue and picture) and publishes it as story.
* Once a week it renews the access token.

No data about visitors of your site is sent. [Terms of Use](https://help.instagram.com/581066165581870), [Platform Terms](https://developers.facebook.com/terms/), [Privacy Policy](https://privacycenter.instagram.com/policy)

**Google Fonts (optional).** Only when the story images use the fonts of your website and the theme does not ship them as .ttf or .otf file, the plugin downloads these font files once from Google Fonts (fonts.googleapis.com, fonts.gstatic.com), provided by Google LLC, and keeps them in the uploads folder. Only the font name and weight are sent. Choose "Bundled fonts" in the tab "Share" to avoid this. [Privacy Policy](https://policies.google.com/privacy), [Google Fonts FAQ](https://developers.google.com/fonts/faq/privacy)

**WhatsApp (optional).** The button "Open in WhatsApp" in the tab "Share" opens wa.me with the text filled in. This only happens when an administrator clicks it. [Privacy Policy](https://www.whatsapp.com/legal/privacy-policy)

**Google Rich Results Test.** The settings page contains a link to this Google tool. Clicking it opens the tool with the address of one of your event pages. Nothing is sent unless an administrator clicks the link.

== Credits ==

The bundled fonts Syne (by Bonjour Monde) and Atkinson Hyperlegible Mono (by Braille Institute) are licensed under the SIL Open Font License 1.1, see assets/fonts.

== Changelog ==

= 2.1.0 =
* New: story images (1080 × 1920) for the weekly preview and every event, in the fonts (including the heading weight) and colours of your website.
* New: Instagram stories through the official API: the weekly overview, and every event the day before and on the day.
* New: tab "Share" with ready texts and images for WhatsApp, Signal and Instagram, and an optional reminder mail.
* New: optional silent "Today" message in Telegram on event days.
* New: newsletter feed optionally only in weeks in which an event was added.

= 2.0.0 =
* New name: Event Toolkit for Custom Fields. Replaces "Add to Calendar from Custom Fields" and "Event Digest for Telegram"; settings are taken over.
* New: weekly preview of upcoming and new events, shared by all channels.
* New: weekly preview as RSS feed for e-mail newsletters.
* New: weekly preview as Telegram post, with link preview and image of the next event.
* New: abilities for the WordPress Abilities API, so AI agents can read the events and the weekly preview.
* Settings page with tabs.
* Requires WordPress 6.9 or later.

= 1.1.0 =
* New: structured data (schema.org Event) for search engines on each event page.
* New: optional fields for doors time, ticket link and price, a default venue and the organizer.
* Without a description field or excerpt, the description uses the beginning of the post content (also in calendars).

= 1.0.0 =
* First public release.
