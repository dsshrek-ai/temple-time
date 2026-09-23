// Shared helpers used across every Temple Time page. Requires js/api.js to be
// loaded first (escapeHtml, fetchData, postAction, captureSso, handleError, etc).

const NAV_ITEMS = [
  { href: 'index.html', label: 'Dashboard', built: true },
  { href: 'temples.html', label: 'Temples', built: true },
  { href: 'visits.html', label: 'Visits', built: true },
  { href: 'people.html', label: 'People', built: true },
  { href: 'plans.html', label: 'Plans', built: false },
  { href: 'photos.html', label: 'Photos', built: false },
  { href: 'stats.html', label: 'Statistics', built: false },
];

function renderNav(activeHref) {
  const nav = document.getElementById('site-nav');
  if (!nav) return;
  nav.innerHTML = NAV_ITEMS.map(item => {
    if (!item.built) {
      return `<span class="nav-soon" title="Coming in a later phase">${escapeHtml(item.label)}</span>`;
    }
    const current = item.href === activeHref ? ' aria-current="page"' : '';
    return `<a href="${item.href}"${current}>${escapeHtml(item.label)}</a>`;
  }).join('');
}

// ---- Vocab (server is the source of truth for these lists) ----

let _vocabCache = null;
async function getVocab() {
  if (_vocabCache) return _vocabCache;
  const res = await fetchData('vocab');
  _vocabCache = res;
  return res;
}

// Canonical work-type order drives both the checkbox list and the shorthand
// string, e.g. Baptisms+Initiatory+Endowment -> "BIE", never "EIB".
const WORK_SHORTHAND = {
  'Baptisms': 'B', 'Confirmations': 'C', 'Initiatory': 'I',
  'Endowment': 'E', 'Sealing': 'S', 'Other': 'O',
};

function workShorthand(workTypes) {
  if (!workTypes || !workTypes.length) return '';
  return Object.keys(WORK_SHORTHAND)
    .filter(w => workTypes.includes(w))
    .map(w => WORK_SHORTHAND[w])
    .join('');
}

function purposeSummary(purposes) {
  if (!purposes || !purposes.length) return '';
  if (purposes.length === 1) return purposes[0];
  return `${purposes[0]} +${purposes.length - 1}`;
}

// ---- Formatting ----

function formatDate(dateStr) {
  if (!dateStr) return '';
  const [y, m, d] = dateStr.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  return dt.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
}

function formatTime(timeStr) {
  if (!timeStr) return '';
  const [h, m] = timeStr.split(':').map(Number);
  const dt = new Date(2000, 0, 1, h, m);
  return dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function whoWithSummary(whoWith) {
  if (!whoWith || !whoWith.length) return '';
  const names = whoWith.map(p => p.Name);
  if (names.length <= 2) return names.join(' & ');
  return `${names[0]} +${names.length - 1}`;
}

// ---- Cards ----

function templeCardHtml(t) {
  const loc = [t.City, t.StateRegion].filter(Boolean).join(', ');
  const badges = [];
  if (t.Favorite) badges.push('★ Favorite');
  if (t.OnVisitList) badges.push('On Visit List');
  return `
    <a class="click-card" href="temple.html?id=${t.Id}">
      <div class="thumb"></div>
      <div class="body">
        <p class="title">${escapeHtml(t.Name)}</p>
        <p class="meta">${escapeHtml(loc)}</p>
        <p class="meta">${t.VisitCount} visit${t.VisitCount === 1 ? '' : 's'}${t.LastVisitDate ? ' · last ' + escapeHtml(formatDate(t.LastVisitDate)) : ''}</p>
        ${badges.length ? `<p class="meta badges">${badges.map(escapeHtml).join(' · ')}</p>` : ''}
      </div>
    </a>`;
}

function visitCardHtml(v) {
  const loc = [v.TempleCity, v.TempleState].filter(Boolean).join(', ');
  const bits = [];
  const who = whoWithSummary(v.WhoWith);
  if (who) bits.push(who);
  const purpose = purposeSummary(v.Purposes);
  if (purpose) bits.push(purpose);
  const work = workShorthand(v.WorkPerformed);
  return `
    <a class="click-card" href="visit.html?id=${v.Id}">
      <div class="thumb"></div>
      <div class="body">
        <p class="title">${escapeHtml(v.TempleName)}${v.FavoriteVisit ? ' ★' : ''}</p>
        <p class="meta">${escapeHtml(formatDate(v.VisitDate))}${loc ? ' · ' + escapeHtml(loc) : ''}</p>
        <p class="meta">${escapeHtml(bits.join(' · '))}${work ? ` <span class="work-shorthand">${escapeHtml(work)}</span>` : ''}</p>
      </div>
    </a>`;
}

function personCardHtml(p) {
  const name = p.DisplayName || [p.FirstName, p.LastName].filter(Boolean).join(' ');
  return `
    <a class="click-card" href="person.html?id=${p.Id}">
      <div class="thumb"></div>
      <div class="body">
        <p class="title">${escapeHtml(name)}${!p.Active ? ' (Inactive)' : ''}</p>
        <p class="meta">${escapeHtml(p.Relationship || '')}</p>
        <p class="meta">${p.VisitsTogether} visit${p.VisitsTogether === 1 ? '' : 's'} together · ${p.TemplesTogether} temple${p.TemplesTogether === 1 ? '' : 's'}</p>
      </div>
    </a>`;
}

function statRowHtml(stats) {
  return `<div class="stat-row">${stats.map(s => `
    <div class="stat-card"><div class="num">${s.num}</div><div class="label">${escapeHtml(s.label)}</div></div>
  `).join('')}</div>`;
}

function emptyStateHtml(title, message, actionHtml) {
  return `
    <div class="empty-state card">
      <h3>${escapeHtml(title)}</h3>
      <p>${escapeHtml(message)}</p>
      ${actionHtml || ''}
    </div>`;
}

// ---- Streak (consecutive weeks, week starts Sunday) ----

function startOfWeek(date) {
  const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  d.setDate(d.getDate() - d.getDay());
  return d;
}

function currentWeeklyStreak(visitDates) {
  if (!visitDates.length) return 0;
  const weekStarts = new Set(visitDates.map(ds => {
    const [y, m, d] = ds.split('-').map(Number);
    return startOfWeek(new Date(y, m - 1, d)).getTime();
  }));
  let streak = 0;
  let cursor = startOfWeek(new Date());
  while (weekStarts.has(cursor.getTime())) {
    streak++;
    cursor = new Date(cursor);
    cursor.setDate(cursor.getDate() - 7);
  }
  return streak;
}

// ---- Google Maps ----

function mapsUrl(t) {
  if (t.Latitude != null && t.Longitude != null) {
    return `https://www.google.com/maps/search/?api=1&query=${t.Latitude},${t.Longitude}`;
  }
  const addr = [t.StreetAddress, t.City, t.StateRegion, t.PostalCode, t.Country].filter(Boolean).join(', ');
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(addr || t.Name)}`;
}

// ---- Page bootstrap ----
// Every page calls this first: handles SSO capture + the login/access gate.
// Returns true if the caller should proceed to load its own data.
async function initPage(activeHref) {
  renderNav(activeHref);
  await captureSso();
  const logoutBtn = document.getElementById('logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      const token = getToken();
      clearToken();
      try { await postAction('logout', { token }); } catch (e) { /* best-effort */ }
      window.location.href = 'index.html';
    });
  }
  return true;
}
