// Memory Book PDF export. Requires js/api.js + js/app.js (formatDate,
// formatTime, workShorthand) plus jsPDF loaded from CDN before this file
// (window.jspdf.jsPDF) -- see the <script> tag on each page that uses this.
//
// One shared builder (generateMemoryBook) feeds three entry points: Person
// Detail (Visits shared with that Person), Temple Detail (Visits to that
// Temple), and Statistics (Visits in the selected Reporting Period). Each
// caller already has its own filtered Visit list on hand, so this file
// only builds the PDF -- no new API calls, no new filtering logic.
//
// Photos need PHOTO_UPLOAD_DIR's public folder to send an
// Access-Control-Allow-Origin header (see SETUP.md "Phase 7") -- without
// it, the browser blocks reading the fetched image back out of the
// canvas, and this degrades gracefully by leaving that photo out of the
// book rather than failing the whole export.

const BOOK_MARGIN = 54; // 0.75in, in the 'pt' unit the PDF is built with

// PDF colors as [r, g, b], matching the app's royal blue palette in
// style.css (--primary-dark, --accent, etc).
const BOOK_COLORS = {
  heading: [28, 58, 143],   // --primary-dark
  label: [47, 95, 196],     // --accent
  text: [30, 37, 51],       // --text
  meta: [60, 68, 85],
  muted: [90, 100, 120],    // --muted
  faint: [150, 158, 172],
  rule: [188, 211, 247],    // --nav-active
  ring: [220, 231, 250],    // --light
};

function bookFileName(title) {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  return `${slug || 'temple-time-memories'}.pdf`;
}

// Fetches an image and returns a JPEG data URL (re-encoding through a
// canvas, same approach as the photo upload resize) plus its pixel size,
// so the caller can lay it out at the right aspect ratio. Resolves to null
// on any failure (missing CORS header, network error, etc.) rather than
// rejecting, so one bad photo doesn't abort the whole book.
function loadImageForBook(url) {
  return new Promise(resolve => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        canvas.getContext('2d').drawImage(img, 0, 0);
        resolve({ dataUrl: canvas.toDataURL('image/jpeg', 0.85), width: img.naturalWidth, height: img.naturalHeight });
      } catch (e) {
        resolve(null); // tainted canvas (no CORS header) or similar
      }
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

// Like loadImageForBook, but crops to a circle for a Person's portrait on
// the title page. The crop is biased toward the top (25%), the same as the
// .person-photo CSS crop, so faces aren't cut off. The area outside the
// circle is filled white to match the page, since JPEG has no transparency.
function loadPortraitForBook(url) {
  return new Promise(resolve => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const size = 600;
        const side = Math.min(img.naturalWidth, img.naturalHeight);
        const sx = (img.naturalWidth - side) / 2;
        const sy = (img.naturalHeight - side) * 0.25;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, size, size);
        ctx.beginPath();
        ctx.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(img, sx, sy, side, side, 0, 0, size, size);
        resolve(canvas.toDataURL('image/jpeg', 0.9));
      } catch (e) {
        resolve(null); // tainted canvas (no CORS header) or similar
      }
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

async function generateMemoryBook({ visits, allPhotos, title, subtitle, portraitUrl }) {
  if (!window.jspdf) {
    alert('The PDF library did not load -- check your connection and try again.');
    return;
  }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'pt', format: 'letter' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const contentWidth = pageWidth - BOOK_MARGIN * 2;
  let y = BOOK_MARGIN;

  function ensureSpace(h) {
    if (y + h > pageHeight - BOOK_MARGIN) {
      doc.addPage();
      y = BOOK_MARGIN;
    }
  }

  function addText(text, { size = 11, style = 'normal', color = BOOK_COLORS.text, gapAfter = 10 } = {}) {
    doc.setFont('helvetica', style);
    doc.setFontSize(size);
    doc.setTextColor(color[0], color[1], color[2]);
    const lines = doc.splitTextToSize(text, contentWidth);
    const lineHeight = size * 1.35;
    lines.forEach(line => {
      ensureSpace(lineHeight);
      doc.text(line, BOOK_MARGIN, y);
      y += lineHeight;
    });
    y += gapAfter;
  }

  function addJournalSection(label, text) {
    if (!text) return;
    ensureSpace(20);
    addText(label, { size: 11, style: 'bold', color: BOOK_COLORS.label, gapAfter: 3 });
    addText(text, { size: 10.5, gapAfter: 12 });
  }

  // ---- Title page ----
  // Optional portrait (Person books) sits centered above the title; the
  // whole portrait + title + subtitle block is centered vertically.
  const portrait = portraitUrl ? await loadPortraitForBook(portraitUrl) : null;
  const portraitSize = 170;
  const portraitGap = 36;
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(26);
  const titleLines = doc.splitTextToSize(title, contentWidth);
  const blockH = (portrait ? portraitSize + portraitGap : 0) + titleLines.length * 32 + (subtitle ? 28 : 0);
  let ty = pageHeight / 2 - blockH / 2 - 20;
  if (portrait) {
    const px = (pageWidth - portraitSize) / 2;
    doc.addImage(portrait, 'JPEG', px, ty, portraitSize, portraitSize);
    doc.setDrawColor(BOOK_COLORS.ring[0], BOOK_COLORS.ring[1], BOOK_COLORS.ring[2]);
    doc.setLineWidth(4);
    doc.circle(pageWidth / 2, ty + portraitSize / 2, portraitSize / 2, 'S');
    ty += portraitSize + portraitGap + 20; // + 20: text is drawn from its baseline
  } else {
    ty += 20;
  }
  doc.setTextColor(BOOK_COLORS.heading[0], BOOK_COLORS.heading[1], BOOK_COLORS.heading[2]);
  titleLines.forEach(line => { doc.text(line, pageWidth / 2, ty, { align: 'center' }); ty += 32; });
  if (subtitle) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(13);
    doc.setTextColor(BOOK_COLORS.muted[0], BOOK_COLORS.muted[1], BOOK_COLORS.muted[2]);
    doc.text(subtitle, pageWidth / 2, ty + 14, { align: 'center' });
  }
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9.5);
  doc.setTextColor(BOOK_COLORS.faint[0], BOOK_COLORS.faint[1], BOOK_COLORS.faint[2]);
  doc.text(`Generated ${new Date().toLocaleDateString()} by Temple Time`, pageWidth / 2, pageHeight - BOOK_MARGIN, { align: 'center' });

  if (!visits.length) {
    doc.addPage();
    y = BOOK_MARGIN;
    addText('No Visits to include in this Memory Book.', { size: 12 });
    doc.save(bookFileName(title));
    return;
  }

  const photosByVisit = {};
  (allPhotos || []).forEach(p => {
    if (!p.VisitId) return;
    (photosByVisit[p.VisitId] = photosByVisit[p.VisitId] || []).push(p);
  });

  // The Visit history starts on its own page after the title page; after
  // that, entries flow continuously rather than forcing a page break per
  // Visit -- a short entry no longer leaves the rest of the page empty. A
  // rule line separates consecutive entries that land on the same page;
  // ensureSpace() still starts a fresh page on its own once one is needed.
  doc.addPage();
  y = BOOK_MARGIN;

  const sorted = visits.slice().sort((a, b) => a.VisitDate.localeCompare(b.VisitDate));

  for (let i = 0; i < sorted.length; i++) {
    const v = sorted[i];

    if (i > 0) {
      ensureSpace(30);
      doc.setDrawColor(BOOK_COLORS.rule[0], BOOK_COLORS.rule[1], BOOK_COLORS.rule[2]);
      doc.setLineWidth(0.75);
      doc.line(BOOK_MARGIN, y, pageWidth - BOOK_MARGIN, y);
      y += 20;
    }
    ensureSpace(90); // room for the heading + date line + first meta line, so a lone heading doesn't get orphaned at a page's bottom

    addText(v.TempleName, { size: 18, style: 'bold', color: BOOK_COLORS.heading, gapAfter: 4 });
    const loc = [v.TempleCity, v.TempleState].filter(Boolean).join(', ');
    addText(`${formatDate(v.VisitDate)}${loc ? ' · ' + loc : ''}${v.FavoriteVisit ? '  ★' : ''}`, { size: 11, style: 'italic', color: BOOK_COLORS.muted, gapAfter: 10 });

    const metaBits = [];
    if ((v.WhoWith || []).length) metaBits.push(`Who With: ${v.WhoWith.map(p => p.Name).join(', ')}`);
    if (v.GroupName) metaBits.push(`Group: ${v.GroupName}`);
    if ((v.Purposes || []).length) metaBits.push(`Purpose: ${v.Purposes.join(', ')}`);
    if ((v.WorkPerformed || []).length) {
      const shorthand = workShorthand(v.WorkPerformed);
      metaBits.push(`Work Performed: ${v.WorkPerformed.join(', ')}${shorthand ? ' (' + shorthand + ')' : ''}`);
    }
    if (v.ArrivalTime) metaBits.push(`Arrival: ${formatTime(v.ArrivalTime)}`);
    if (v.DepartureTime) metaBits.push(`Departure: ${formatTime(v.DepartureTime)}`);
    metaBits.forEach(line => addText(line, { size: 10.5, color: BOOK_COLORS.meta, gapAfter: 3 }));
    if (metaBits.length) y += 8;

    addJournalSection('Notes', v.Notes);
    addJournalSection('Spiritual Impressions', v.SpiritualImpressions);
    addJournalSection('Memorable Experiences', v.MemorableExperiences);
    addJournalSection('People Encountered', v.PeopleEncountered);
    if ((v.Tags || []).length) addJournalSection('Tags', v.Tags.join(', '));

    const visitPhotos = photosByVisit[v.Id] || [];
    if (visitPhotos.length) {
      const cols = 2;
      const gap = 10;
      const cellW = (contentWidth - gap * (cols - 1)) / cols;
      const maxCellH = 150;
      for (let i = 0; i < visitPhotos.length; i += cols) {
        const row = visitPhotos.slice(i, i + cols);
        const loaded = await Promise.all(row.map(p => loadImageForBook(p.ImageUrl)));
        const rowH = Math.max(...loaded.map(img => (img ? Math.min(maxCellH, cellW * (img.height / img.width)) : 0)), 0);
        if (rowH === 0) continue; // every photo in this row failed to load
        ensureSpace(rowH + gap);
        row.forEach((p, idx) => {
          const img = loaded[idx];
          if (!img) return;
          const h = Math.min(maxCellH, cellW * (img.height / img.width));
          const w = h * (img.width / img.height);
          const x = BOOK_MARGIN + idx * (cellW + gap) + (cellW - w) / 2;
          doc.addImage(img.dataUrl, 'JPEG', x, y, w, h);
        });
        y += rowH + gap;
      }
    }
  }

  doc.save(bookFileName(title));
}

// Wires a button to generateMemoryBook with a "Generating..." busy state,
// since embedding several photos can take a few seconds.
function wireMemoryBookButton(buttonEl, getArgs) {
  buttonEl.addEventListener('click', async () => {
    const originalText = buttonEl.textContent;
    buttonEl.disabled = true;
    buttonEl.textContent = 'Generating...';
    try {
      await generateMemoryBook(getArgs());
    } catch (err) {
      alert('Could not generate the Memory Book. Please try again.');
    } finally {
      buttonEl.disabled = false;
      buttonEl.textContent = originalText;
    }
  });
}
