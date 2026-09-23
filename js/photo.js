// Photo upload + rendering helpers. Requires js/api.js and js/app.js.
//
// Resizing happens here, client-side, via a canvas -- not on the server.
// Loading the source file into an <img> and drawing it lets the browser do
// the decode, which is what lets this handle HEIC/HEIF (iPhone's default
// photo format) even though there's no HEIC decoder on the PHP side: the
// canvas is always re-encoded as JPEG regardless of the source format. The
// server (api.php's resizeToJpeg) re-resizes again to actually enforce the
// 1600x1600 / thumbnail caps, so this client-side pass is about producing a
// small enough upload and universal format, not the source of truth for size.

function resizeImageFile(file, maxSide, quality) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
      const w = Math.max(1, Math.round(img.naturalWidth * scale));
      const h = Math.max(1, Math.round(img.naturalHeight * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff'; // flatten any transparency before JPEG encode
      ctx.fillRect(0, 0, w, h);
      ctx.drawImage(img, 0, 0, w, h);
      canvas.toBlob(
        blob => (blob ? resolve(blob) : reject(new Error('Could not process that image.'))),
        'image/jpeg', quality
      );
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not read that image file.')); };
    img.src = url;
  });
}

async function uploadPhotoFile(file, opts) {
  const { templeId, visitId, caption, dateTaken } = opts || {};
  const [standardBlob, thumbBlob] = await Promise.all([
    resizeImageFile(file, 1600, 0.85),
    resizeImageFile(file, 500, 0.8),
  ]);
  const fd = new FormData();
  if (templeId) fd.set('templeId', String(templeId));
  if (visitId) fd.set('visitId', String(visitId));
  if (caption) fd.set('caption', caption);
  if (dateTaken) fd.set('dateTaken', dateTaken);
  fd.set('originalFilename', file.name);
  fd.set('standard', standardBlob, 'standard.jpg');
  fd.set('thumb', thumbBlob, 'thumb.jpg');
  return postForm('addPhoto', fd);
}

// Renders a "+ Add Photo(s)" control into `container`. Attaches itself to
// either a Temple or a Visit (or both, when called from a Visit -- the
// server attaches the Visit's Temple automatically). Calls
// opts.onUploaded(result) once per file as it finishes, so the caller can
// refresh its list incrementally rather than waiting for the whole batch.
function renderPhotoUploader(container, opts) {
  // Read templeId/visitId lazily (opts.templeId, not a destructured copy) so
  // a caller can hand in a getter tied to a live <select> -- e.g. Photos
  // Gallery's "which Temple" dropdown -- and have each upload batch use
  // whatever is currently selected, not whatever was selected when this
  // widget was first rendered.
  opts = opts || {};
  const { onUploaded, onError } = opts;
  container.innerHTML = `
    <label class="btn secondary photo-upload-label">
      + Add Photo
      <input type="file" accept="image/*" multiple class="photo-upload-input">
    </label>
    <span class="meta photo-upload-status"></span>
  `;
  const input = container.querySelector('.photo-upload-input');
  const status = container.querySelector('.photo-upload-status');
  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    if (!files.length) return;
    let done = 0;
    status.textContent = `Uploading 0/${files.length}...`;
    for (const file of files) {
      try {
        const res = await uploadPhotoFile(file, { templeId: opts.templeId, visitId: opts.visitId });
        done++;
        status.textContent = `Uploading ${done}/${files.length}...`;
        if (onUploaded) await onUploaded(res);
      } catch (err) {
        if (onError) onError(file, err);
        else alert(`Could not upload ${file.name}: ${err.message}`);
      }
    }
    status.textContent = '';
    input.value = '';
  });
}

function photoCardHtml(p) {
  const meta = [p.TempleName, p.VisitDate ? formatDate(p.VisitDate) : null].filter(Boolean).join(' · ');
  return `
    <a class="click-card" href="photo.html?id=${p.Id}">
      <img class="thumb" src="${escapeHtml(p.ThumbUrl)}" alt="${escapeHtml(p.Caption || '')}">
      <div class="body">
        ${meta ? `<p class="meta">${escapeHtml(meta)}</p>` : ''}
        ${p.Caption ? `<p class="meta">${escapeHtml(p.Caption)}</p>` : ''}
        ${p.Favorite ? `<p class="meta badges">★ Favorite</p>` : ''}
      </div>
    </a>`;
}
