# Temple Time

A personal temple worship planning, tracking, and memory application. See
`TempleTime.md` (design doc) for the full product vision.

Built on the **MyDataWorld** platform, the same pattern as Choir Connect,
Reading List, and T-Minus: static HTML/CSS/vanilla-JS front end (GitHub
Pages), a single `api/api.php` (mysqli action router) on seniorfamily.org,
shared `users`/`sessions` login via **My Apps Hub** SSO. Multi-user: anyone
granted access can log in, but every row is scoped by `user_id`, so each
person's Temples/Visits/People/Plans/Photos stay private to them.

## Status: Phase 1 in progress

- [x] Phase 0 -- project foundation, schema, API auth skeleton
- [x] Phase 1 (backend) -- Temples, People, Visits CRUD in `api/api.php` +
      `api/schema.sql`
- [ ] Phase 1 (front end) -- Dashboard, Temple list/detail, Quick Log, Visit
      detail, People screens (`index.html` currently only proves the backend
      is wired up -- lists Temples and summary counts)
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
