# The class bell, away from the tab — mirror the timetable into Google Calendar

The bell on the index page rings only while that tab is open. For a reminder
that reaches Paige with the browser closed, the timetable is mirrored into a
Google calendar and Google does the alerting. Nothing runs on the server: a
prompt, pasted into a claude.ai chat that has both the **Education Tracker**
and **Google Calendar** connectors enabled, writes the events. Re-run it
whenever the timetable is re-cut.

Why a calendar and not push notifications: the site is on a shared host with
no way to fire a push at 09:45 sharp, and Chrome's own scheduled-notification
API was abandoned. Why real events and not a subscribed `.ics` feed: Google
refreshes subscribed feeds only every 12 to 24 hours and does not reliably
apply notification defaults to them. Events written through the connector
carry their own reminders and sync everywhere within seconds.

## Paige's side — once

Google Calendar's own web notifications only fire while calendar.google.com
is open in a browser tab, which is the same weakness as the bell. The alert
has to come from something native that syncs the calendar:

- **Laptop (Windows).** Settings → Accounts → Email & accounts → Add account
  → Google, with Calendar sync on. The built-in Calendar / Outlook app then
  raises a Windows notification with sound at each reminder, browser open or
  closed. Check once that Focus Assist / Do Not Disturb is not silencing
  notifications during study hours, and that the sound is on in Settings →
  System → Notifications for the Calendar app.
- **Phone, if she has one.** Google Calendar app, signed in to the account
  the calendar is shared with, "Study timetable" calendar visible,
  notifications allowed for the app.
- **Test it.** Open the "Study timetable" calendar, find the next block,
  and wait for it with the browser closed. One notification with sound is
  the whole test.

She keeps the switch, the same as the bell: hiding the "Study timetable"
calendar in the app, or muting its notifications, turns it off. Nothing
about the calendar reports back to the tracker or to anyone.

## Setup — once, by Dad

1. In Google Calendar, create a calendar named exactly **Study timetable**.
   If it lives in Dad's account, share it with Paige's Google account
   ("See all event details" is enough). Alternatively create it in Paige's
   account and share it with Dad's with "Make changes to events", so the
   prompt can write to it.
2. In that calendar's settings, set **Event notifications** to
   *Notification, 0 minutes before*. This is the fallback if the connector
   cannot set reminders per event; when it can, each event carries its own.
3. Open a claude.ai chat with the Education Tracker and Google Calendar
   connectors both enabled, and paste the prompt below.

Optionally save the prompt as a Routine running Sunday evening. With
recurring events that is only insurance; without them (see the fallback in
the prompt) it is what keeps the next two weeks populated.

## The prompt — paste this whole into the chat

Mirror Paige's study timetable from the Education Tracker into the Google
calendar named "Study timetable", so that Google raises a reminder at the
start of every block. Work in Europe/London. Write only to that calendar.
Never write to the tracker.

1. Read the timetable in force with `tracker_get_timetable()` (no arguments:
   today). Note its version number, its `valid_from` date, and every block:
   weekday, start, end, label, kind, block_key. Then read approved days off
   for the next fourteen days with `tracker_days_off(from: today, to: today
   + 14 days, status: "approved")`.

2. Find the calendar named exactly "Study timetable". If there is no such
   calendar, stop and say so; do not create events on any other calendar.

3. Clear what this prompt wrote before. List the events on that calendar
   whose description contains the tag `tracker:` and delete them — every
   one of them, including recurring series. Never delete an event without
   that tag: anything else on the calendar is somebody's and not yours.

4. Create the events. One per block whose kind is not `break`, Monday to
   Friday, in the Europe/London timezone:
   - Title: the block's label, prefixed with its start time, e.g.
     `09:45 Maths — new topic`.
   - Start and end: the block's start and end on the right weekday.
   - Recurrence: weekly, starting from the first occurrence on or after the
     timetable's `valid_from` date, with no end date. If the calendar tool
     cannot create a recurring event, instead create the individual
     occurrences for the next two weeks (from today's Monday) and say so at
     the end, so this prompt gets scheduled to run every Sunday evening.
   - Reminders: one popup reminder at 0 minutes before the start, and no
     email reminder. Google reminders are relative to the start only, so
     there is no end-of-block reminder: the next block's start marks the end
     of this one, and breaks are the gaps between.
   - Description: exactly `tracker:v<version>#<block_key>` on the first
     line, e.g. `tracker:v1#3`, followed by the block's note if it has one.
     Nothing else: no names, no commentary, nothing about how the work is
     going. The calendar may be shared.
   - Visibility and colour: leave the calendar's defaults.

5. Apply the days off. For each approved day off in the next fourteen days,
   delete that date's occurrences of the events you just created (for a
   recurring series, delete only that instance). A day off further out than
   fourteen days is handled by a later run.

6. Report in one short message: the timetable version mirrored, how many
   events were created, whether they are recurring or a two-week batch, how
   many day-off occurrences were removed, and anything you could not do.
   Do not restate the timetable.

Do not: create, change or delete anything outside the "Study timetable"
calendar; delete an untagged event; log a session, tick a block, request or
decide a day off, or change anything in the tracker; add invitees to
events; put anything about Paige beyond the block label and note into an
event.

If the Education Tracker connector is unavailable, say so plainly and stop;
do not reconstruct the timetable from memory. If the Google Calendar
connector is unavailable, say so and stop.

## When to re-run it

- After any timetable re-cut (`tracker_set_timetable`). The prompt replaces
  its own events, so a re-run never duplicates.
- After a day off is approved, if it is within the next fortnight and the
  reminders that day would be a nuisance. Optional: she can also just ignore
  them.
- Weekly on a Sunday, only if the connector could not create recurring
  events (the prompt says so in its report).

## Checking it worked

- Google Calendar shows the non-break blocks on "Study timetable", Monday to
  Friday, none at the weekend, each with a 0-minute notification.
- Nothing outside that calendar changed.
- A second run leaves the event count unchanged.
- With the browser closed on the laptop, one block boundary produces a
  Windows notification with sound.
