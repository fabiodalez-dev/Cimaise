import Uppy from '@uppy/core'
import DragDrop from '@uppy/drag-drop'
import XHRUpload from '@uppy/xhr-upload'

function initUppyDragDrop() {
  const area = document.getElementById('uppy');
  if (!area) return;
  const endpoint = area.dataset.endpoint;
  const csrf = area.dataset.csrf;
  const albumId = area.dataset.albumId;
  const uppy = new Uppy({ autoProceed: true, restrictions: { allowedFileTypes: ['image/*'] } })
    .use(DragDrop, { target: '#uppy', note: 'Trascina qui le immagini' })
    .use(XHRUpload, { endpoint, fieldName: 'file', headers: { 'X-CSRF-Token': csrf, 'Accept':'application/json' } });
  uppy.on('upload-success', (file, response) => {
    try {
      const data = response.body || JSON.parse(response.responseText);
      if (data && data.image && data.image.preview_url) {
        const grid = document.getElementById('images-grid');
        if (grid) {
          const col = document.createElement('div');
          col.className='col-6 col-md-3';
          col.setAttribute('data-id', data.image.id);
          col.innerHTML = `
            <div class="card position-relative">
              <div class="ratio ratio-1x1">
                <img src="${data.image.preview_url}" class="card-img-top object-fit-cover" alt="">
              </div>
              <div class="card-body p-2 d-flex justify-content-between">
                <button type="button" data-cover-id="${data.image.id}" class="btn btn-sm btn-outline-secondary">Cover</button>
                <span class="text-muted small">ID ${data.image.id}</span>
              </div>
            </div>`;
          grid.prepend(col);
        }
      }
    } catch (e) {}
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initUppyDragDrop();
});

