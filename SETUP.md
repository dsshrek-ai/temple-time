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

## Re-deploying after a change

- Front end (`index.html`, `style.css`, `js/*.js`): push to GitHub, Pages
  redeploys automatically.
- Backend (`api/api.php`): re-upload by FTP to
  `seniorfamily.org/temple-time-api/` -- it does not auto-deploy from GitHub.
- Schema changes: run the new `CREATE TABLE` / `ALTER TABLE` statements by
  hand in phpMyAdmin.
