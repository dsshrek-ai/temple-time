-- Temple Time -- schema for MyDataWorld
-- Run in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER My Apps
-- Hub's own api/schema.sql (this uses the shared users/sessions/apps/
-- app_access/app_usage_log tables).
--
-- Multi-user by the standard MyDataWorld pattern: anyone granted app_access
-- for 'temple-time' can log in, but every row below is scoped by user_id, so
-- each person's Temples/Visits/People/Plans/Photos stay private to them.
--
-- Tables are prefixed tt_ so they can't collide with another app's.
--
-- PHASE 1 (this file): Temples, People, Visits.
-- PHASE 2 will ALTER this schema to add tt_photos plus
--   primary_photo_id on tt_temples and cover_photo_id on tt_visits.
-- PHASE 3 will add tt_plans (+ its purpose/work/people join tables) plus a
--   plan_id column on tt_visits (nullable -- set when a Visit is logged from
--   a Plan).

-- ---------- PLATFORM TABLES (shared) -- idempotent for a standalone run ----------

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- TEMPLES ----------
-- status: Operating | Announced | Under Construction | Open House | Dedicated
--         | Renovation / Closed | Other

CREATE TABLE IF NOT EXISTS tt_temples (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  name            VARCHAR(200) NOT NULL,
  short_name      VARCHAR(100) NULL,
  status          VARCHAR(30) NOT NULL DEFAULT 'Operating',
  street_address  VARCHAR(200) NULL,
  address_line2   VARCHAR(200) NULL,
  city            VARCHAR(100) NULL,
  state_region    VARCHAR(100) NULL,
  postal_code     VARCHAR(20)  NULL,
  country         VARCHAR(100) NULL,
  latitude        DECIMAL(10,7) NULL,
  longitude       DECIMAL(10,7) NULL,
  phone           VARCHAR(30)  NULL,
  website         VARCHAR(500) NULL,
  notes           TEXT NULL,
  favorite        TINYINT(1) NOT NULL DEFAULT 0,
  on_visit_list   TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tt_temples_user (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- PEOPLE ----------
-- relationship: Spouse | Child | Grandchild | Parent | Sibling | Other Family
--               | Friend | Church Friend | Ward Member | Other

CREATE TABLE IF NOT EXISTS tt_people (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NULL,
  display_name    VARCHAR(150) NULL,
  relationship    VARCHAR(30) NULL,
  notes           TEXT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tt_people_user (user_id, active, first_name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- VISITS ----------

CREATE TABLE IF NOT EXISTS tt_visits (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  user_id                INT NOT NULL,
  temple_id              INT NOT NULL,
  visit_date             DATE NOT NULL,
  arrival_time           TIME NULL,
  departure_time         TIME NULL,
  group_name             VARCHAR(200) NULL,
  notes                  TEXT NULL,
  spiritual_impressions  TEXT NULL,
  memorable_experiences  TEXT NULL,
  people_encountered     TEXT NULL,
  favorite_visit         TINYINT(1) NOT NULL DEFAULT 0,
  created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tt_visits_user (user_id, visit_date),
  KEY ix_tt_visits_temple (temple_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (temple_id) REFERENCES tt_temples(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visit Purpose is multi-select: Temple Work | Temple Grounds | Open House |
-- Tour | Family Visit | Youth / Children Visit | Prospective Temple-Goer
-- Visit | Special Event | Other
CREATE TABLE IF NOT EXISTS tt_visit_purposes (
  visit_id  INT NOT NULL,
  purpose   VARCHAR(40) NOT NULL,
  PRIMARY KEY (visit_id, purpose),
  FOREIGN KEY (visit_id) REFERENCES tt_visits(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Work Performed is multi-select: Baptisms | Confirmations | Initiatory |
-- Endowment | Sealing | Other. Counts of Visits containing a work type, not
-- counts of individual ordinances.
CREATE TABLE IF NOT EXISTS tt_visit_work (
  visit_id   INT NOT NULL,
  work_type  VARCHAR(20) NOT NULL,
  PRIMARY KEY (visit_id, work_type),
  FOREIGN KEY (visit_id) REFERENCES tt_visits(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Who With" -- People present for the Visit, from tt_people.
CREATE TABLE IF NOT EXISTS tt_visit_people (
  visit_id   INT NOT NULL,
  person_id  INT NOT NULL,
  PRIMARY KEY (visit_id, person_id),
  FOREIGN KEY (visit_id) REFERENCES tt_visits(id) ON DELETE CASCADE,
  FOREIGN KEY (person_id) REFERENCES tt_people(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_tags (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  user_id  INT NOT NULL,
  name     VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_tt_tags_user_name (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_visit_tags (
  visit_id  INT NOT NULL,
  tag_id    INT NOT NULL,
  PRIMARY KEY (visit_id, tag_id),
  FOREIGN KEY (visit_id) REFERENCES tt_visits(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tt_tags(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) Register the app with the Hub -- run the "NEW APP: Temple Time" block
--    appended to my-apps-hub/api/schema.sql.
--
-- 2) Grant yourself (and anyone else) the app -- either through the Hub's
--    admin.html, or directly:
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com' AND a.app_key = 'temple-time';
-- ============================================================
