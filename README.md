# Temple Time

A personal temple worship planning, tracking, and memory application. See
`TempleTime.md` (design doc) for the full product vision.

Built on the **MyDataWorld** platform, the same pattern as Choir Connect,
Reading List, and T-Minus: static HTML/CSS/vanilla-JS front end (GitHub
Pages), a single `api/api.php` (mysqli action router) on seniorfamily.org,
shared `users`/`sessions` login via **My Apps Hub** SSO. Multi-user: anyone
granted access can log in, but every row is scoped by `user_id`, so each
person's Temples/Visits/People/Plans/Photos stay private to them.

## Status: Phases 1-4 live; Phase 6 built, pending deployment (Phase 5 skipped for now)

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
      **Add to Google Calendar** (quick-add link, no OAuth -- see the
      Phase 3 decision in `TempleTime.md` 11.3, and the "Known Phase 3
      simplifications" note below for the debugging story behind it) on
      every Plan, plus an **Appointment Scheduled** flag
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
      **Deployed and confirmed working**, including the Appointment
      Scheduled flag and the Add to Google Calendar fix (see "Known Phase 3
      simplifications" for that debugging story).
- [x] Phase 4 -- Statistics (`stats.html`): Reporting Period selector (This
      Month/This Year/Last Year/All Time/Custom Range, default This Year),
      Summary Cards, Visit Streak + a separate Temple Work Streak (current/
      longest weekly streak, weeks-with-a-visit this year), Visits Over
      Time (Month/Quarter/Year toggle), Most Visited Temples, Visit
      Purpose and Work Performed breakdowns + common Work combinations,
      People (ranked by visits together), and Geographic (by State/Region,
      by Country) -- all clickable through to the relevant Temple/Person
      page. Computed entirely **client-side** from the existing
      `temples`/`visits`/`people` actions -- no new schema or API endpoints,
      since a personal visit log is small enough to crunch in the browser.
      `statBarListHtml()` in `js/app.js` is a small dependency-free bar-list
      renderer used throughout, so this didn't need a charting library.
      Deferred (spec marks these optional/future anyway): Visit Calendar
      view, Activity Heat Map, Year in Review.
- [ ] Phase 5 -- deliberately skipped for now (search/filters/Nearby
      Temples polish). Nothing built depends on it; pick back up anytime.
- [x] Phase 6 (built, not deployed) -- Share Temple List, and optionally
      upcoming Plans, with another Temple Time user via an in-app share
      code (`share.html`) -- final design in `TempleTime.md` 18.1. No
      export file, no text message: `createShareCode`/`importFromShareCode`
      read and merge directly between the two users' rows in the shared
      database. Single-use, expires after 48h or explicit cancel
      (`tt_share_codes`). Dedup by exact Temple Name match, as decided;
      Favorite/On My Visit List always start unset on the copy. A shared
      Temple's Primary Photo is **copied** (new file, new `tt_photos` row)
      rather than linked -- each user ends up with an independent copy, no
      `deletePhoto` reference-counting needed (the original design in
      18.1 called for linking a shared file; simplified after discussing
      it). Plans are opt-in at share time and copy Purposes/Work/Planned
      Date-Time-End Time/Group/Notes; **Who With is dropped** since the
      recipient's People table is separate. Visits are never touched.
      Entry point: "Share My List" button on `temples.html`. See
      `SETUP.md` section 7 to enable.

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
- `tt_share_codes` -- one row per active share code (Phase 6); `code` (the
  primary key) is what the recipient types in, `include_plans` is the
  sharer's opt-in choice, `expires_at` enforces the 48h window

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
- "Add to Google Calendar" is a quick-add link, not a live sync -- editing
  or cancelling a Plan after adding it to your calendar does not update or
  remove that calendar event. Full Calendar API integration (OAuth, live
  create/update/delete) was deliberately deferred; see the decision
  recorded in `TempleTime.md` 11.3.
- This feature went down two dead ends before landing back on the plain
  quick-add link it started with -- see the comment above
  `calendarEventUrl` in `js/app.js` for the full story. Short version: a
  report that the link "opened Google Calendar but created nothing" was
  wrongly diagnosed as the Google Calendar mobile app intercepting the
  link via Universal Links, which led to two rebuilds of the mechanism
  itself (a `data:` URI `.ics` file, then a `Blob`-downloaded `.ics`
  file) -- neither actually fixed it, and the second attempt introduced
  its own new failure (iOS Safari blocking `data:`-URI navigation). Only
  after comparing against `life-tempo`'s `gcalUrl()`, which uses this
  exact same plain link and works fine on the same device, did the real
  bug surface: the default-duration fallback (when a Plan has a Planned
  Time but no End Time) computed the end hour as plain `startHour + 2`
  with no overflow handling, so a late-evening Plan produced an invalid
  hour like "24" or "25" in the `dates` param -- which Google Calendar
  silently ignores rather than erroring, showing today's view with
  nothing filled in. Fixed by doing real `Date`-object arithmetic instead
  (matching Life Tempo's approach), which correctly rolls hour overflow
  into the next day. Lesson: when a working reference implementation
  exists elsewhere, diff against it before inventing a new theory.

## Known Phase 4 simplifications

- No Visit Calendar view, Activity Heat Map, or Year in Review -- the spec
  itself marks these optional/future (sections 12.13-12.15), not deferred
  for effort reasons.
- Visits Over Time always covers all-time history regardless of the
  Reporting Period selector (with Month capped to the last 12 buckets and
  Quarter to the last 8, so the list doesn't grow unbounded) -- it's a
  trend view, so re-scoping it to "This Month" would make it show one bar.
  Every other section respects the selected period.
- Visit Streak and Temple Work Streak are always "as of today," not scoped
  to the Reporting Period either -- a streak is inherently about the
  present, not a historical window.
- "Weeks With a Visit" is pinned to the current calendar year, matching
  the spec's own wording (12.3), independent of the Reporting Period.
- People/Most Visited Temples rank the top 10 by visit count within the
  period; there's no "view all" for the full list.
- Dashboard's existing summary cards (Total Visits, Different Temples,
  etc.) aren't yet linked through to Statistics -- spec section 6.4 says
  "clickable where practical," not done for Phase 4.

## Known Phase 6 simplifications

- No subset picker -- sharing always offers **all** of your Temples, not
  just Favorites/On My Visit List or a hand-picked selection.
- No preview before accepting an import -- it applies immediately and
  shows a receipt (Added/skipped counts) afterward, not a "review these
  changes first" step.
- Only a Temple's **Primary Photo** transfers, not its full gallery, and
  never Visit photos -- those are personal memories, not part of the
  Temple record being shared.
- Plan sharing always drops **Who With** -- the recipient's People table
  is a separate personal roster, and guessing at name matches seemed more
  surprising than leaving it blank for the recipient to fill in.
- One active share code per user at a time -- generating a new one
  replaces (and immediately invalidates) any earlier one, there's no way
  to have two different codes live simultaneously (e.g. one with Plans,
  one without).
- No admin/history view of past share codes or who redeemed what -- a
  code is deleted the moment it's used or expires, nothing is retained.
