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

async function generateMemoryBook({ visits, allPhotos, title, subtitle }) {
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

  function addText(text, { size = 11, style = 'normal', color = [40, 36, 30], gapAfter = 10 } = {}) {
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
    addText(label, { size: 11, style: 'bold', color: [138, 109, 59], gapAfter: 3 });
    addText(text, { size: 10.5, gapAfter: 12 });
  }

  // ---- Title page ----
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(26);
  doc.setTextColor(95, 74, 38);
  const titleLines = doc.splitTextToSize(title, contentWidth);
  let ty = pageHeight / 2 - (titleLines.length * 32) / 2 - 20;
  titleLines.forEach(line => { doc.text(line, pageWidth / 2, ty, { align: 'center' }); ty += 32; });
  if (subtitle) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(13);
    doc.setTextColor(100, 95, 85);
    doc.text(subtitle, pageWidth / 2, ty + 14, { align: 'center' });
  }
  doc.setFontSize(9.5);
  doc.setTextColor(160, 155, 145);
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
      doc.setDrawColor(205, 195, 175);
      doc.setLineWidth(0.75);
      doc.line(BOOK_MARGIN, y, pageWidth - BOOK_MARGIN, y);
      y += 20;
    }
    ensureSpace(90); // room for the heading + date line + first meta line, so a lone heading doesn't get orphaned at a page's bottom

    addText(v.TempleName, { size: 18, style: 'bold', color: [95, 74, 38], gapAfter: 4 });
    const loc = [v.TempleCity, v.TempleState].filter(Boolean).join(', ');
    addText(`${formatDate(v.VisitDate)}${loc ? ' · ' + loc : ''}${v.FavoriteVisit ? '  ★' : ''}`, { size: 11, style: 'italic', color: [110, 105, 95], gapAfter: 10 });

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
    metaBits.forEach(line => addText(line, { size: 10.5, color: [70, 65, 58], gapAfter: 3 }));
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
