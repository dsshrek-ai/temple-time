# Temple Time

A personal temple worship planning, tracking, and memory application. See
`TempleTime.md` (design doc) for the full product vision.

Built on the **MyDataWorld** platform, the same pattern as Choir Connect,
Reading List, and T-Minus: static HTML/CSS/vanilla-JS front end (GitHub
Pages), a single `api/api.php` (mysqli action router) on seniorfamily.org,
shared `users`/`sessions` login via **My Apps Hub** SSO. Multi-user: anyone
granted access can log in, but every row is scoped by `user_id`, so each
person's Temples/Visits/People/Plans/Photos stay private to them.

## Status: Phase 1 live; Phase 2 built, pending deployment

- [x] Phase 0 -- project foundation, schema, API auth skeleton
- [x] Phase 1 -- Temples, People, Visits CRUD + full front end (Dashboard,
      Quick Log, detail pages). **Deployed and confirmed working.**
- [x] Phase 2 (built, not deployed) -- Photos: multi-file upload with
      client-side canvas resize (1600x1600 standard / ~450px thumbnail,
      always re-encoded as JPEG -- handles HEIC/HEIF from iPhones without
      any server-side HEIC decoding) plus a server-side GD re-resize that
      enforces the same caps regardless of what the client sends. Gallery
      (`photos.html`) with Favorites/Temple/Person/Tag/Year filters, detail
      page (`photo.html`) with caption/date/People/Tags editing and
      Favorite/Set-as-Primary/Set-as-Cover/Delete actions. Upload wired into
      Temple and Visit detail pages; Temple/Visit cards and the Dashboard's
      "Recent Memories" now show real thumbnails. See `SETUP.md` section 5
      to enable -- **the schema migration must run before the new api.php is
      uploaded**, or Temples/Visits break too (see that section for why).
- [ ] Phase 3 -- Plans, Google Maps navigation, Google Calendar ("Add to
      Calendar" link)
- [ ] Phase 4 -- Statistics & streaks
- [ ] Phase 5 -- Search, filters, Nearby Temples, polish

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

Plans (Phase 3) will extend this schema additively -- see the comments at
the top of `api/schema.sql`.

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
