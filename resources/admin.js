import Uppy from '@uppy/core'
// We avoid rendering Uppy UI; we keep our own area
import XHRUpload from '@uppy/xhr-upload'
import TomSelect from 'tom-select'
import 'tom-select/dist/css/tom-select.css'
import Sortable from 'sortablejs'
import tinymce from 'tinymce/tinymce'
import 'tinymce/icons/default'
import 'tinymce/themes/silver'
import 'tinymce/plugins/link'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/autoresize'
import 'tinymce/models/dom/model'
// TinyMCE UI/content CSS (bundled) to ensure toolbar is visible
import 'tinymce/skins/ui/oxide/skin.css'
import 'tinymce/skins/content/default/content.css'

function initUppyAreaUpload() {
  const area = document.getElementById('uppy');
  if (!area) return;
  
  // Prevent double initialization
  if (area._uppyInitialized) return;
  area._uppyInitialized = true;
  
  const endpoint = area.dataset.endpoint;
  const csrf = area.dataset.csrf;
  const uppy = new Uppy({ autoProceed: true, restrictions: { allowedFileTypes: ['image/*'] } })
    .use(XHRUpload, { endpoint, fieldName: 'file', headers: { 'X-CSRF-Token': csrf, 'Accept':'application/json' } });

  // Create progress indicator
  let progressEl = document.getElementById('upload-progress');
  if (!progressEl) {
    progressEl = document.createElement('div');
    progressEl.id = 'upload-progress';
    progressEl.className = 'hidden fixed top-4 right-4 bg-white border border-gray-300 rounded-lg shadow-lg p-4 z-50 min-w-[300px]';
    progressEl.innerHTML = `
      <div class="flex items-center gap-3">
        <i class="fas fa-cloud-upload-alt text-blue-500"></i>
        <div class="flex-1">
          <div class="text-sm font-medium text-gray-900">Caricamento in corso...</div>
          <div class="text-xs text-gray-500" id="upload-status">0% - Preparazione...</div>
        </div>
        <div class="w-12 h-12 relative">
          <svg class="w-12 h-12 transform -rotate-90" viewBox="0 0 36 36">
            <path d="m18,2.0845 a 15.9155,15.9155 0 0,1 0,31.831 a 15.9155,15.9155 0 0,1 0,-31.831" fill="none" stroke="#e5e7eb" stroke-width="3"/>
            <path id="upload-progress-circle" d="m18,2.0845 a 15.9155,15.9155 0 0,1 0,31.831 a 15.9155,15.9155 0 0,1 0,-31.831" fill="none" stroke="#3b82f6" stroke-width="3" stroke-dasharray="0, 100"/>
          </svg>
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-xs font-bold text-gray-900" id="upload-percentage">0%</span>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(progressEl);
  }

  // Hidden file input to trigger on click while preserving UI
  let input = area.querySelector('input[type="file"].uppy-input');
  if (!input) {
    input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.accept = 'image/*';
    input.style.display = 'none';
    input.classList.add('uppy-input');
    area.appendChild(input);
  }

  area.addEventListener('click', () => input.click());
  input.addEventListener('change', () => {
    if (!input.files) return;
    const existing = new Set(uppy.getFiles().map(f=>`${f.name}|${f.size}`));
    Array.from(input.files).forEach((f) => {
      const key = `${f.name}|${f.size}`;
      if (existing.has(key)) { if (window.showToast) window.showToast('File già aggiunto: ' + f.name, 'error'); return; }
      try { uppy.addFile({ source: 'file-input', name: f.name, type: f.type, data: f }); } catch(e) {}
    });
    input.value = '';
  });

  // Drag & drop support without injecting Uppy UI
  area.addEventListener('dragover', (e) => { e.preventDefault(); area.classList.add('bg-gray-100'); });
  area.addEventListener('dragleave', () => { area.classList.remove('bg-gray-100'); });
  area.addEventListener('drop', (e) => {
    e.preventDefault();
    area.classList.remove('bg-gray-100');
    const files = Array.from(e.dataTransfer?.files || []);
    const existing = new Set(uppy.getFiles().map(f=>`${f.name}|${f.size}`));
    files.forEach((f) => {
      const key = `${f.name}|${f.size}`;
      if (existing.has(key)) { if (window.showToast) window.showToast('File già aggiunto: ' + f.name, 'error'); return; }
      try { uppy.addFile({ source: 'drag-drop', name: f.name, type: f.type, data: f }); } catch(e) {}
    });
  });

  // Progress event handlers
  uppy.on('upload-start', () => {
    progressEl.classList.remove('hidden');
    const statusEl = document.getElementById('upload-status');
    const percentageEl = document.getElementById('upload-percentage');
    const circleEl = document.getElementById('upload-progress-circle');
    
    if (statusEl) statusEl.textContent = '0% - Avvio caricamento...';
    if (percentageEl) percentageEl.textContent = '0%';
    if (circleEl) circleEl.setAttribute('stroke-dasharray', '0, 100');
  });

  uppy.on('upload-progress', (file, progress) => {
    const percentage = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
    const statusEl = document.getElementById('upload-status');
    const percentageEl = document.getElementById('upload-percentage');
    const circleEl = document.getElementById('upload-progress-circle');
    
    if (statusEl) statusEl.textContent = `${percentage}% - Caricamento ${file.name}`;
    if (percentageEl) percentageEl.textContent = `${percentage}%`;
    if (circleEl) circleEl.setAttribute('stroke-dasharray', `${percentage}, 100`);
  });

  uppy.on('complete', (result) => {
    const statusEl = document.getElementById('upload-status');
    const percentageEl = document.getElementById('upload-percentage');
    const circleEl = document.getElementById('upload-progress-circle');
    
    if (statusEl) statusEl.textContent = '100% - Completato!';
    if (percentageEl) percentageEl.textContent = '100%';
    if (circleEl) circleEl.setAttribute('stroke-dasharray', '100, 100');
    
    // Hide progress after 2 seconds
    setTimeout(() => {
      progressEl.classList.add('hidden');
    }, 2000);
    
    refreshGalleryArea();
  });

  uppy.on('error', (error) => {
    const statusEl = document.getElementById('upload-status');
    if (statusEl) statusEl.textContent = `Errore: ${error.message}`;
    
    setTimeout(() => {
      progressEl.classList.add('hidden');
    }, 3000);
  });
}

document.addEventListener('DOMContentLoaded', () => { initUppyAreaUpload(); });

// Initialize all TomSelect fields if present
function initTomSelects() {
  const make = (selector, opts = {}) => {
    const el = document.querySelector(selector);
    if (!el) return null;
    try { return new TomSelect(el, opts); } catch { return null; }
  };

  const common = {
    plugins: ['remove_button'],
    // Keep clean, monochrome styling via default CSS + our form styles
    persist: false,
    create: false,
    maxItems: null,
    render: {
      option: (data, escape) => `<div>${escape(data.text ?? data.name ?? data.value)}</div>`,
      item: (data, escape) => `<div>${escape(data.text ?? data.name ?? data.value)}</div>`
    }
  };

  // Tags with async suggestions
  make('#album-tags', {
    ...common,
    valueField: 'id',
    labelField: 'name',
    searchField: 'name',
    load: (q, cb) => {
      fetch(`/admin/api/tags?q=${encodeURIComponent(q || '')}`, { headers: { 'Accept': 'application/json' }})
        .then(r => r.ok ? r.json() : []).then(cb).catch(() => cb());
    }
  });

  // Simple multi/selects
  make('#album-categories', common);
  make('#album-cameras', common);
  make('#album-lenses', common);
  make('#album-films', common);
  make('#album-developers', common);
  make('#album-labs', common);
  make('#album-locations', common);
}

document.addEventListener('DOMContentLoaded', initTomSelects);

// Sortable grid and controls on edit page
function initSortableGrid() {
  const grid = document.getElementById('images-grid');
  if (!grid) return;
  try {
    new Sortable(grid, {
      animation: 150,
      draggable: '[data-id]',
      onEnd: async () => {
        const ids = Array.from(grid.querySelectorAll('[data-id]')).map(el=>el.getAttribute('data-id'));
        const rel = grid.dataset.reorderEndpoint || '';
        const endpoint = rel.startsWith('/') ? `${window.basePath}${rel}` : `${window.basePath}/${rel}`;
        await fetch(endpoint, { method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': grid.dataset.csrf, 'Accept':'application/json' }, body: JSON.stringify({ order: ids }) });
        if (window.showToast) window.showToast('Ordine salvato', '');
      }
    });
  } catch {}

  const sortSelect = document.getElementById('sort-images');
  if (sortSelect) {
    const parseDate = (s) => new Date(s || '1970-01-01T00:00:00Z').getTime();
    function sortGridBy(mode){
      const cards = Array.from(grid.children);
      cards.sort((a,b) => {
        switch(mode){
          case 'created_newest': return parseDate(b.dataset.created) - parseDate(a.dataset.created);
          case 'created_oldest': return parseDate(a.dataset.created) - parseDate(b.dataset.created);
          case 'id_asc': return (parseInt(a.dataset.id,10)||0) - (parseInt(b.dataset.id,10)||0);
          case 'id_desc': return (parseInt(b.dataset.id,10)||0) - (parseInt(a.dataset.id,10)||0);
          default: return 0;
        }
      });
      cards.forEach(c => grid.appendChild(c));
    }
    sortSelect.addEventListener('change', (e)=> sortGridBy(e.target.value));
  }

  const saveBtn = document.getElementById('save-order');
  if (saveBtn) {
    saveBtn.addEventListener('click', async (e)=>{
      e.preventDefault();
      const ids = Array.from(grid.querySelectorAll('[data-id]')).map(el=>el.getAttribute('data-id'));
      const rel = grid.dataset.reorderEndpoint || '';
      const endpoint = rel.startsWith('/') ? `${window.basePath}${rel}` : `${window.basePath}/${rel}`;
      await fetch(endpoint, { method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': grid.dataset.csrf, 'Accept':'application/json' }, body: JSON.stringify({ order: ids }) });
    });
  }
}

// TinyMCE init on all richtext areas (GPL via npm)
function initTinyMCE() {
  const areas = document.querySelectorAll('textarea.richtext');
  if (!areas.length) return;
  try { tinymce.remove(); } catch {}
  
  tinymce.init({
    selector: 'textarea.richtext',
    menubar: false,
    statusbar: true,
    branding: false,
    plugins: 'link lists autoresize',
    toolbar: 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | blockquote | link | removeformat',
    block_formats: 'Paragrafo=p; Sottotitolo=h3; Titolo sezione=h2; Nota=h4',
    default_link_target: '_blank',
    link_default_protocol: 'https',
    rel_list: [
      { title: 'noopener', value: 'noopener' },
      { title: 'noreferrer', value: 'noreferrer' }
    ],
    skin: false,
    content_css: false,
    content_style: `
      body{
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; 
        color:#111; 
        line-height:1.7; 
        margin: 8px;
        background: white;
      } 
      a{color:#000; text-decoration:underline} 
      blockquote{border-left:3px solid #e5e7eb; margin:1rem 0; padding-left:.75rem; color:#444}
      
      /* TinyMCE UI Styles */
      .tox .tox-toolbar__group { display: flex !important; }
      .tox .tox-toolbar { display: flex !important; flex-wrap: wrap; }
      .tox .tox-editor-header { display: block !important; }
    `,
    valid_elements: 'p,br,strong/b,em/i,ul,ol,li,blockquote,a[href|target|rel],h2,h3,h4,hr',
    height: 600,
    resize: true,
    toolbar_mode: 'wrap',
    promotion: false,
    setup: function (editor) {
      editor.on('init', function () {
        // Force toolbar visibility
        const container = editor.getContainer();
        const toolbar = container.querySelector('.tox-toolbar');
        const header = container.querySelector('.tox-editor-header');
        if (header) header.style.display = 'block';
        if (toolbar) toolbar.style.display = 'flex';
      });
    }
  });
}

// Expose global initializer for SPA content loads
window.AdminInit = function() {
  initTomSelects();
  initUppyAreaUpload();
  initSortableGrid();
  initTinyMCE();
  initMediaModalOnEdit();
  bindGridButtons();
};

// Expose refreshGalleryArea globally
window.refreshGalleryArea = refreshGalleryArea;

// Ensure all initializers run on first load (not only SPA swaps)
document.addEventListener('DOMContentLoaded', () => {
  try { window.AdminInit(); } catch(e) { console.error(e); }
});

// Media library modal on album edit page
function initMediaModalOnEdit() {
  const btn = document.getElementById('open-media-library');
  const modal = document.getElementById('media-modal');
  const body = document.getElementById('media-body');
  const close = document.getElementById('media-close');
  const grid = document.getElementById('images-grid');
  
  if (!btn || !modal || !body) return;
  
  // Get albumId from uppy element or grid element
  const uppyEl = document.getElementById('uppy');
  const albumId = uppyEl?.dataset.albumId || grid?.dataset.albumId;
  
  if (!albumId) {
    console.error('No albumId found for media modal');
    return;
  }
  
  function open(){ 
    modal.classList.remove('hidden'); 
    modal.classList.add('flex'); 
    load(); 
  }
  function hide(){ 
    modal.classList.add('hidden'); 
    modal.classList.remove('flex'); 
  }
  async function load(){
    try {
      body.innerHTML = '<div class="p-8 text-center"><i class="fas fa-spinner fa-spin"></i> Caricamento...</div>';
      const res = await fetch(`${window.basePath || ''}/admin/media?partial=1`, { 
        headers: { 'Accept':'text/html' }
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const html = await res.text();
      body.innerHTML = html;
    } catch(e) {
      console.error('Failed to load media:', e);
      body.innerHTML = `<div class="text-center text-red-600 p-8">
        <p>Errore caricamento galleria</p>
        <p class="text-sm mt-2">${e.message}</p>
      </div>`;
    }
  }
  btn.addEventListener('click', open);
  close?.addEventListener('click', hide);
  body.addEventListener('click', async (e)=>{
    if (e.target.closest('input,button,select,label')) return;
    const el = e.target.closest('[data-media-id]'); if (!el) return;
    const id = el.getAttribute('data-media-id');
    const fd = new URLSearchParams(); fd.append('csrf', getCsrf()); fd.append('image_id', id);
    try {
      const res = await fetch(`${window.basePath || ''}/admin/albums/${albumId}/images/attach`, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: fd.toString() });
      if (res.ok) { 
        hide(); 
        if (window.showToast) window.showToast('Immagine aggiunta','success'); 
        refreshGalleryArea(); 
      } else {
        console.error('Failed to attach image:', res.status, res.statusText);
        if (window.showToast) window.showToast('Errore aggiunta immagine','error');
      }
    } catch(error) {
      console.error('Error attaching image:', error);
      if (window.showToast) window.showToast('Errore aggiunta immagine','error');
    }
  });
}

function getCsrf(){ const el = document.querySelector('input[name="csrf"]'); return el ? el.value : ''; }

// Refresh only the gallery grid after uploads
async function refreshGalleryArea() {
  try {
    // Show loading indicator
    const grid = document.getElementById('images-grid');
    if (grid) {
      const loadingEl = document.createElement('div');
      loadingEl.className = 'gallery-refresh-loading';
      loadingEl.style.cssText = 'position: fixed; inset: 0; background: rgba(0, 0, 0, 0.2); display: flex; align-items: center; justify-content: center; z-index: 50;';
      loadingEl.innerHTML = '<div class="bg-white rounded-lg p-4 shadow-lg"><i class="fas fa-spinner fa-spin mr-2"></i>Aggiornamento galleria...</div>';
      document.body.appendChild(loadingEl);
    }
    
    const res = await fetch(window.location.href, { headers: { 'Accept': 'text/html' }});
    if (!res.ok) throw new Error('Failed to fetch page');
    const html = await res.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const newGrid = doc.querySelector('#images-grid');
    
    if (newGrid && grid) {
      // Update grid content
      grid.innerHTML = newGrid.innerHTML;
      
      // Re-initialize all components that depend on the grid
      initSortableGrid();
      bindGridButtons();
      rebindBulkSelection();
      rebindImageModalHandlers(); // Add this new function
      
      if (window.showToast) window.showToast('Galleria aggiornata', 'success');
    }
    
    // Remove loading indicator
    const loadingEl = document.querySelector('.gallery-refresh-loading');
    if (loadingEl) loadingEl.remove();
    
  } catch (e) { 
    console.error('Error refreshing gallery:', e);
    // Remove loading indicator on error
    const loadingEl = document.querySelector('.gallery-refresh-loading');
    if (loadingEl) loadingEl.remove();
    
    // Fallback to full page reload if refresh fails
    window.location.reload(); 
  }
}

function bindGridButtons() {
  const grid = document.getElementById('images-grid');
  if (!grid) return;
  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  const albumId = grid.dataset.albumId;
  // Cover
  grid.querySelectorAll('[data-cover-id]')?.forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-cover-id');
      await fetch(`${window.basePath}/admin/albums/${albumId}/cover/${id}`, { method:'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }});
      refreshGalleryArea();
    });
  });
  // Delete
  grid.querySelectorAll('[data-delete-id]')?.forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Eliminare questa immagine?')) return;
      const id = btn.getAttribute('data-delete-id');
      const res = await fetch(`${window.basePath}/admin/albums/${albumId}/images/${id}/delete`, { method:'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }});
      if (res.ok) {
        btn.closest('[data-id]')?.remove();
        if (window.showToast) window.showToast('Immagine eliminata', '');
      } else {
        if (window.showToast) window.showToast('Errore eliminazione', 'error');
      }
    });
  });
}

function rebindBulkSelection() {
  // Re-bind bulk selection checkbox events
  function updateBulk() { 
    const bulkBtn = document.getElementById('bulk-delete');
    const getSelectedIds = () => Array.from(document.querySelectorAll('[data-select-id]:checked')).map(cb=>cb.getAttribute('data-select-id'));
    const any = getSelectedIds().length > 0; 
    if (bulkBtn) bulkBtn.disabled = !any; 
  }
  
  document.querySelectorAll('[data-select-id]').forEach(cb => {
    cb.removeEventListener('change', updateBulk); // Remove old listeners
    cb.addEventListener('change', updateBulk);
  });
  
  updateBulk(); // Update initial state
}

// New function to re-bind image modal click handlers after gallery refresh
function rebindImageModalHandlers() {
  const grid = document.getElementById('images-grid');
  if (!grid) return;
  
  // Remove any existing delegated click handler to avoid duplicates
  const existingHandler = grid._modalClickHandler;
  if (existingHandler) {
    grid.removeEventListener('click', existingHandler);
  }
  
  // Create new delegated click handler
  const newHandler = (e) => {
    // Don't interfere with button clicks
    if (e.target.closest('[data-select-id]') || e.target.closest('[data-delete-id]') || e.target.closest('[data-cover-id]')) return;
    
    // Find the clicked image box
    const box = e.target.closest('[data-id]');
    if (!box) return;
    
    // Check if we clicked on the image area (not just anywhere in the card)
    const imageArea = e.target.closest('.aspect-square');
    if (!imageArea) return;
    
    // Get image data and open modal
    const imageId = box.getAttribute('data-id');
    const data = {
      alt_text: box.getAttribute('data-alt_text'),
      caption: box.getAttribute('data-caption'),
      camera_id: box.getAttribute('data-camera_id'),
      lens_id: box.getAttribute('data-lens_id'),
      film_id: box.getAttribute('data-film_id'),
      developer_id: box.getAttribute('data-developer_id'),
      lab_id: box.getAttribute('data-lab_id'),
      location_id: box.getAttribute('data-location_id'),
      custom_camera: box.getAttribute('data-custom_camera'),
      custom_lens: box.getAttribute('data-custom_lens'),
      custom_film: box.getAttribute('data-custom_film'),
      iso: box.getAttribute('data-iso'),
      shutter_speed: box.getAttribute('data-shutter_speed'),
      aperture: box.getAttribute('data-aperture'),
    };
    
    // Call global modal open function if it exists
    if (window.openImageModal) {
      window.openImageModal(imageId, data, box);
    }
  };
  
  // Store reference and add new handler
  grid._modalClickHandler = newHandler;
  grid.addEventListener('click', newHandler);
}
