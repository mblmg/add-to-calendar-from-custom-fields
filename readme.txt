=== Event Toolkit for Custom Fields ===
Contributors: mblmg
Tags: events, calendar, ics, telegram, newsletter
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spread the events you keep in custom fields: calendar links and feeds, search engine markup, a weekly Telegram post and an RSS feed for newsletters.

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
* **E-mail newsletter:** an RSS feed with one entry per week, ready for the RSS campaigns of newsletter services such as Brevo, CleverReach or Mailchimp

**Page builders**

* All calendar links as dynamic data in Breakdance (category "Add to Calendar") and as a shortcode for any other theme or builder

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
5. Optional: set day and time of the weekly preview, connect a Telegram bot, and enter the newsletter feed address in your newsletter service.

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

= How do I use the newsletter feed? =

Create an RSS campaign in your newsletter service and enter the feed address shown in the tab "Weekly preview". The feed has one entry per week with the whole preview as HTML, so the service sends one mail per week. In weeks without upcoming or new events the feed stays empty, and no mail is sent.

= The Telegram post came late. =

WordPress runs scheduled tasks when someone visits the site. On sites with few visitors, set up a real cron job at your host that calls `wp-cron.php` every 15 minutes.

= I used "Add to Calendar from Custom Fields" or "Event Digest for Telegram". =

This plugin replaces both. Install it, then deactivate the old plugins: all settings, the calendar addresses and the Breakdance fields are taken over. While an old plugin is still active, this plugin waits and shows a notice.

= Developer hooks =

* Filter `imm_tk_event` ($event, $post): change the event data before output.
* Filter `imm_tk_feed_query_args` ($args): change the query for the subscription feed and the weekly preview.
* Filter `imm_tk_schema` ($data, $event, $post): change the structured data, e.g. add performers. Return an empty array to output nothing.
* Filter `imm_tk_digest_events` ($selection, $issue): change the events of a weekly preview (`week` and `new`).
* Filter `imm_tk_digest_email_html` ($html, $selection): change the newsletter HTML.
* Filter `imm_tg_message` ($message, $selection): change the Telegram message (Telegram HTML).

== External services ==

The plugin does not load external scripts and does not track visitors. The .ics files, the subscription feed and the newsletter feed are generated on your own server. External services are only involved as described below.

**Calendar links.** The optional calendar links send the visitor to a third-party calendar service. This only happens when a visitor clicks such a link. The link contains the event title, date and time, location, description and the URL of the event page.

* Google Calendar ("Google Calendar" link and "subscribe in Google Calendar" link): provided by Google LLC. [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy)
* Outlook.com and Outlook / Microsoft 365 ("Outlook" links): provided by Microsoft Corporation. [Services Agreement](https://www.microsoft.com/servicesagreement), [Privacy Statement](https://privacy.microsoft.com/privacystatement)

If you do not want to offer these services, simply do not place these links on your site.

**Telegram (optional).** Only when you enter a bot token, the plugin connects to the Telegram Bot API (api.telegram.org), provided by Telegram:

* When the weekly preview is due, or when an administrator clicks "Post this preview to the chat now", the plugin sends the message (event titles, dates, times, venues and links to your site) together with the bot token and the chat ID to Telegram. Telegram then loads the page of the next event to build the link preview.
* When an administrator clicks "Find chats", the plugin asks Telegram for the bot's recent updates (chats and topics the bot was added to or received commands in).

No data about visitors of your site is sent. [Terms of Service](https://telegram.org/tos), [Bot Developer Terms](https://telegram.org/tos/bot-developers), [Privacy Policy](https://telegram.org/privacy)

**Google Rich Results Test.** The settings page contains a link to this Google tool. Clicking it opens the tool with the address of one of your event pages. Nothing is sent unless an administrator clicks the link.

== Changelog ==

= 2.0.0 =
* New name: Event Toolkit for Custom Fields. Replaces "Add to Calendar from Custom Fields" and "Event Digest for Telegram"; settings are taken over.
* New: weekly preview of upcoming and new events, shared by all channels.
* New: weekly preview as RSS feed for e-mail newsletters.
* New: weekly preview as Telegram post, with link preview and image of the next event.
* Settings page with tabs.

= 1.1.0 =
* New: structured data (schema.org Event) for search engines on each event page.
* New: optional fields for doors time, ticket link and price, a default venue and the organizer.
* Without a description field or excerpt, the description uses the beginning of the post content (also in calendars).

= 1.0.0 =
* First public release.
