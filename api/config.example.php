<?php
// Copy this file to config.php, fill in the real values, and upload config.php via FTP/File Manager.
// config.php is gitignored -- it should never be committed.

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// How long a login session stays valid.
define('SESSION_LIFETIME_DAYS', 30);

// Phase 2 (Photos): server filesystem path of the folder where uploaded
// Photos are written (no trailing slash), e.g.
// '/home/ACCOUNT/public_html/temple-time-photos'. Create this folder via
// FTP/File Manager first and make sure PHP can write to it. Leave '' to
// disable photo uploads -- the app then just won't offer the upload field.
define('PHOTO_UPLOAD_DIR', '');

// Public URL of that same folder (no trailing slash), e.g.
// 'https://seniorfamily.org/temple-time-photos'. api.php builds each
// Photo's real image/thumbnail URL from this constant + the filename stored
// in the database, same pattern as Choir Connect's SONG_FILES_BASE_URL.
define('PHOTO_BASE_URL', '');
