# Temple Time

A personal temple worship planning, tracking, and memory application. See
`TempleTime.md` (design doc) for the full product vision.

Built on the **MyDataWorld** platform, the same pattern as Choir Connect,
Reading List, and T-Minus: static HTML/CSS/vanilla-JS front end (GitHub
Pages), a single `api/api.php` (mysqli action router) on seniorfamily.org,
shared `users`/`sessions` login via **My Apps Hub** SSO. Multi-user: anyone
granted access can log in, but every row is scoped by `user_id`, so each
person's Temples/Visits/People/Plans/Photos stay private to them.

## Status: Phase 1 complete (pending deployment)

- [x] Phase 0 -- project foundation, schema, API auth skeleton
- [x] Phase 1 (backend) -- Temples, People, Visits CRUD in `api/api.php` +
      `api/schema.sql`
- [x] Phase 1 (front end) -- Dashboard (`index.html`), Temples
      (`temples.html`/`temple.html`), Visits (`visits.html`/`visit.html`/
      `visit-edit.html` for Quick Log + Add More Details + Duplicate),
      People (`people.html`/`person.html`). Not yet deployed -- see
      `SETUP.md`.
- [ ] Phase 2 -- Photos (upload, server-side optimize/thumbnail, gallery)
- [ ] Phase 3 -- Plans, Google Maps navigation, Google Calendar ("Add to
      Calendar" link)
- [ ] Phase 4 -- Statistics & streaks
- [ ] Phase 5 -- Search, filters, Nearby Temples, polish

See `SETUP.md` for deployment steps.

## Data model (Phase 1)

- `tt_temples` -- one row per temple the user has added
- `tt_people` -- lightweight personal roster ("Who With")
- `tt_visits` -- one row per actual visit, plus join tables for multi-select
  Visit Purpose (`tt_visit_purposes`), Work Performed (`tt_visit_work`),
  Who With (`tt_visit_people`), and Tags (`tt_tags` / `tt_visit_tags`)

Photos (Phase 2) and Plans (Phase 3) will extend this schema additively --
see the comments at the top of `api/schema.sql`.

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
- No Photos anywhere yet (cards show an empty placeholder thumbnail) --
  Phase 2.
