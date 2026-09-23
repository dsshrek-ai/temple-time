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
    "SELECT t.*,
       (SELECT COUNT(*) FROM tt_visits v WHERE v.temple_id = t.id) AS visit_count,
       (SELECT MIN(v.visit_date) FROM tt_visits v WHERE v.temple_id = t.id) AS first_visit_date,
       (SELECT MAX(v.visit_date) FROM tt_visits v WHERE v.temple_id = t.id) AS last_visit_date,
       EXISTS(
         SELECT 1 FROM tt_visits v
         JOIN tt_visit_purposes p ON p.visit_id = v.id
         WHERE v.temple_id = t.id AND p.purpose = 'Temple Grounds'
       ) AS grounds_visited
     FROM tt_temples t WHERE t.user_id = ? ORDER BY t.name"
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
  $stmt = db()->prepare('DELETE FROM tt_people WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
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
  $sql = "SELECT v.*, t.name AS temple_name, t.city AS temple_city, t.state_region AS temple_state
          FROM tt_visits v JOIN tt_temples t ON t.id = v.temple_id
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

  $conn = db();
  $conn->begin_transaction();
  try {
    if ($id === null) {
      $stmt = $conn->prepare(
        'INSERT INTO tt_visits (user_id, temple_id, visit_date, arrival_time, departure_time,
           group_name, notes, spiritual_impressions, memorable_experiences, people_encountered, favorite_visit)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
      );
      $stmt->bind_param(
        'iissssssssi', $userId, $templeId, $date, $arrival, $departure, $group, $notes,
        $spiritual, $memorable, $encountered, $favorite
      );
      $stmt->execute();
      $id = $stmt->insert_id;
      $stmt->close();
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

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  case 'vocab': {
    respond(['ok' => true, 'statuses' => STATUSES, 'relationships' => RELATIONSHIPS,
      'purposes' => PURPOSES, 'workTypes' => WORK_TYPES]);
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
