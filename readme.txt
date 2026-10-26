=== Add to Calendar from Custom Fields ===
Contributors: mblmg
Tags: calendar, ics, events, add to calendar, schema
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add-to-calendar links, .ics files, a calendar feed and event markup for search engines, built from the date fields you already have.

== Description ==

Your events already have a date, a start time and a location in custom fields. This plugin turns exactly those fields into calendar entries, so nobody has to enter the data a second time.

* An .ics file per event for Apple Calendar, Outlook, Thunderbird and most phone calendars
* Links that open Google Calendar, Outlook.com or Outlook / Microsoft 365 with the event filled in
* A subscribable calendar feed with all upcoming events. Calendar apps re-fetch it regularly, so new and changed events show up for subscribers automatically.
* Structured data (schema.org Event as JSON-LD) on each event page, so search engines like Google can list the event with date, time and place
* All links as dynamic data in Breakdance (category "Add to Calendar") and as a shortcode for any other theme or builder

The plugin reads regular post meta, so it works with Advanced Custom Fields (ACF), ACPT, Meta Box text fields and plain custom fields. Dates are recognised in the common storage formats: 2026-12-09 (ACPT), 20261209 (ACF), 09.12.2026, 12/09/2026 and Unix timestamps.

= How start and end are calculated =

* Start time: the first field with a value from the list of start time fields. Example: "doors, start" uses the doors time and falls back to the start time.
* No start time at all: the event is added as an all-day event.
* End time: if it is earlier than the start time, the event ends on the next day.
* No end time: start plus the default duration (3 hours unless you change it).

= Addresses =

* Single event: /calendar/event-{ID}.ics
* Subscription feed: /calendar/events.ics (linked as webcal://)

Base path and feed name can be changed in the settings.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Add to Calendar.
3. Enter the post type of your events and the names of your date, time and location fields. The settings page lists the field names of a sample event and shows a preview of the next events, so you can check the result right away.
4. Add the links to your event template:
   * Breakdance: choose the dynamic data category "Add to Calendar" in the link field of a button or text link.
   * Any other theme or builder: use the shortcode, e.g. `[imm_calendar_link type="ics" text="Add to calendar"]`.

== Frequently Asked Questions ==

= Which link types are available? =

`ics`, `google`, `outlook`, `office365` for a single event, and `feed`, `webcal`, `feed_google` for the subscription. Without the `text` attribute the shortcode returns only the URL.

= Does a downloaded .ics file update itself? =

No. A downloaded file is a one-time copy. For automatic updates, offer the subscription link (webcal). Subscribed calendars re-fetch the feed regularly; Google Calendar can take up to a day.

= Does it work with page caching? =

Yes. The calendar files tell caching plugins not to cache them.

= What does the structured data contain? =

Name, start and end, doors time, description (description field, excerpt or the beginning of the post content), featured image, venue and address, organizer, and optionally the ticket link and price. The first location field is read as venue name, the other location fields as address. An address like "Main Street 1, 12345 City" is split into street, postal code and city. The settings page shows the generated data for the next event and links to Google's Rich Results Test.

If another plugin already outputs event markup for the same posts, turn the option off in the settings to avoid duplicates.

= Developer hooks =

* Filter `imm_tk_event` ($event, $post): change the event data before output.
* Filter `imm_tk_feed_query_args` ($args): change the query for the subscription feed.
* Filter `imm_tk_schema` ($data, $event, $post): change the structured data, e.g. add performers. Return an empty array to output nothing.

== External services ==

This plugin itself does not contact any external service, does not load external scripts and does not track visitors. The .ics files and the subscription feed are generated on your own server.

The optional calendar links send the visitor to a third-party calendar service. This only happens when a visitor clicks such a link. The link contains the event title, date and time, location, description and the URL of the event page.

* Google Calendar ("Google Calendar" link and "subscribe in Google Calendar" link): provided by Google LLC. [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy)
* Outlook.com and Outlook / Microsoft 365 ("Outlook" links): provided by Microsoft Corporation. [Services Agreement](https://www.microsoft.com/servicesagreement), [Privacy Statement](https://privacy.microsoft.com/privacystatement)

If you do not want to offer these services, simply do not place these links on your site.

The settings page contains a link to Google's Rich Results Test. Clicking it opens that Google tool with the address of one of your event pages. Nothing is sent unless an administrator clicks the link.

== Changelog ==

= 1.1.0 =
* New: structured data (schema.org Event) for search engines on each event page.
* New: optional fields for doors time, ticket link and price, a default venue and the organizer.
* Settings page grouped into sections, with a preview of the structured data.
* Without a description field or excerpt, the description now uses the beginning of the post content (also in calendars).

= 1.0.0 =
* First public release.
