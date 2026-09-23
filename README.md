# Temple Time

A personal temple worship planning, tracking, and memory application. See
`TempleTime.md` (design doc) for the full product vision.

Built on the **MyDataWorld** platform, the same pattern as Choir Connect,
Reading List, and T-Minus: static HTML/CSS/vanilla-JS front end (GitHub
Pages), a single `api/api.php` (mysqli action router) on seniorfamily.org,
shared `users`/`sessions` login via **My Apps Hub** SSO. Multi-user: anyone
granted access can log in, but every row is scoped by `user_id`, so each
person's Temples/Visits/People/Plans/Photos stay private to them.

## Status: Phases 1-3 live; a Phase 3 fix + addition pending deployment

- [x] Phase 0 -- project foundation, schema, API auth skeleton
- [x] Phase 1 -- Temples, People, Visits CRUD + full front end (Dashboard,
      Quick Log, detail pages). **Deployed and confirmed working.**
- [x] Phase 2 -- Photos: multi-file upload with client-side canvas resize
      (1600x1600 standard / ~450px thumbnail, always re-encoded as JPEG --
      handles HEIC/HEIF from iPhones without any server-side HEIC decoding)
      plus a server-side GD re-resize that enforces the same caps regardless
      of what the client sends. Gallery (`photos.html`) with
      Favorites/Temple/Person/Tag/Year filters, detail page (`photo.html`)
      with caption/date/People/Tags editing and
      Favorite/Set-as-Primary/Set-as-Cover/Delete actions. **Deployed and
      confirmed working.**
- [x] Phase 3 -- Plans: `tt_plans` + purpose/work/people join tables, plus a
      nullable `plan_id` on `tt_visits`. `plans.html` (Upcoming/This Week/
      This Month/All/Completed/Cancelled views), `plan.html` (detail +
      inline edit), `plan-edit.html` (create). Navigate (Google Maps) and
      **Add to Calendar** (downloads a generic `.ics` file via `Blob`, no
      OAuth -- see the Phase 3 decision in `TempleTime.md` 11.3, and the
      "Known Phase 3 simplifications" note below for why it went through
      two other approaches first) on every Plan, plus an **Appointment
      Scheduled** flag
      (separate from Plan Status) so a Plan can be flagged as not yet
      booked with the temple. **Log This Visit** reuses `visit-edit.html`
      (`?fromPlan=<id>`), carrying forward Temple/Planned Date/Who With/
      Group/Purpose/**Planned Work** (unlike Duplicate as New Visit, Work
      *is* carried here since it's a plan for what you intend to do, not a
      copy of what already happened); saving links the new Visit's
      `plan_id` and flips the Plan to Completed. Cancel Plan (status only)
      and Delete Plan (hard delete) both included. Dashboard gained a
      "Coming Up" section (nearest upcoming Plan + quick actions); Temple
      Detail gained "Plan a Visit" + an "Upcoming Plans" list; Visit Detail
      links back to its originating Plan when there is one.
      **Deployed and confirmed working** (Plans/Log This Visit/Maps); the
      Appointment Scheduled flag and the Calendar-link fix above landed
      afterward and still need the `appointment_scheduled` column migration
      + `api.php` re-upload -- see `SETUP.md` section 6/6.1.
- [ ] Phase 4 -- Statistics & streaks
- [ ] Phase 5 -- Search, filters, Nearby Temples, polish
- [ ] Phase 6 -- Share Temple List (and Planned Visits, once Phase 3 exists)
      with another Temple Time user via an in-app share code -- design in
      `TempleTime.md` section 18.1. Needs a `deletePhoto` reference-count
      fix (see that section) before Photos can be safely linked, not
      duplicated, between two users' Temples.

See `SETUP.md` for deployment steps.

## Data model

- `tt_temples` -- one row per temple the user has added
- `tt_people` -- lightweight personal roster ("Who With")
- `tt_visits` -- one row per actual visit, plus join tables for multi-select
  Visit Purpose (`tt_visit_purposes`), Work Performed (`tt_visit_work`),
  Who With (`tt_visit_people`), and Tags (`tt_tags` / `tt_visit_tags`)
- `tt_photos` -- one row per uploaded photo (Phase 2), plus
  `tt_photo_people` / `tt_photo_tags` join tables, and a nullable
  `primary_photo_id` on `tt_temples` / `cover_photo_id` on `tt_visits`
- `tt_plans` -- one row per planned future visit (Phase 3), plus join tables
  for multi-select Planned Purpose (`tt_plan_purposes`), Planned Work
  (`tt_plan_work`), Who With (`tt_plan_people`), and a nullable `plan_id`
  on `tt_visits` (set by "Log This Visit")

See the comments at the top of `api/schema.sql` for the full history of
additive changes.

## Known Phase 1 simplifications

Deliberate scope cuts, not bugs -- revisit in a later phase if they turn out
to matter:

- **People Encountered** is a single free-text field, not linked to real
  People records yet (the spec allows either).
- **Duplicate as New Visit** does not carry forward Work Performed (only
  Temple, Who With, Group, Visit Purpose) -- ordinance work usually differs
  visit to visit even at the same temple.
- Temple **sort by Distance** and the **Nearby** view are not implemented
  (need a Home-location setting -- Phase 5).
- Visit search/filters cover Temple, Purpose, Favorite, year-ish free text,
  and a keyword search across notes/people/tags/group -- not yet a full
  combinable filter panel (Date range, Work Performed, Person, Tag as
  separate controls) -- Phase 5 polish.
- Photos Gallery upload (`photos.html`) requires picking a Temple; it can't
  attach a Photo to a Visit -- upload from that Visit's own detail page for
  that. A standalone Photo (no Temple or Visit at all) isn't supported --
  the spec's data model allows it but nothing in the UI creates one.
- Uploading several Photos at once from a Temple/Visit detail page reloads
  the whole page after each file finishes, so the "Uploading N/M" progress
  text can visibly reset partway through a multi-file batch. All the files
  still upload correctly -- this is a cosmetic rough edge, not data loss.

## Known Phase 3 simplifications

- No recurring Plans (explicitly deferred in the spec itself, section 17).
- Editing a Plan is only available while its status is Planned -- a
  Completed or Cancelled Plan can be viewed or deleted, not edited back.
- "Log This Visit" carries Planned Work forward (unlike Duplicate as New
  Visit); if that turns out to be the wrong call for how ordinance work
  actually gets planned vs. performed, it's a one-line change in
  `visit-edit.html`'s `fromPlan` branch.
- "Add to Calendar" downloads an `.ics` file (via `downloadCalendarEvent()`
  in `js/app.js`), it isn't a live sync -- editing or cancelling a Plan
  after adding it to your calendar does not update or remove that calendar
  event (you'd re-add it, or edit/delete it directly in your calendar
  app). The `.ics`'s UID is stable per Plan, so re-adding after an edit
  updates the same event rather than duplicating it in calendar apps that
  dedupe by UID -- not all of them do. Full Calendar API integration
  (OAuth, live create/update/delete) was deliberately deferred; see the
  decision recorded in `TempleTime.md` 11.3.
- This feature has gone through two dead ends before landing on the
  current approach -- see the comment above `buildIcsText` in `js/app.js`
  for the full history: (1) a Google-specific quick-add web URL, killed by
  the Google Calendar mobile app intercepting it via Universal Links and
  ignoring the prefill params; (2) a `.ics` file as a `data:text/calendar`
  URI on a plain link, killed by recent iOS Safari blocking top-level
  navigation to `data:` URIs outright. Current: a `Blob` + programmatic
  `download`-attributed link click, which isn't subject to either
  restriction -- the tradeoff is it saves the file (to Downloads / Files on
  iOS) rather than jumping straight to an "Add Event" screen, so there's
  one extra tap to open the saved file and import it.
