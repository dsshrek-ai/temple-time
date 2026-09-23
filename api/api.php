<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// ---- Auth: shared MyDataWorld login + an app_access grant for 'temple-time' ----
// Multi-user: anyone granted access can log in, but every query below is
// scoped by user_id, so each person's data stays private to them.

const APP_KEY = 'temple-time';

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];
  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $row;
}

function hasAppAccess(array $user): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $key = APP_KEY;
  $stmt->bind_param('is', $user['id'], $key);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  return (bool)$ok;
}

function logAppUsage(int $userId): void {
  try {
    $key = APP_KEY;
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user)) {
    fail('Not authorized for Temple Time', 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// ---- Shared vocabularies ----

const STATUSES = ['Operating', 'Announced', 'Under Construction', 'Open House', 'Dedicated', 'Renovation / Closed', 'Other'];
const RELATIONSHIPS = ['Spouse', 'Child', 'Grandchild', 'Parent', 'Sibling', 'Other Family', 'Friend', 'Church Friend', 'Ward Member', 'Other'];
const PURPOSES = ['Temple Work', 'Temple Grounds', 'Open House', 'Tour', 'Family Visit', 'Youth / Children Visit', 'Prospective Temple-Goer Visit', 'Special Event', 'Other'];
const WORK_TYPES = ['Baptisms', 'Confirmations', 'Initiatory', 'Endowment', 'Sealing', 'Other'];

function normList(array $values, array $allowed): array {
  $out = [];
  foreach ($values as $v) {
    $v = trim((string)$v);
    foreach ($allowed as $a) {
      if (strcasecmp($a, $v) === 0 && !in_array($a, $out, true)) {
        $out[] = $a;
        break;
      }
    }
  }
  return $out;
}

function nullIfBlank(string $s): ?string {
  $s = trim($s);
  return $s === '' ? null : $s;
}

// ---- Temples ----

function templesForUser(int $userId): array {
  $stmt = db()->prepare(
    "SELECT t.*, pp.thumb_path AS primary_photo_thumb,
       (SELECT COUNT(*) FROM tt_visits v WHERE v.temple_id = t.id) AS visit_count,
       (SELECT MIN(v.visit_date) FROM tt_visits v WHERE v.temple_id = t.id) AS first_visit_date,
       (SELECT MAX(v.visit_date) FROM tt_visits v WHERE v.temple_id = t.id) AS last_visit_date,
       EXISTS(
         SELECT 1 FROM tt_visits v
         JOIN tt_visit_purposes p ON p.visit_id = v.id
         WHERE v.temple_id = t.id AND p.purpose = 'Temple Grounds'
       ) AS grounds_visited
     FROM tt_temples t
     LEFT JOIN tt_photos pp ON pp.id = t.primary_photo_id
     WHERE t.user_id = ? ORDER BY t.name"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => $r['name'],
      'ShortName' => $r['short_name'],
      'Status' => $r['status'],
      'StreetAddress' => $r['street_address'],
      'AddressLine2' => $r['address_line2'],
      'City' => $r['city'],
      'StateRegion' => $r['state_region'],
      'PostalCode' => $r['postal_code'],
      'Country' => $r['country'],
      'Latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
      'Longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
      'Phone' => $r['phone'],
      'Website' => $r['website'],
      'Notes' => $r['notes'],
      'Favorite' => (bool)$r['favorite'],
      'OnVisitList' => (bool)$r['on_visit_list'],
      'VisitCount' => (int)$r['visit_count'],
      'Visited' => (int)$r['visit_count'] > 0,
      'FirstVisitDate' => $r['first_visit_date'],
      'LastVisitDate' => $r['last_visit_date'],
      'GroundsVisited' => (bool)$r['grounds_visited'],
      'PrimaryPhotoId' => $r['primary_photo_id'] !== null ? (int)$r['primary_photo_id'] : null,
      'PrimaryPhotoThumbUrl' => $r['primary_photo_thumb'] ? photoUrl($r['primary_photo_thumb']) : null,
    ];
  }
  $stmt->close();
  return $out;
}

function saveTemple(int $userId, array $t, ?int $id): int {
  $name = trim((string)($t['name'] ?? ''));
  if ($name === '') { fail('Temple name is required'); }
  $status = in_array($t['status'] ?? '', STATUSES, true) ? $t['status'] : 'Operating';
  $shortName = nullIfBlank((string)($t['shortName'] ?? ''));
  $street = nullIfBlank((string)($t['streetAddress'] ?? ''));
  $line2 = nullIfBlank((string)($t['addressLine2'] ?? ''));
  $city = nullIfBlank((string)($t['city'] ?? ''));
  $state = nullIfBlank((string)($t['stateRegion'] ?? ''));
  $postal = nullIfBlank((string)($t['postalCode'] ?? ''));
  $country = nullIfBlank((string)($t['country'] ?? ''));
  $lat = isset($t['latitude']) && $t['latitude'] !== '' ? (float)$t['latitude'] : null;
  $lng = isset($t['longitude']) && $t['longitude'] !== '' ? (float)$t['longitude'] : null;
  $phone = nullIfBlank((string)($t['phone'] ?? ''));
  $website = nullIfBlank((string)($t['website'] ?? ''));
  $notes = nullIfBlank((string)($t['notes'] ?? ''));
  $favorite = !empty($t['favorite']) ? 1 : 0;
  $onList = !empty($t['onVisitList']) ? 1 : 0;

  if ($id === null) {
    $stmt = db()->prepare(
      'INSERT INTO tt_temples (user_id, name, short_name, status, street_address, address_line2,
         city, state_region, postal_code, country, latitude, longitude, phone, website, notes,
         favorite, on_visit_list)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param(
      'issssssssssddssii',
      $userId, $name, $shortName, $status, $street, $line2, $city, $state, $postal, $country,
      $lat, $lng, $phone, $website, $notes, $favorite, $onList
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    return $newId;
  }

  $stmt = db()->prepare(
    'UPDATE tt_temples SET name=?, short_name=?, status=?, street_address=?, address_line2=?,
       city=?, state_region=?, postal_code=?, country=?, latitude=?, longitude=?, phone=?,
       website=?, notes=?, favorite=?, on_visit_list=?
     WHERE id=? AND user_id=?'
  );
  $stmt->bind_param(
    'sssssssssddsssiiii',
    $name, $shortName, $status, $street, $line2, $city, $state, $postal, $country,
    $lat, $lng, $phone, $website, $notes, $favorite, $onList, $id, $userId
  );
  $stmt->execute();
  $stmt->close();
  return $id;
}

function deleteTemple(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM tt_temples WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- People ----

function peopleForUser(int $userId): array {
  $stmt = db()->prepare(
    "SELECT p.*,
       (SELECT COUNT(*) FROM tt_visit_people vp WHERE vp.person_id = p.id) AS visits_together,
       (SELECT COUNT(DISTINCT v.temple_id) FROM tt_visit_people vp
          JOIN tt_visits v ON v.id = vp.visit_id WHERE vp.person_id = p.id) AS temples_together
     FROM tt_people p WHERE p.user_id = ? ORDER BY p.active DESC, p.first_name"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'FirstName' => $r['first_name'],
      'LastName' => $r['last_name'],
      'DisplayName' => $r['display_name'],
      'Relationship' => $r['relationship'],
      'Notes' => $r['notes'],
      'Active' => (bool)$r['active'],
      'VisitsTogether' => (int)$r['visits_together'],
      'TemplesTogether' => (int)$r['temples_together'],
      // empty(): tolerates a database the Person Photo migration hasn't run on yet.
      'PhotoUrl' => !empty($r['photo_path']) ? photoUrl($r['photo_path']) : null,
      'PhotoThumbUrl' => !empty($r['photo_thumb_path']) ? photoUrl($r['photo_thumb_path']) : null,
    ];
  }
  $stmt->close();
  return $out;
}

function savePerson(int $userId, array $p, ?int $id): int {
  $first = trim((string)($p['firstName'] ?? ''));
  if ($first === '') { fail('First name is required'); }
  $last = nullIfBlank((string)($p['lastName'] ?? ''));
  $display = nullIfBlank((string)($p['displayName'] ?? ''));
  $relationship = in_array($p['relationship'] ?? '', RELATIONSHIPS, true) ? $p['relationship'] : null;
  $notes = nullIfBlank((string)($p['notes'] ?? ''));
  $active = array_key_exists('active', $p) ? (!empty($p['active']) ? 1 : 0) : 1;

  if ($id === null) {
    $stmt = db()->prepare(
      'INSERT INTO tt_people (user_id, first_name, last_name, display_name, relationship, notes, active)
       VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('isssssi', $userId, $first, $last, $display, $relationship, $notes, $active);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    return $newId;
  }

  $stmt = db()->prepare(
    'UPDATE tt_people SET first_name=?, last_name=?, display_name=?, relationship=?, notes=?, active=?
     WHERE id=? AND user_id=?'
  );
  $stmt->bind_param('sssssiii', $first, $last, $display, $relationship, $notes, $active, $id, $userId);
  $stmt->execute();
  $stmt->close();
  return $id;
}

function deletePerson(int $userId, int $id): void {
  removePersonPhotoFiles($userId, $id);
  $stmt = db()->prepare('DELETE FROM tt_people WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- Person Photo ----
// A single profile picture per Person, stored as columns on tt_people rather
// than a tt_photos row -- gallery Photos must belong to a Temple or Visit,
// and a portrait isn't a temple memory. Files live in the same
// PHOTO_UPLOAD_DIR as gallery Photos, with a "person_" prefix.

function setPersonPhoto(int $userId, int $personId, array $files): void {
  if (!photosConfigured()) { fail('Photo uploads are not configured on the server yet.', 500); }

  $chk = db()->prepare('SELECT 1 FROM tt_people WHERE id = ? AND user_id = ?');
  $chk->bind_param('ii', $personId, $userId);
  $chk->execute();
  if (!$chk->get_result()->fetch_row()) { fail('Person not found'); }
  $chk->close();

  $standard = validateUploadedImage($files['standard'] ?? null);
  $thumb = validateUploadedImage($files['thumb'] ?? null);
  $standardBytes = resizeToJpeg($standard['tmp_name'], 800, 85);
  $thumbBytes = resizeToJpeg($thumb['tmp_name'], 500, 80);
  if ($standardBytes === null || $thumbBytes === null) {
    fail('Could not process that image on the server (unsupported format or GD unavailable).', 500);
  }

  $token = 'person_' . bin2hex(random_bytes(16));
  $imagePath = $token . '.jpg';
  $thumbPath = $token . '_thumb.jpg';
  $dir = rtrim(PHOTO_UPLOAD_DIR, '/');
  if (@file_put_contents($dir . '/' . $imagePath, $standardBytes) === false) {
    fail('Could not save the photo.', 500);
  }
  if (@file_put_contents($dir . '/' . $thumbPath, $thumbBytes) === false) {
    @unlink($dir . '/' . $imagePath);
    fail('Could not save the photo thumbnail.', 500);
  }

  // Replacing a photo: drop the old files only after the new ones are safely written.
  removePersonPhotoFiles($userId, $personId);
  $stmt = db()->prepare('UPDATE tt_people SET photo_path = ?, photo_thumb_path = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ssii', $imagePath, $thumbPath, $personId, $userId);
  $stmt->execute();
  $stmt->close();
}

function removePersonPhoto(int $userId, int $personId): void {
  removePersonPhotoFiles($userId, $personId);
  $stmt = db()->prepare('UPDATE tt_people SET photo_path = NULL, photo_thumb_path = NULL WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $personId, $userId);
  $stmt->execute();
  $stmt->close();
}

function removePersonPhotoFiles(int $userId, int $personId): void {
  if (!photosConfigured()) { return; }
  $stmt = db()->prepare('SELECT photo_path, photo_thumb_path FROM tt_people WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $personId, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) { return; }
  $dir = rtrim(PHOTO_UPLOAD_DIR, '/');
  if (!empty($row['photo_path'])) { @unlink($dir . '/' . $row['photo_path']); }
  if (!empty($row['photo_thumb_path'])) { @unlink($dir . '/' . $row['photo_thumb_path']); }
}

// ---- Visits ----

function tagIdsForNames(int $userId, array $names): array {
  $ids = [];
  $stmt = db()->prepare('SELECT id FROM tt_tags WHERE user_id = ? AND name = ?');
  $ins = db()->prepare('INSERT INTO tt_tags (user_id, name) VALUES (?, ?)');
  foreach ($names as $name) {
    $name = trim((string)$name);
    if ($name === '') { continue; }
    $stmt->bind_param('is', $userId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    if ($row) {
      $ids[] = (int)$row[0];
    } else {
      $ins->bind_param('is', $userId, $name);
      $ins->execute();
      $ids[] = $ins->insert_id;
    }
  }
  $stmt->close();
  $ins->close();
  return $ids;
}

function visitsForUser(int $userId, ?int $temple_id = null): array {
  $sql = "SELECT v.*, t.name AS temple_name, t.city AS temple_city, t.state_region AS temple_state,
            cp.thumb_path AS cover_photo_thumb, tp.thumb_path AS temple_primary_photo_thumb
          FROM tt_visits v
          JOIN tt_temples t ON t.id = v.temple_id
          LEFT JOIN tt_photos cp ON cp.id = v.cover_photo_id
          LEFT JOIN tt_photos tp ON tp.id = t.primary_photo_id
          WHERE v.user_id = ?" . ($temple_id ? " AND v.temple_id = ?" : "") . "
          ORDER BY v.visit_date DESC, v.id DESC";
  $stmt = db()->prepare($sql);
  if ($temple_id) {
    $stmt->bind_param('ii', $userId, $temple_id);
  } else {
    $stmt->bind_param('i', $userId);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $visits = [];
  $ids = [];
  while ($r = $res->fetch_assoc()) {
    $id = (int)$r['id'];
    $ids[] = $id;
    $visits[$id] = [
      'Id' => $id,
      'TempleId' => (int)$r['temple_id'],
      'TempleName' => $r['temple_name'],
      'TempleCity' => $r['temple_city'],
      'TempleState' => $r['temple_state'],
      'VisitDate' => $r['visit_date'],
      'ArrivalTime' => $r['arrival_time'],
      'DepartureTime' => $r['departure_time'],
      'GroupName' => $r['group_name'],
      'Notes' => $r['notes'],
      'SpiritualImpressions' => $r['spiritual_impressions'],
      'MemorableExperiences' => $r['memorable_experiences'],
      'PeopleEncountered' => $r['people_encountered'],
      'FavoriteVisit' => (bool)$r['favorite_visit'],
      'PlanId' => $r['plan_id'] !== null ? (int)$r['plan_id'] : null,
      // Visit Cover Photo if set, else the Temple's Primary Photo (spec
      // 6.3/8.1: cards show "Visit Cover Photo or Temple Primary Photo").
      'CoverPhotoThumbUrl' => $r['cover_photo_thumb']
        ? photoUrl($r['cover_photo_thumb'])
        : ($r['temple_primary_photo_thumb'] ? photoUrl($r['temple_primary_photo_thumb']) : null),
      'Purposes' => [],
      'WorkPerformed' => [],
      'WhoWith' => [],
      'Tags' => [],
    ];
  }
  $stmt->close();
  if (!$ids) { return []; }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $stmt = db()->prepare("SELECT visit_id, purpose FROM tt_visit_purposes WHERE visit_id IN ($placeholders)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $visits[(int)$r['visit_id']]['Purposes'][] = $r['purpose']; }
  $stmt->close();

  $stmt = db()->prepare("SELECT visit_id, work_type FROM tt_visit_work WHERE visit_id IN ($placeholders)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $visits[(int)$r['visit_id']]['WorkPerformed'][] = $r['work_type']; }
  $stmt->close();

  $stmt = db()->prepare(
    "SELECT vp.visit_id, p.id, p.first_name, p.last_name, p.display_name
     FROM tt_visit_people vp JOIN tt_people p ON p.id = vp.person_id
     WHERE vp.visit_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $visits[(int)$r['visit_id']]['WhoWith'][] = [
      'Id' => (int)$r['id'],
      'Name' => $r['display_name'] ?: trim($r['first_name'] . ' ' . $r['last_name']),
    ];
  }
  $stmt->close();

  $stmt = db()->prepare(
    "SELECT vt.visit_id, tg.name FROM tt_visit_tags vt JOIN tt_tags tg ON tg.id = vt.tag_id
     WHERE vt.visit_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $visits[(int)$r['visit_id']]['Tags'][] = $r['name']; }
  $stmt->close();

  return array_values($visits);
}

function saveVisit(int $userId, array $v, ?int $id): int {
  $templeId = (int)($v['templeId'] ?? 0);
  $date = trim((string)($v['visitDate'] ?? ''));
  if ($templeId <= 0) { fail('Temple is required'); }
  if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fail('A valid Visit Date is required'); }

  // Confirm the temple belongs to this user before attaching a Visit to it.
  $chk = db()->prepare('SELECT 1 FROM tt_temples WHERE id = ? AND user_id = ?');
  $chk->bind_param('ii', $templeId, $userId);
  $chk->execute();
  if (!$chk->get_result()->fetch_row()) { fail('Temple not found'); }
  $chk->close();

  $arrival = nullIfBlank((string)($v['arrivalTime'] ?? ''));
  $departure = nullIfBlank((string)($v['departureTime'] ?? ''));
  $group = nullIfBlank((string)($v['groupName'] ?? ''));
  $notes = nullIfBlank((string)($v['notes'] ?? ''));
  $spiritual = nullIfBlank((string)($v['spiritualImpressions'] ?? ''));
  $memorable = nullIfBlank((string)($v['memorableExperiences'] ?? ''));
  $encountered = nullIfBlank((string)($v['peopleEncountered'] ?? ''));
  $favorite = !empty($v['favoriteVisit']) ? 1 : 0;

  // "Log This Visit" from a Plan: only meaningful when creating a brand new
  // Visit (editing an existing one never re-links or re-completes a Plan).
  $planId = null;
  if ($id === null && !empty($v['planId'])) {
    $planId = (int)$v['planId'];
    $chk = db()->prepare("SELECT 1 FROM tt_plans WHERE id = ? AND user_id = ? AND status = 'Planned'");
    $chk->bind_param('ii', $planId, $userId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { fail('Plan not found, or it was already logged/cancelled.'); }
    $chk->close();
  }

  $conn = db();
  $conn->begin_transaction();
  try {
    if ($id === null) {
      $stmt = $conn->prepare(
        'INSERT INTO tt_visits (user_id, temple_id, visit_date, arrival_time, departure_time,
           group_name, notes, spiritual_impressions, memorable_experiences, people_encountered, favorite_visit, plan_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
      );
      $stmt->bind_param(
        'iissssssssii', $userId, $templeId, $date, $arrival, $departure, $group, $notes,
        $spiritual, $memorable, $encountered, $favorite, $planId
      );
      $stmt->execute();
      $id = $stmt->insert_id;
      $stmt->close();

      if ($planId !== null) {
        $upd = $conn->prepare("UPDATE tt_plans SET status = 'Completed' WHERE id = ? AND user_id = ?");
        $upd->bind_param('ii', $planId, $userId);
        $upd->execute();
        $upd->close();
      }
    } else {
      $stmt = $conn->prepare(
        'UPDATE tt_visits SET temple_id=?, visit_date=?, arrival_time=?, departure_time=?,
           group_name=?, notes=?, spiritual_impressions=?, memorable_experiences=?,
           people_encountered=?, favorite_visit=?
         WHERE id=? AND user_id=?'
      );
      $stmt->bind_param(
        'issssssssiii', $templeId, $date, $arrival, $departure, $group, $notes,
        $spiritual, $memorable, $encountered, $favorite, $id, $userId
      );
      $stmt->execute();
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_visit_purposes WHERE visit_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $purposes = normList((array)($v['purposes'] ?? []), PURPOSES);
    if ($purposes) {
      $stmt = $conn->prepare('INSERT INTO tt_visit_purposes (visit_id, purpose) VALUES (?, ?)');
      foreach ($purposes as $p) { $stmt->bind_param('is', $id, $p); $stmt->execute(); }
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_visit_work WHERE visit_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $work = normList((array)($v['workPerformed'] ?? []), WORK_TYPES);
    if ($work) {
      $stmt = $conn->prepare('INSERT INTO tt_visit_work (visit_id, work_type) VALUES (?, ?)');
      foreach ($work as $w) { $stmt->bind_param('is', $id, $w); $stmt->execute(); }
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_visit_people WHERE visit_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $personIds = array_values(array_unique(array_map('intval', (array)($v['personIds'] ?? []))));
    if ($personIds) {
      // Only attach People that belong to this user.
      $placeholders = implode(',', array_fill(0, count($personIds), '?'));
      $types = 'i' . str_repeat('i', count($personIds));
      $chk = $conn->prepare("SELECT id FROM tt_people WHERE user_id = ? AND id IN ($placeholders)");
      $params = array_merge([$userId], $personIds);
      $chk->bind_param($types, ...$params);
      $chk->execute();
      $validIds = [];
      $res = $chk->get_result();
      while ($r = $res->fetch_row()) { $validIds[] = (int)$r[0]; }
      $chk->close();
      if ($validIds) {
        $stmt = $conn->prepare('INSERT INTO tt_visit_people (visit_id, person_id) VALUES (?, ?)');
        foreach ($validIds as $pid) { $stmt->bind_param('ii', $id, $pid); $stmt->execute(); }
        $stmt->close();
      }
    }

    $del = $conn->prepare('DELETE FROM tt_visit_tags WHERE visit_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $tagNames = (array)($v['tags'] ?? []);
    if ($tagNames) {
      $tagIds = tagIdsForNames($userId, $tagNames);
      if ($tagIds) {
        $stmt = $conn->prepare('INSERT INTO tt_visit_tags (visit_id, tag_id) VALUES (?, ?)');
        foreach ($tagIds as $tid) { $stmt->bind_param('ii', $id, $tid); $stmt->execute(); }
        $stmt->close();
      }
    }

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    fail('Could not save Visit: ' . $e->getMessage(), 500);
  }

  return $id;
}

function deleteVisit(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM tt_visits WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- Plans ----

const PLAN_STATUSES = ['Planned', 'Completed', 'Cancelled'];

function plansForUser(int $userId): array {
  $stmt = db()->prepare(
    "SELECT p.*, t.name AS temple_name, t.city AS temple_city, t.state_region AS temple_state,
       t.street_address, t.address_line2, t.postal_code, t.country, t.latitude, t.longitude,
       tp.thumb_path AS temple_primary_photo_thumb
     FROM tt_plans p
     JOIN tt_temples t ON t.id = p.temple_id
     LEFT JOIN tt_photos tp ON tp.id = t.primary_photo_id
     WHERE p.user_id = ?
     ORDER BY p.planned_date, p.planned_time IS NULL, p.planned_time, p.id"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $plans = [];
  $ids = [];
  while ($r = $res->fetch_assoc()) {
    $id = (int)$r['id'];
    $ids[] = $id;
    $plans[$id] = [
      'Id' => $id,
      'TempleId' => (int)$r['temple_id'],
      'TempleName' => $r['temple_name'],
      'TempleCity' => $r['temple_city'],
      'TempleState' => $r['temple_state'],
      'TempleStreetAddress' => $r['street_address'],
      'TempleAddressLine2' => $r['address_line2'],
      'TemplePostalCode' => $r['postal_code'],
      'TempleCountry' => $r['country'],
      'TempleLatitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
      'TempleLongitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
      'TemplePrimaryPhotoThumbUrl' => $r['temple_primary_photo_thumb'] ? photoUrl($r['temple_primary_photo_thumb']) : null,
      'PlannedDate' => $r['planned_date'],
      'PlannedTime' => $r['planned_time'],
      'EndTime' => $r['end_time'],
      'GroupName' => $r['group_name'],
      'Notes' => $r['notes'],
      'Status' => $r['status'],
      'AppointmentScheduled' => (bool)$r['appointment_scheduled'],
      'Purposes' => [],
      'WorkPerformed' => [],
      'WhoWith' => [],
    ];
  }
  $stmt->close();
  if (!$ids) { return []; }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $stmt = db()->prepare("SELECT plan_id, purpose FROM tt_plan_purposes WHERE plan_id IN ($placeholders)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $plans[(int)$r['plan_id']]['Purposes'][] = $r['purpose']; }
  $stmt->close();

  $stmt = db()->prepare("SELECT plan_id, work_type FROM tt_plan_work WHERE plan_id IN ($placeholders)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $plans[(int)$r['plan_id']]['WorkPerformed'][] = $r['work_type']; }
  $stmt->close();

  $stmt = db()->prepare(
    "SELECT pp.plan_id, pe.id, pe.first_name, pe.last_name, pe.display_name
     FROM tt_plan_people pp JOIN tt_people pe ON pe.id = pp.person_id
     WHERE pp.plan_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $plans[(int)$r['plan_id']]['WhoWith'][] = [
      'Id' => (int)$r['id'],
      'Name' => $r['display_name'] ?: trim($r['first_name'] . ' ' . $r['last_name']),
    ];
  }
  $stmt->close();

  return array_values($plans);
}

function savePlan(int $userId, array $p, ?int $id): int {
  $templeId = (int)($p['templeId'] ?? 0);
  $date = trim((string)($p['plannedDate'] ?? ''));
  if ($templeId <= 0) { fail('Temple is required'); }
  if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fail('A valid Planned Date is required'); }

  $chk = db()->prepare('SELECT 1 FROM tt_temples WHERE id = ? AND user_id = ?');
  $chk->bind_param('ii', $templeId, $userId);
  $chk->execute();
  if (!$chk->get_result()->fetch_row()) { fail('Temple not found'); }
  $chk->close();

  $time = nullIfBlank((string)($p['plannedTime'] ?? ''));
  $endTime = nullIfBlank((string)($p['endTime'] ?? ''));
  $group = nullIfBlank((string)($p['groupName'] ?? ''));
  $notes = nullIfBlank((string)($p['notes'] ?? ''));
  $appointmentScheduled = !empty($p['appointmentScheduled']) ? 1 : 0;

  $conn = db();
  $conn->begin_transaction();
  try {
    if ($id === null) {
      $stmt = $conn->prepare(
        'INSERT INTO tt_plans (user_id, temple_id, planned_date, planned_time, end_time, group_name, notes, status, appointment_scheduled)
         VALUES (?,?,?,?,?,?,?,\'Planned\',?)'
      );
      $stmt->bind_param('iisssssi', $userId, $templeId, $date, $time, $endTime, $group, $notes, $appointmentScheduled);
      $stmt->execute();
      $id = $stmt->insert_id;
      $stmt->close();
    } else {
      $stmt = $conn->prepare(
        'UPDATE tt_plans SET temple_id=?, planned_date=?, planned_time=?, end_time=?, group_name=?, notes=?, appointment_scheduled=?
         WHERE id=? AND user_id=?'
      );
      $stmt->bind_param('isssssiii', $templeId, $date, $time, $endTime, $group, $notes, $appointmentScheduled, $id, $userId);
      $stmt->execute();
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_plan_purposes WHERE plan_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $purposes = normList((array)($p['purposes'] ?? []), PURPOSES);
    if ($purposes) {
      $stmt = $conn->prepare('INSERT INTO tt_plan_purposes (plan_id, purpose) VALUES (?, ?)');
      foreach ($purposes as $x) { $stmt->bind_param('is', $id, $x); $stmt->execute(); }
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_plan_work WHERE plan_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $work = normList((array)($p['workPerformed'] ?? []), WORK_TYPES);
    if ($work) {
      $stmt = $conn->prepare('INSERT INTO tt_plan_work (plan_id, work_type) VALUES (?, ?)');
      foreach ($work as $x) { $stmt->bind_param('is', $id, $x); $stmt->execute(); }
      $stmt->close();
    }

    $del = $conn->prepare('DELETE FROM tt_plan_people WHERE plan_id = ?');
    $del->bind_param('i', $id); $del->execute(); $del->close();
    $personIds = array_values(array_unique(array_map('intval', (array)($p['personIds'] ?? []))));
    if ($personIds) {
      $placeholders = implode(',', array_fill(0, count($personIds), '?'));
      $types = 'i' . str_repeat('i', count($personIds));
      $chk = $conn->prepare("SELECT id FROM tt_people WHERE user_id = ? AND id IN ($placeholders)");
      $params = array_merge([$userId], $personIds);
      $chk->bind_param($types, ...$params);
      $chk->execute();
      $validIds = [];
      $res = $chk->get_result();
      while ($r = $res->fetch_row()) { $validIds[] = (int)$r[0]; }
      $chk->close();
      if ($validIds) {
        $stmt = $conn->prepare('INSERT INTO tt_plan_people (plan_id, person_id) VALUES (?, ?)');
        foreach ($validIds as $pid) { $stmt->bind_param('ii', $id, $pid); $stmt->execute(); }
        $stmt->close();
      }
    }

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    fail('Could not save Plan: ' . $e->getMessage(), 500);
  }

  return $id;
}

function setPlanStatus(int $userId, int $id, string $status): void {
  if (!in_array($status, PLAN_STATUSES, true)) { fail('Invalid status'); }
  $stmt = db()->prepare('UPDATE tt_plans SET status = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('sii', $status, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function deletePlan(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM tt_plans WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- Photos ----

function photosConfigured(): bool {
  return defined('PHOTO_UPLOAD_DIR') && PHOTO_UPLOAD_DIR !== ''
    && defined('PHOTO_BASE_URL') && PHOTO_BASE_URL !== ''
    && is_dir(PHOTO_UPLOAD_DIR) && is_writable(PHOTO_UPLOAD_DIR);
}

function photoUrl(string $filename): string {
  return rtrim(PHOTO_BASE_URL, '/') . '/' . $filename;
}

function photosForUser(int $userId): array {
  $stmt = db()->prepare(
    "SELECT p.*, t.name AS temple_name, v.visit_date, v.temple_id AS visit_temple_id
     FROM tt_photos p
     LEFT JOIN tt_temples t ON t.id = p.temple_id
     LEFT JOIN tt_visits v ON v.id = p.visit_id
     WHERE p.user_id = ?
     ORDER BY p.uploaded_at DESC, p.id DESC"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $photos = [];
  $ids = [];
  while ($r = $res->fetch_assoc()) {
    $id = (int)$r['id'];
    $ids[] = $id;
    $photos[$id] = [
      'Id' => $id,
      'TempleId' => $r['temple_id'] !== null ? (int)$r['temple_id'] : null,
      'TempleName' => $r['temple_name'],
      'VisitId' => $r['visit_id'] !== null ? (int)$r['visit_id'] : null,
      'VisitDate' => $r['visit_date'],
      'ImageUrl' => photoUrl($r['image_path']),
      'ThumbUrl' => photoUrl($r['thumb_path']),
      'OriginalFilename' => $r['original_filename'],
      'DateTaken' => $r['date_taken'],
      'Caption' => $r['caption'],
      'Favorite' => (bool)$r['favorite'],
      'UploadedAt' => $r['uploaded_at'],
      'People' => [],
      'Tags' => [],
    ];
  }
  $stmt->close();
  if (!$ids) { return []; }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $stmt = db()->prepare(
    "SELECT pp.photo_id, pe.id, pe.first_name, pe.last_name, pe.display_name
     FROM tt_photo_people pp JOIN tt_people pe ON pe.id = pp.person_id
     WHERE pp.photo_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $photos[(int)$r['photo_id']]['People'][] = [
      'Id' => (int)$r['id'],
      'Name' => $r['display_name'] ?: trim($r['first_name'] . ' ' . $r['last_name']),
    ];
  }
  $stmt->close();

  $stmt = db()->prepare(
    "SELECT pt.photo_id, tg.name FROM tt_photo_tags pt JOIN tt_tags tg ON tg.id = pt.tag_id
     WHERE pt.photo_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $photos[(int)$r['photo_id']]['Tags'][] = $r['name']; }
  $stmt->close();

  return array_values($photos);
}

// Reads an uploaded image from a temp path and returns a JPEG blob resized
// to fit within $maxSide x $maxSide (no upscaling). The client (js/photo.js)
// already resizes before upload so this is normally a no-op pass-through,
// but it's what actually enforces the 1600x1600 / thumbnail size caps --
// a client that skips resizing, or a direct API call, still gets capped
// here. Requires GD (bundled with virtually all PHP builds).
function resizeToJpeg(string $tmpPath, int $maxSide, int $quality): ?string {
  $info = @getimagesize($tmpPath);
  if (!is_array($info)) { return null; }
  [$width, $height, $type] = $info;
  $src = null;
  switch ($type) {
    case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmpPath); break;
    case IMAGETYPE_PNG: $src = @imagecreatefrompng($tmpPath); break;
    case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($tmpPath); } break;
  }
  if (!$src) { return null; }

  $scale = min(1.0, $maxSide / max($width, $height));
  $newW = max(1, (int)round($width * $scale));
  $newH = max(1, (int)round($height * $scale));
  $dst = imagecreatetruecolor($newW, $newH);
  imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
  imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
  imagedestroy($src);

  ob_start();
  imagejpeg($dst, null, $quality);
  $bytes = ob_get_clean();
  imagedestroy($dst);
  return $bytes === false ? null : $bytes;
}

// Accepts the two files the client already resized ("standard" up to
// 1600x1600, "thumb" ~450px) plus attachment/caption fields, and writes
// them to PHOTO_UPLOAD_DIR under a random filename pair.
function uploadPhoto(int $userId, array $post, array $files): int {
  if (!photosConfigured()) { fail('Photo uploads are not configured on the server yet.', 500); }

  $templeId = isset($post['templeId']) && $post['templeId'] !== '' ? (int)$post['templeId'] : null;
  $visitId = isset($post['visitId']) && $post['visitId'] !== '' ? (int)$post['visitId'] : null;
  if ($templeId === null && $visitId === null) { fail('A Photo needs a Temple or a Visit.'); }

  if ($templeId !== null) {
    $chk = db()->prepare('SELECT 1 FROM tt_temples WHERE id = ? AND user_id = ?');
    $chk->bind_param('ii', $templeId, $userId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { fail('Temple not found'); }
    $chk->close();
  }
  if ($visitId !== null) {
    $chk = db()->prepare('SELECT temple_id FROM tt_visits WHERE id = ? AND user_id = ?');
    $chk->bind_param('ii', $visitId, $userId);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) { fail('Visit not found'); }
    // A Photo uploaded from a Visit is automatically attached to that
    // Visit's Temple too, even if the caller didn't pass templeId.
    if ($templeId === null) { $templeId = (int)$row['temple_id']; }
  }

  $standard = validateUploadedImage($files['standard'] ?? null);
  $thumb = validateUploadedImage($files['thumb'] ?? null);

  // The client already resizes before upload, but the 1600x1600 / ~450px
  // caps are a real constraint (spec section 9.1), not just polite client
  // behavior -- re-encode server-side too so a direct API call (or a client
  // that skipped the resize) can't store an oversized image.
  $standardBytes = resizeToJpeg($standard['tmp_name'], 1600, 85);
  $thumbBytes = resizeToJpeg($thumb['tmp_name'], 500, 80);
  if ($standardBytes === null || $thumbBytes === null) {
    fail('Could not process that image on the server (unsupported format or GD unavailable).', 500);
  }

  $token = bin2hex(random_bytes(16));
  $imagePath = $token . '.jpg';
  $thumbPath = $token . '_thumb.jpg';
  $dir = rtrim(PHOTO_UPLOAD_DIR, '/');

  if (@file_put_contents($dir . '/' . $imagePath, $standardBytes) === false) {
    fail('Could not save the photo.', 500);
  }
  if (@file_put_contents($dir . '/' . $thumbPath, $thumbBytes) === false) {
    @unlink($dir . '/' . $imagePath);
    fail('Could not save the photo thumbnail.', 500);
  }

  $originalFilename = nullIfBlank((string)($post['originalFilename'] ?? ''));
  $caption = nullIfBlank((string)($post['caption'] ?? ''));
  $dateTaken = nullIfBlank((string)($post['dateTaken'] ?? ''));
  if ($dateTaken !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTaken)) { $dateTaken = null; }

  $stmt = db()->prepare(
    'INSERT INTO tt_photos (user_id, temple_id, visit_id, image_path, thumb_path, original_filename, date_taken, caption)
     VALUES (?,?,?,?,?,?,?,?)'
  );
  $stmt->bind_param('iiisssss', $userId, $templeId, $visitId, $imagePath, $thumbPath, $originalFilename, $dateTaken, $caption);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

// getimagesize both identifies the type and proves the bytes are a real,
// decodable image (fileinfo extension not required) -- same check Choir
// Connect's roster-photo upload uses. Since the client always re-encodes as
// JPEG before upload, only jpeg is really expected, but png/webp are
// accepted too in case a future caller sends one directly.
function validateUploadedImage(?array $file): array {
  if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
      || !is_uploaded_file($file['tmp_name'] ?? '')) {
    fail('Missing photo file.');
  }
  if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
    fail('That photo is too large (8MB max).');
  }
  $info = @getimagesize($file['tmp_name']);
  $mime = is_array($info) ? ($info['mime'] ?? '') : '';
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    fail('That file is not a supported image type.');
  }
  return $file;
}

function updatePhoto(int $userId, int $id, array $p): void {
  $caption = nullIfBlank((string)($p['caption'] ?? ''));
  $dateTaken = nullIfBlank((string)($p['dateTaken'] ?? ''));
  if ($dateTaken !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTaken)) { $dateTaken = null; }
  $favorite = !empty($p['favorite']) ? 1 : 0;

  $stmt = db()->prepare('UPDATE tt_photos SET caption=?, date_taken=?, favorite=? WHERE id=? AND user_id=?');
  $stmt->bind_param('ssiii', $caption, $dateTaken, $favorite, $id, $userId);
  $stmt->execute();
  $stmt->close();

  $conn = db();
  $del = $conn->prepare('DELETE FROM tt_photo_people WHERE photo_id = ?');
  $del->bind_param('i', $id); $del->execute(); $del->close();
  $personIds = array_values(array_unique(array_map('intval', (array)($p['personIds'] ?? []))));
  if ($personIds) {
    $placeholders = implode(',', array_fill(0, count($personIds), '?'));
    $types = 'i' . str_repeat('i', count($personIds));
    $chk = $conn->prepare("SELECT id FROM tt_people WHERE user_id = ? AND id IN ($placeholders)");
    $params = array_merge([$userId], $personIds);
    $chk->bind_param($types, ...$params);
    $chk->execute();
    $validIds = [];
    $res = $chk->get_result();
    while ($r = $res->fetch_row()) { $validIds[] = (int)$r[0]; }
    $chk->close();
    if ($validIds) {
      $stmt = $conn->prepare('INSERT INTO tt_photo_people (photo_id, person_id) VALUES (?, ?)');
      foreach ($validIds as $pid) { $stmt->bind_param('ii', $id, $pid); $stmt->execute(); }
      $stmt->close();
    }
  }

  $del = $conn->prepare('DELETE FROM tt_photo_tags WHERE photo_id = ?');
  $del->bind_param('i', $id); $del->execute(); $del->close();
  $tagNames = (array)($p['tags'] ?? []);
  if ($tagNames) {
    $tagIds = tagIdsForNames($userId, $tagNames);
    if ($tagIds) {
      $stmt = $conn->prepare('INSERT INTO tt_photo_tags (photo_id, tag_id) VALUES (?, ?)');
      foreach ($tagIds as $tid) { $stmt->bind_param('ii', $id, $tid); $stmt->execute(); }
      $stmt->close();
    }
  }
}

function deletePhoto(int $userId, int $id): void {
  $stmt = db()->prepare('SELECT image_path, thumb_path FROM tt_photos WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) { return; }

  $stmt = db()->prepare('DELETE FROM tt_photos WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();

  if (photosConfigured()) {
    $dir = rtrim(PHOTO_UPLOAD_DIR, '/');
    @unlink($dir . '/' . $row['image_path']);
    @unlink($dir . '/' . $row['thumb_path']);
  }
}

function setTemplePrimaryPhoto(int $userId, int $templeId, ?int $photoId): void {
  if ($photoId !== null) {
    $chk = db()->prepare('SELECT 1 FROM tt_photos WHERE id = ? AND user_id = ?');
    $chk->bind_param('ii', $photoId, $userId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { fail('Photo not found'); }
    $chk->close();
  }
  $stmt = db()->prepare('UPDATE tt_temples SET primary_photo_id = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('iii', $photoId, $templeId, $userId);
  $stmt->execute();
  $stmt->close();
}

function setVisitCoverPhoto(int $userId, int $visitId, ?int $photoId): void {
  if ($photoId !== null) {
    $chk = db()->prepare('SELECT 1 FROM tt_photos WHERE id = ? AND user_id = ?');
    $chk->bind_param('ii', $photoId, $userId);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { fail('Photo not found'); }
    $chk->close();
  }
  $stmt = db()->prepare('UPDATE tt_visits SET cover_photo_id = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('iii', $photoId, $visitId, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- Sharing (see TempleTime.md 18.1) ----

const SHARE_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L -- avoids misreads when typed by hand
const SHARE_CODE_LENGTH = 8;
const SHARE_CODE_LIFETIME_HOURS = 48;

function genShareCode(): string {
  $alphabet = SHARE_CODE_ALPHABET;
  $max = strlen($alphabet) - 1;
  for ($attempt = 0; $attempt < 20; $attempt++) {
    $code = '';
    for ($i = 0; $i < SHARE_CODE_LENGTH; $i++) {
      $code .= $alphabet[random_int(0, $max)];
    }
    $chk = db()->prepare('SELECT 1 FROM tt_share_codes WHERE code = ?');
    $chk->bind_param('s', $code);
    $chk->execute();
    $taken = (bool)$chk->get_result()->fetch_row();
    $chk->close();
    if (!$taken) { return $code; }
  }
  fail('Could not generate a share code, please try again.', 500);
}

// One active code per user -- creating a new one replaces any existing one.
function createShareCode(int $userId, bool $includePlans): array {
  $conn = db();
  $del = $conn->prepare('DELETE FROM tt_share_codes WHERE user_id = ?');
  $del->bind_param('i', $userId);
  $del->execute();
  $del->close();

  $code = genShareCode();
  $hours = SHARE_CODE_LIFETIME_HOURS;
  $stmt = $conn->prepare(
    'INSERT INTO tt_share_codes (code, user_id, include_plans, expires_at)
     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
  );
  $includePlansInt = $includePlans ? 1 : 0;
  $stmt->bind_param('siii', $code, $userId, $includePlansInt, $hours);
  $stmt->execute();
  $stmt->close();

  return ['code' => $code, 'includePlans' => $includePlans, 'lifetimeHours' => $hours];
}

function myShareCode(int $userId): ?array {
  $stmt = db()->prepare('SELECT code, include_plans, expires_at FROM tt_share_codes WHERE user_id = ? AND expires_at > NOW()');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) { return null; }
  return ['code' => $row['code'], 'includePlans' => (bool)$row['include_plans'], 'expiresAt' => $row['expires_at']];
}

function cancelShareCode(int $userId): void {
  $stmt = db()->prepare('DELETE FROM tt_share_codes WHERE user_id = ?');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $stmt->close();
}

// Physically copies a Photo's two files to a fresh filename pair and
// inserts a new tt_photos row owned by $newUserId, attached to
// $newTempleId. Each user ends up with their own independent copy of the
// image bytes -- simpler and safer than reference-counting a shared file
// across two users' rows (see TempleTime.md 18.1). Returns the new photo's
// id, or null if PHOTO_UPLOAD_DIR isn't configured or the source files are
// missing -- either way the caller just proceeds without a Primary Photo
// rather than failing the whole import over a missing picture.
function copyPhotoForShare(int $sourcePhotoId, int $newUserId, int $newTempleId): ?int {
  if (!photosConfigured()) { return null; }

  $stmt = db()->prepare('SELECT image_path, thumb_path, caption, original_filename FROM tt_photos WHERE id = ?');
  $stmt->bind_param('i', $sourcePhotoId);
  $stmt->execute();
  $src = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$src) { return null; }

  $dir = rtrim(PHOTO_UPLOAD_DIR, '/');
  $token = bin2hex(random_bytes(16));
  $newImagePath = $token . '.jpg';
  $newThumbPath = $token . '_thumb.jpg';

  if (!@copy($dir . '/' . $src['image_path'], $dir . '/' . $newImagePath)) { return null; }
  if (!@copy($dir . '/' . $src['thumb_path'], $dir . '/' . $newThumbPath)) {
    @unlink($dir . '/' . $newImagePath);
    return null;
  }

  $stmt = db()->prepare(
    'INSERT INTO tt_photos (user_id, temple_id, image_path, thumb_path, original_filename, caption)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param('iissss', $newUserId, $newTempleId, $newImagePath, $newThumbPath, $src['original_filename'], $src['caption']);
  $stmt->execute();
  $newId = $stmt->insert_id;
  $stmt->close();
  return $newId;
}

function importFromShareCode(int $recipientUserId, string $code): array {
  $conn = db();

  $stmt = $conn->prepare('SELECT user_id, include_plans FROM tt_share_codes WHERE code = ? AND expires_at > NOW()');
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $share = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$share) { fail('That share code is invalid or has expired.'); }

  $sharerUserId = (int)$share['user_id'];
  $includePlans = (bool)$share['include_plans'];
  if ($sharerUserId === $recipientUserId) { fail("You can't import your own share code."); }

  $addedTemples = 0;
  $skippedTemples = 0;
  $addedPlans = 0;

  $conn->begin_transaction();
  try {
    // Recipient's existing Temple names, for exact-match dedup.
    $stmt = $conn->prepare('SELECT id, name FROM tt_temples WHERE user_id = ?');
    $stmt->bind_param('i', $recipientUserId);
    $stmt->execute();
    $existingByName = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $existingByName[$r['name']] = (int)$r['id']; }
    $stmt->close();

    $stmt = $conn->prepare('SELECT * FROM tt_temples WHERE user_id = ?');
    $stmt->bind_param('i', $sharerUserId);
    $stmt->execute();
    $sharerTemples = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Maps the sharer's temple_id -> the recipient's temple_id (whether
    // newly created or an existing exact-name match), so shared Plans
    // always have somewhere to attach.
    $templeIdMap = [];

    foreach ($sharerTemples as $t) {
      if (isset($existingByName[$t['name']])) {
        $templeIdMap[$t['id']] = $existingByName[$t['name']];
        $skippedTemples++;
        continue;
      }

      $ins = $conn->prepare(
        'INSERT INTO tt_temples (user_id, name, short_name, status, street_address, address_line2,
           city, state_region, postal_code, country, latitude, longitude, phone, website, notes,
           favorite, on_visit_list)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0)'
      );
      $ins->bind_param(
        'isssssssssddsss',
        $recipientUserId, $t['name'], $t['short_name'], $t['status'], $t['street_address'], $t['address_line2'],
        $t['city'], $t['state_region'], $t['postal_code'], $t['country'], $t['latitude'], $t['longitude'],
        $t['phone'], $t['website'], $t['notes']
      );
      $ins->execute();
      $newTempleId = $ins->insert_id;
      $ins->close();

      $templeIdMap[$t['id']] = $newTempleId;
      $addedTemples++;

      if ($t['primary_photo_id'] !== null) {
        $newPhotoId = copyPhotoForShare((int)$t['primary_photo_id'], $recipientUserId, $newTempleId);
        if ($newPhotoId !== null) {
          $upd = $conn->prepare('UPDATE tt_temples SET primary_photo_id = ? WHERE id = ?');
          $upd->bind_param('ii', $newPhotoId, $newTempleId);
          $upd->execute();
          $upd->close();
        }
      }
    }

    if ($includePlans) {
      $stmt = $conn->prepare("SELECT * FROM tt_plans WHERE user_id = ? AND status = 'Planned'");
      $stmt->bind_param('i', $sharerUserId);
      $stmt->execute();
      $sharerPlans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();

      foreach ($sharerPlans as $p) {
        if (!isset($templeIdMap[$p['temple_id']])) { continue; } // defensive -- every sharer Temple was just mapped above
        $newTempleId = $templeIdMap[$p['temple_id']];

        $ins = $conn->prepare(
          "INSERT INTO tt_plans (user_id, temple_id, planned_date, planned_time, end_time, group_name, notes, status, appointment_scheduled)
           VALUES (?,?,?,?,?,?,?,'Planned',0)"
        );
        $ins->bind_param(
          'iisssss',
          $recipientUserId, $newTempleId, $p['planned_date'], $p['planned_time'], $p['end_time'], $p['group_name'], $p['notes']
        );
        $ins->execute();
        $newPlanId = $ins->insert_id;
        $ins->close();

        $srcPurposes = $conn->prepare('SELECT purpose FROM tt_plan_purposes WHERE plan_id = ?');
        $srcPurposes->bind_param('i', $p['id']);
        $srcPurposes->execute();
        $purposeRes = $srcPurposes->get_result();
        $insP = $conn->prepare('INSERT INTO tt_plan_purposes (plan_id, purpose) VALUES (?, ?)');
        while ($pr = $purposeRes->fetch_assoc()) {
          $insP->bind_param('is', $newPlanId, $pr['purpose']);
          $insP->execute();
        }
        $srcPurposes->close();

        $srcWork = $conn->prepare('SELECT work_type FROM tt_plan_work WHERE plan_id = ?');
        $srcWork->bind_param('i', $p['id']);
        $srcWork->execute();
        $workRes = $srcWork->get_result();
        $insW = $conn->prepare('INSERT INTO tt_plan_work (plan_id, work_type) VALUES (?, ?)');
        while ($wr = $workRes->fetch_assoc()) {
          $insW->bind_param('is', $newPlanId, $wr['work_type']);
          $insW->execute();
        }
        $srcWork->close();

        // Who With is intentionally not copied -- the recipient's People
        // table is a separate, personal roster (TempleTime.md 18.1).
        $addedPlans++;
      }
    }

    $del = $conn->prepare('DELETE FROM tt_share_codes WHERE code = ?');
    $del->bind_param('s', $code);
    $del->execute();
    $del->close();

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    fail('Could not import that share code: ' . $e->getMessage(), 500);
  }

  return ['addedTemples' => $addedTemples, 'skippedTemples' => $skippedTemples, 'includePlans' => $includePlans, 'addedPlans' => $addedPlans];
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
// A photo upload (addPhoto) comes in as multipart/form-data, so its action
// and fields live in $_POST, not a JSON body.
$isMultipart = $method === 'POST'
  && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
$body = ($method === 'POST' && !$isMultipart) ? jsonBody() : [];
$action = $method === 'GET'
  ? ($_GET['action'] ?? '')
  : ($isMultipart ? ($_POST['action'] ?? '') : ($body['action'] ?? ''));

switch ($action) {

  case 'vocab': {
    respond(['ok' => true, 'statuses' => STATUSES, 'relationships' => RELATIONSHIPS,
      'purposes' => PURPOSES, 'workTypes' => WORK_TYPES, 'planStatuses' => PLAN_STATUSES]);
  }

  // -- Temples --

  case 'temples': {
    $user = requireMember();
    respond(['ok' => true, 'temples' => templesForUser((int)$user['id'])]);
  }

  case 'addTemple': {
    $user = requireMember();
    $id = saveTemple((int)$user['id'], $body, null);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateTemple': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing temple id'); }
    saveTemple((int)$user['id'], $body, $id);
    respond(['ok' => true]);
  }

  case 'deleteTemple': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing temple id'); }
    deleteTemple((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  // -- People --

  case 'people': {
    $user = requireMember();
    respond(['ok' => true, 'people' => peopleForUser((int)$user['id'])]);
  }

  case 'addPerson': {
    $user = requireMember();
    $id = savePerson((int)$user['id'], $body, null);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updatePerson': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing person id'); }
    savePerson((int)$user['id'], $body, $id);
    respond(['ok' => true]);
  }

  case 'deletePerson': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing person id'); }
    deletePerson((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  case 'setPersonPhoto': {
    $user = requireMember();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { fail('Missing person id'); }
    setPersonPhoto((int)$user['id'], $id, $_FILES);
    respond(['ok' => true]);
  }

  case 'removePersonPhoto': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing person id'); }
    removePersonPhoto((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  // -- Visits --

  case 'visits': {
    $user = requireMember();
    $templeId = isset($_GET['templeId']) ? (int)$_GET['templeId'] : null;
    respond(['ok' => true, 'visits' => visitsForUser((int)$user['id'], $templeId)]);
  }

  case 'addVisit': {
    $user = requireMember();
    $id = saveVisit((int)$user['id'], $body, null);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateVisit': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing visit id'); }
    saveVisit((int)$user['id'], $body, $id);
    respond(['ok' => true]);
  }

  case 'deleteVisit': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing visit id'); }
    deleteVisit((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  // -- Plans --

  case 'plans': {
    $user = requireMember();
    respond(['ok' => true, 'plans' => plansForUser((int)$user['id'])]);
  }

  case 'addPlan': {
    $user = requireMember();
    $id = savePlan((int)$user['id'], $body, null);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updatePlan': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing plan id'); }
    savePlan((int)$user['id'], $body, $id);
    respond(['ok' => true]);
  }

  case 'cancelPlan': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing plan id'); }
    setPlanStatus((int)$user['id'], $id, 'Cancelled');
    respond(['ok' => true]);
  }

  case 'deletePlan': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing plan id'); }
    deletePlan((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  // -- Photos --

  case 'photosConfigured': {
    respond(['ok' => true, 'configured' => photosConfigured()]);
  }

  case 'photos': {
    $user = requireMember();
    respond(['ok' => true, 'photos' => photosForUser((int)$user['id'])]);
  }

  case 'addPhoto': {
    $user = requireMember();
    $id = uploadPhoto((int)$user['id'], $_POST, $_FILES);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updatePhoto': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing photo id'); }
    updatePhoto((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'deletePhoto': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing photo id'); }
    deletePhoto((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  case 'setTemplePrimaryPhoto': {
    $user = requireMember();
    $templeId = (int)($body['templeId'] ?? 0);
    if ($templeId <= 0) { fail('Missing temple id'); }
    $photoId = isset($body['photoId']) && $body['photoId'] !== null ? (int)$body['photoId'] : null;
    setTemplePrimaryPhoto((int)$user['id'], $templeId, $photoId);
    respond(['ok' => true]);
  }

  case 'setVisitCoverPhoto': {
    $user = requireMember();
    $visitId = (int)($body['visitId'] ?? 0);
    if ($visitId <= 0) { fail('Missing visit id'); }
    $photoId = isset($body['photoId']) && $body['photoId'] !== null ? (int)$body['photoId'] : null;
    setVisitCoverPhoto((int)$user['id'], $visitId, $photoId);
    respond(['ok' => true]);
  }

  // -- Sharing --

  case 'myShareCode': {
    $user = requireMember();
    respond(['ok' => true, 'share' => myShareCode((int)$user['id'])]);
  }

  case 'createShareCode': {
    $user = requireMember();
    $includePlans = !empty($body['includePlans']);
    respond(['ok' => true] + createShareCode((int)$user['id'], $includePlans));
  }

  case 'cancelShareCode': {
    $user = requireMember();
    cancelShareCode((int)$user['id']);
    respond(['ok' => true]);
  }

  case 'importFromShareCode': {
    $user = requireMember();
    $code = strtoupper(trim((string)($body['code'] ?? '')));
    if ($code === '') { fail('Enter a share code.'); }
    respond(['ok' => true] + importFromShareCode((int)$user['id'], $code));
  }

  // -- MyDataWorld login (shared with the other apps) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  case 'whoAmI': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') { respond(['ok' => false]); }
    $stmt = db()->prepare(
      'SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    respond($row ? ['ok' => true, 'email' => $row['username']] : ['ok' => false]);
  }

  case 'checkAccess': {
    $user = requireUser();
    if (!hasAppAccess($user)) {
      fail('Not authorized for Temple Time', 403);
    }
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
