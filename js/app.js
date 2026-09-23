// Shared helpers used across every Temple Time page. Requires js/api.js to be
// loaded first (escapeHtml, fetchData, postAction, captureSso, handleError, etc).

const NAV_ITEMS = [
  { href: 'index.html', label: 'Dashboard', built: true },
  { href: 'temples.html', label: 'Temples', built: true },
  { href: 'visits.html', label: 'Visits', built: true },
  { href: 'people.html', label: 'People', built: true },
  { href: 'plans.html', label: 'Plans', built: true },
  { href: 'photos.html', label: 'Photos', built: true },
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

function todayStr() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

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

function thumbTagHtml(url, alt) {
  // No loading="lazy" -- iOS Safari has a known bug where a page with
  // several lazy-loaded images (a grid of card thumbnails is exactly this
  // shape) can miscalculate the scrollable page height, so the page stops
  // scrolling partway down and cuts off real content below. These are
  // already small pre-resized (~450px) thumbnails, so eager loading them
  // costs little.
  return url
    ? `<img class="thumb" src="${escapeHtml(url)}" alt="${escapeHtml(alt || '')}">`
    : `<div class="thumb"></div>`;
}

function templeCardHtml(t) {
  const loc = [t.City, t.StateRegion].filter(Boolean).join(', ');
  const badges = [];
  if (t.Favorite) badges.push('★ Favorite');
  if (t.OnVisitList) badges.push('On Visit List');
  return `
    <a class="click-card" href="temple.html?id=${t.Id}">
      ${thumbTagHtml(t.PrimaryPhotoThumbUrl, t.Name)}
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
      ${thumbTagHtml(v.CoverPhotoThumbUrl, v.TempleName)}
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

function planCardHtml(p) {
  const loc = [p.TempleCity, p.TempleState].filter(Boolean).join(', ');
  const bits = [];
  const who = whoWithSummary(p.WhoWith);
  if (who) bits.push(who);
  const purpose = purposeSummary(p.Purposes);
  if (purpose) bits.push(purpose);
  const work = workShorthand(p.WorkPerformed);
  const when = p.PlannedTime ? `${formatDate(p.PlannedDate)} · ${formatTime(p.PlannedTime)}` : formatDate(p.PlannedDate);
  return `
    <a class="click-card" href="plan.html?id=${p.Id}">
      ${thumbTagHtml(p.TemplePrimaryPhotoThumbUrl, p.TempleName)}
      <div class="body">
        <p class="title">${escapeHtml(p.TempleName)}</p>
        <p class="meta">${escapeHtml(when)}${loc ? ' · ' + escapeHtml(loc) : ''}</p>
        <p class="meta">${escapeHtml(bits.join(' · '))}${work ? ` <span class="work-shorthand">${escapeHtml(work)}</span>` : ''}</p>
        ${p.Status !== 'Planned'
          ? `<p class="meta badges">${escapeHtml(p.Status)}</p>`
          : (p.AppointmentScheduled
              ? `<p class="meta badges">✓ Scheduled</p>`
              : `<p class="meta" style="color:#a33"><strong>⚠ Not Yet Scheduled</strong></p>`)}
      </div>
    </a>`;
}

// Adapts a Plan's Temple* fields (TempleName/TempleLatitude/...) to the
// {Name, Latitude, StreetAddress, ...} shape mapsUrl() expects (which
// matches a Temple record's own field names).
function planTempleForMaps(p) {
  return {
    Name: p.TempleName, Latitude: p.TempleLatitude, Longitude: p.TempleLongitude,
    StreetAddress: p.TempleStreetAddress, City: p.TempleCity, StateRegion: p.TempleState,
    PostalCode: p.TemplePostalCode, Country: p.TempleCountry,
  };
}

// ---- Add to Google Calendar (quick-add link, no OAuth) ----
//
// This has been through two wrong turns before landing here -- worth
// recording so a future "let's just try X" doesn't re-walk them:
//
// 1. Original: this same plain quick-add URL. Reported as producing no
//    event ("opens to today's view, nothing pre-filled"). Assumed to be
//    iOS/Android intercepting the calendar.google.com link via the Google
//    Calendar app (Universal Links) and not understanding the web-only
//    "action=TEMPLATE" query scheme.
// 2. Switched to a generic .ics file, first as a `data:text/calendar,...`
//    URI, then (when recent iOS Safari turned out to block top-level
//    data:-URI navigation outright) as a Blob downloaded via a
//    `download`-attributed link click.
//
// Both replacements were solving the wrong problem. Comparing against
// Life Tempo (life-tempo/js/api.js gcalUrl()), which uses this exact same
// plain quick-add link and works fine on the same device, proved the link
// mechanism itself was never the issue. The real bug: the default-duration
// fallback below used to compute the end hour as plain `startHour + 2`
// with no rollover handling -- for a Plan starting at, say, 10pm with no
// End Time set, that produced an invalid hour like "24" or "25" in the
// `dates` param, which Google Calendar silently ignores rather than
// erroring, showing today's view with nothing filled in. Fixed by doing
// real Date-object arithmetic (start.getTime() + durationMs) instead,
// matching how Life Tempo's gcalUrl() computes its default end time --
// Date math correctly rolls hour overflow into the next day.
//
// Dates are floating local time (no timezone suffix), matching how
// Planned Time was entered -- Temple Time doesn't track a timezone for
// any Temple.

function calendarEventUrl(plan) {
  const [y, m, d] = plan.PlannedDate.split('-').map(Number);
  let start, end, allDay = false;
  if (plan.PlannedTime) {
    const [sh, sm] = plan.PlannedTime.split(':').map(Number);
    start = new Date(y, m - 1, d, sh, sm, 0);
    if (plan.EndTime) {
      const [eh, em] = plan.EndTime.split(':').map(Number);
      end = new Date(y, m - 1, d, eh, em, 0);
    } else {
      end = new Date(start.getTime() + 2 * 60 * 60 * 1000); // default 2-hour duration
    }
  } else {
    // All-day event -- Google's end date is exclusive, so use the next day.
    allDay = true;
    start = new Date(y, m - 1, d);
    end = new Date(y, m - 1, d + 1);
  }

  const pad = n => String(n).padStart(2, '0');
  const fmtDate = dt => `${dt.getFullYear()}${pad(dt.getMonth() + 1)}${pad(dt.getDate())}`;
  const fmtDateTime = dt => `${fmtDate(dt)}T${pad(dt.getHours())}${pad(dt.getMinutes())}00`;
  const dates = allDay ? `${fmtDate(start)}/${fmtDate(end)}` : `${fmtDateTime(start)}/${fmtDateTime(end)}`;

  const details = [];
  details.push(`Temple: ${plan.TempleName}`);
  if ((plan.Purposes || []).length) details.push(`Purpose: ${plan.Purposes.join(', ')}`);
  if ((plan.WorkPerformed || []).length) details.push(`Planned Work: ${plan.WorkPerformed.join(', ')}`);
  if ((plan.WhoWith || []).length) details.push(`With: ${plan.WhoWith.map(x => x.Name).join(', ')}`);
  if (plan.Notes) details.push(plan.Notes);

  const addr = [plan.TempleStreetAddress, plan.TempleCity, plan.TempleState, plan.TemplePostalCode, plan.TempleCountry]
    .filter(Boolean).join(', ');

  const params = new URLSearchParams({ action: 'TEMPLATE', text: `Temple - ${plan.TempleName}`, dates });
  if (addr) params.set('location', addr);
  if (details.length) params.set('details', details.join('\n'));
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
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
