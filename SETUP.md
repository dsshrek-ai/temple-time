# Temple Time -- Setup

## 1. Database

1. Open phpMyAdmin for the MyDataWorld database (the same DB used by Choir
   Connect, Reading List, T-Minus, etc.).
2. Run `api/schema.sql` in the SQL tab. It's idempotent (`CREATE TABLE IF NOT
   EXISTS`), so it's safe even if the shared `users`/`sessions` tables already
   exist from another app.

## 2. Register with My Apps Hub

Append a block to `my-apps-hub/api/schema.sql` (same pattern as the other
apps -- see the "NEW APP: Reading List" / "NEW APP: Life Tempo" blocks
already in that file) and run it in phpMyAdmin:

```sql
INSERT INTO apps (app_key, name, is_public, description, icon_emoji, icon_color_class, launch_url, sso_enabled) VALUES
  ('temple-time', 'Temple Time', 0,
   'A personal temple worship planning, tracking, and memory app -- log visits, plan future ones, and preserve photos and journal entries.',
   '🕊️', 'icon-teal', 'https://dsshrek-ai.github.io/temple-time/', 1)
ON DUPLICATE KEY UPDATE
  name             = VALUES(name),
  is_public        = VALUES(is_public),
  description      = VALUES(description),
  icon_emoji       = VALUES(icon_emoji),
  icon_color_class = VALUES(icon_color_class),
  launch_url       = VALUES(launch_url),
  sso_enabled      = VALUES(sso_enabled);
```

Then grant yourself (and anyone else) access, either through the Hub's
`admin.html` or directly in phpMyAdmin:

```sql
INSERT INTO app_access (user_id, app_id)
SELECT u.id, a.id FROM users u, apps a
WHERE u.username = 'you@example.com' AND a.app_key = 'temple-time';
```

## 3. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   MyDataWorld DB credentials.
2. Upload the `api/` folder (including `config.php`) via FTP/File Manager to
   `seniorfamily.org/temple-time-api/`.
3. Confirm `js/api.js`'s `CONFIG.API_URL` points at
   `https://seniorfamily.org/temple-time-api/api.php` (already set).

## 4. Deploy the front end

1. Push this repo to GitHub (`dsshrek-ai/temple-time`).
2. Enable GitHub Pages (root of `main`).
3. Open the app from My Apps Hub -- it hands off a session token
   (`?token=...`) so you're logged in automatically.

## 5. Phase 2: enable Photo uploads

1. Run the "PHASE 2: PHOTOS" section of `api/schema.sql` in phpMyAdmin
   (adds `tt_photos` + two join tables, and two `ALTER TABLE`s on
   `tt_temples`/`tt_visits`). **Do this before uploading the Phase-2
   `api.php`** -- the updated `templesForUser`/`visitsForUser` queries
   reference the new `primary_photo_id`/`cover_photo_id` columns, so
   uploading the new API code against an un-migrated database breaks
   *everything*, not just Photos (Temples/Visits screens fail to load
   too). If you ever do them out of order by accident, running the SQL
   fixes it immediately -- no re-upload needed.
2. Create a folder via FTP/File Manager for uploaded photos, e.g.
   `seniorfamily.org/temple-time-photos/`, and make sure PHP can write to
   it (typical shared-host default permissions are fine).
3. In `api/config.php`, set `PHOTO_UPLOAD_DIR` to that folder's server
   filesystem path (e.g. `/home/ACCOUNT/public_html/temple-time-photos`)
   and `PHOTO_BASE_URL` to its public URL (e.g.
   `https://seniorfamily.org/temple-time-photos`). Leaving either blank
   disables uploads -- `addPhoto` fails with a clear "not configured"
   message instead of a crash.
4. Re-upload `api/api.php` and `api/config.php` by FTP.

## 6. Phase 3: enable Plans

1. Run the "PHASE 3: PLANS" section of `api/schema.sql` in phpMyAdmin
   (adds `tt_plans` + three join tables, and one `ALTER TABLE` adding
   `plan_id` to `tt_visits`). **Same ordering rule as Phase 2** -- run this
   before uploading the Phase-3 `api.php`, since `saveVisit`/
   `visitsForUser` now reference `tt_visits.plan_id` and several pages call
   the new `plans` action.
2. Re-upload `api/api.php` by FTP. No new `config.php` values needed --
   Google Calendar is a client-side quick-add link (no API key) and Google
   Maps just builds a URL, so there's nothing to configure server-side.

## Re-deploying after a change

- Front end (`index.html`, `style.css`, `js/*.js`): push to GitHub, Pages
  redeploys automatically.
- Backend (`api/api.php`): re-upload by FTP to
  `seniorfamily.org/temple-time-api/` -- it does not auto-deploy from GitHub.
- Schema changes: run the new `CREATE TABLE` / `ALTER TABLE` statements by
  hand in phpMyAdmin, **before** re-uploading any `api.php` that depends on
  them (see the Phase 2 note above -- worth double-checking whenever a phase
  adds a column that an *existing* query will now select, since that can
  break screens that were already working).
