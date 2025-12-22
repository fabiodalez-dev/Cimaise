import Uppy from '@uppy/core'
// We avoid rendering Uppy UI; we keep our own area
import XHRUpload from '@uppy/xhr-upload'
import Compressor from '@uppy/compressor'
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

// Import GSAP for animations
import { gsap } from 'gsap'

// Admin JS i18n helpers (translations injected in admin/_layout.twig)
const t = (key) => {
  try {
    if (typeof window !== 'undefined' && typeof window.adminT === 'function') {
      return window.adminT(key);
    }
  } catch (e) {}
  return key;
};
const tf = (key, params = {}) => {
  try {
    if (typeof window !== 'undefined' && typeof window.adminTf === 'function') {
      return window.adminTf(key, params);
    }
  } catch (e) {}
  let out = t(key);
  try {
    Object.keys(params || {}).forEach((k) => {
      out = String(out).replaceAll(`{${k}}`, String(params[k]));
    });
  } catch (e) {}
  return out;
};

// Debug logger (disabled unless window.__ADMIN_DEBUG is true)
const debugLog = (...args) => {
  try {
    if (typeof window !== 'undefined' && window.__ADMIN_DEBUG) {
      console.log(...args);
    }
  } catch (e) {}
};

/**
 * Initialize the custom image upload area: configures an Uppy instance (XHRUpload with CSRF),
 * builds a hidden file input, enables drag-and-drop, renders a total + per-file progress panel,
 * and wires event handlers to surface progress, errors, and completion (which triggers gallery refresh).
 *
 * This function is idempotent for the same area element (guards against double initialization).
 * It registers the Uppy instance on window.uppyInstances for external cleanup and uses the following
 * DOM elements/ids when present or created: #uppy (area), #upload-progress (progress panel),
 * #upload-file-list, #upload-bar-total, #upload-counter, and #upload-status.
 */
function initUppyAreaUpload() {
  const area = document.getElementById('uppy');
  if (!area) return;
  
  // Prevent double initialization
  if (area._uppyInitialized) return;
  area._uppyInitialized = true;
  
  const endpoint = area.dataset.endpoint;
  const csrf = area.dataset.csrf;
  const uppy = new Uppy({
    autoProceed: true,
    restrictions: {
      // Keep client restrictions aligned with server-side validation
      allowedFileTypes: ['image/jpeg', 'image/png', 'image/webp']
    }
  })
    // Compress images client-side before upload (reduces upload time significantly)
    .use(Compressor, {
      quality: 0.85,
      maxWidth: 4000,
      maxHeight: 4000,
      convertTypes: ['image/png'],  // Convert PNG to JPEG for smaller uploads
      convertSize: 500000  // Only convert PNGs larger than 500KB
    })
    .use(XHRUpload, {
      endpoint,
      fieldName: 'file',
      limit: 3,  // Upload 3 files in parallel for faster bulk uploads
      timeout: 120000,  // 2 minute timeout per file
      headers: {
        'X-CSRF-Token': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    });

  // Track instance globally for proper cleanup on SPA re-inits
  if (!window.uppyInstances) window.uppyInstances = [];
  window.uppyInstances.push(uppy);

  // Create progress indicator with file list
  let progressEl = document.getElementById('upload-progress');
  if (!progressEl) {
    progressEl = document.createElement('div');
    progressEl.id = 'upload-progress';
    progressEl.className = 'hidden fixed top-4 right-4 bg-white border border-gray-200 rounded-lg shadow-xl p-4 z-50 min-w-[350px] max-w-[400px]';
    progressEl.innerHTML = `
      <div class="space-y-3">
        <!-- Header with overall progress -->
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-black" id="upload-spinner"></div>
            <span class="text-sm font-medium text-gray-900" id="upload-title"></span>
          </div>
          <span class="text-sm text-gray-600" id="upload-counter">0 / 0</span>
        </div>
        <!-- Total progress bar -->
        <div class="w-full bg-gray-200 rounded-full h-2">
          <div id="upload-bar-total" class="bg-black h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
        <div class="text-xs text-gray-500" id="upload-status"></div>
        <!-- Individual file progress list -->
        <div id="upload-file-list" class="space-y-2 max-h-48 overflow-y-auto"></div>
      </div>
    `;
    document.body.appendChild(progressEl);
    const titleEl = document.getElementById('upload-title');
    if (titleEl) titleEl.textContent = t('admin.upload.in_progress_title');
    const statusEl = document.getElementById('upload-status');
    if (statusEl) statusEl.textContent = t('admin.upload.preparing');
  }

  // Track files for individual progress
  let fileProgressMap = new Map();

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

  // Store click handler to detach on cleanup and prevent duplicates
  const clickHandler = () => input.click();
  area._uppyClickHandler = clickHandler;
  area.addEventListener('click', clickHandler);
  input.addEventListener('change', () => {
    if (!input.files) return;
    const existing = new Set(uppy.getFiles().map(f=>`${f.name}|${f.size}`));
    Array.from(input.files).forEach((f) => {
      const key = `${f.name}|${f.size}`;
      if (existing.has(key)) { if (window.showToast) window.showToast(tf('admin.upload.file_already_added', { name: f.name }), 'error'); return; }
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
      if (existing.has(key)) { if (window.showToast) window.showToast(tf('admin.upload.file_already_added', { name: f.name }), 'error'); return; }
      try { uppy.addFile({ source: 'drag-drop', name: f.name, type: f.type, data: f }); } catch(e) {}
    });
  });

  // Helper to create file progress element
  function createFileProgressEl(file) {
    // Sanitize filename to prevent XSS
    const safeName = file.name.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const div = document.createElement('div');
    div.id = `file-prog-${file.id}`;
    div.className = 'bg-gray-50 border border-gray-200 rounded p-2';
    div.innerHTML = `
      <div class="flex items-center justify-between mb-1">
        <span class="text-xs text-gray-700 truncate flex-1 mr-2" title="${safeName}">
          <i class="fas fa-image text-gray-400 mr-1"></i>${safeName}
        </span>
        <span class="text-xs text-gray-500 file-status"></span>
      </div>
      <div class="w-full bg-gray-100 rounded-full h-1">
        <div class="file-bar bg-gray-400 h-1 rounded-full transition-all duration-150" style="width: 0%"></div>
      </div>
    `;
    const statusEl = div.querySelector('.file-status');
    if (statusEl) statusEl.textContent = t('admin.upload.queued');
    return div;
  }

  // Helper to update file progress
  function updateFileEl(fileId, percent, status, isError = false, isComplete = false) {
    const div = document.getElementById(`file-prog-${fileId}`);
    if (!div) return;
    const bar = div.querySelector('.file-bar');
    const statusEl = div.querySelector('.file-status');
    if (bar) {
      bar.style.width = `${percent}%`;
      if (isComplete) bar.className = 'file-bar bg-green-500 h-1 rounded-full transition-all duration-150';
      else if (isError) bar.className = 'file-bar bg-red-500 h-1 rounded-full transition-all duration-150';
      else if (percent > 0) bar.className = 'file-bar bg-black h-1 rounded-full transition-all duration-150';
    }
    if (statusEl && status) {
      statusEl.textContent = status;
      if (isComplete) statusEl.className = 'text-xs text-green-600 file-status';
      else if (isError) statusEl.className = 'text-xs text-red-600 file-status';
      else statusEl.className = 'text-xs text-gray-500 file-status';
    }
  }

  // Helper to update total progress
  function updateTotalProgress() {
    const files = uppy.getFiles();
    const total = files.length;
    const completed = files.filter(f => f.progress?.uploadComplete).length;
    const counterEl = document.getElementById('upload-counter');
    const barEl = document.getElementById('upload-bar-total');
    if (counterEl) counterEl.textContent = `${completed} / ${total}`;
    if (barEl) barEl.style.width = `${total > 0 ? (completed / total) * 100 : 0}%`;
  }

  // Progress event handlers
  uppy.on('file-added', (file) => {
    const listEl = document.getElementById('upload-file-list');
    if (listEl) {
      listEl.appendChild(createFileProgressEl(file));
    }
    fileProgressMap.set(file.id, 0);
    updateTotalProgress();
  });

  // Show compression status when compressor is processing
  uppy.on('preprocess-progress', (file, progress) => {
    if (progress.mode === 'indeterminate') {
      const statusEl = document.getElementById('upload-status');
      if (statusEl) statusEl.textContent = tf('admin.upload.compressing', { name: file.name });
    }
  });

  uppy.on('upload-start', () => {
    progressEl.classList.remove('hidden');
    const statusEl = document.getElementById('upload-status');
    if (statusEl) statusEl.textContent = t('admin.upload.starting_upload');
    updateTotalProgress();
  });

  uppy.on('upload-progress', (file, progress) => {
    const percentage = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
    updateFileEl(file.id, percentage, `${percentage}%`);
    fileProgressMap.set(file.id, percentage);

    const statusEl = document.getElementById('upload-status');
    if (statusEl) statusEl.textContent = tf('admin.upload.uploading_file', { name: file.name });
  });

  uppy.on('upload-success', (file) => {
    updateFileEl(file.id, 100, t('admin.upload.completed') + ' ✓', false, true);
    fileProgressMap.set(file.id, 100);
    updateTotalProgress();
  });

  uppy.on('complete', (result) => {
    const statusEl = document.getElementById('upload-status');
    const spinnerEl = document.getElementById('upload-spinner');

    const count = result.successful?.length || 0;
    if (statusEl) statusEl.textContent = tf('admin.upload.completed_summary', { count });
    if (spinnerEl) spinnerEl.className = 'rounded-full h-5 w-5 bg-green-500 flex items-center justify-center text-white text-xs';
    if (spinnerEl) spinnerEl.innerHTML = '<i class="fas fa-check"></i>';

    // Hide progress after 2.5 seconds and clear file list
    setTimeout(() => {
      progressEl.classList.add('hidden');
      const listEl = document.getElementById('upload-file-list');
      if (listEl) listEl.innerHTML = '';
      fileProgressMap.clear();
      // Reset spinner
      if (spinnerEl) {
        spinnerEl.className = 'animate-spin rounded-full h-5 w-5 border-b-2 border-black';
        spinnerEl.innerHTML = '';
      }
    }, 2500);

    refreshGalleryArea();
  });

  // Surface server-side errors (400, etc.) instead of generic network error
  uppy.on('upload-error', (file, error, response) => {
    let msg = t('admin.upload.upload_error');
    try {
      if (response && response.body) {
        msg = response.body.error || response.body.message || msg;
      } else if (response && response.response) {
        // XHRUpload may expose raw XHR as response
        const text = response.responseText || response.response || '';
        try { const j = JSON.parse(text); msg = j.error || j.message || msg; } catch {}
      }
      if (error && error.message && (!msg || msg === 'Upload error')) msg = error.message;
    } catch {}

    // Update individual file progress to show error
    updateFileEl(file.id, 100, t('admin.common.error') + ' ✗', true, false);
    updateTotalProgress();

    if (window.showToast) window.showToast(msg, 'error');
    console.error('Upload error:', msg, { file, error, response });
  });

  uppy.on('error', (error) => {
    const statusEl = document.getElementById('upload-status');
    if (statusEl) statusEl.textContent = t('admin.common.error');
    try { console.error('[Upload error]', error); } catch (e) {}

    setTimeout(() => {
      progressEl.classList.add('hidden');
      const listEl = document.getElementById('upload-file-list');
      if (listEl) listEl.innerHTML = '';
      fileProgressMap.clear();
    }, 3000);
  });
}

// Initialize all TomSelect fields if present
function initTomSelects() {
  debugLog('Initializing TomSelects...');
  
  const make = (selector, opts = {}) => {
    const elements = document.querySelectorAll(selector);
    elements.forEach(el => {
      // Skip if already initialized
      if (el.tomselect) {
        debugLog(`TomSelect already initialized for ${selector}`);
        return;
      }
      
      try {
        el.tomselect = new TomSelect(el, opts);
        debugLog(`TomSelect initialized for ${selector}`);
      } catch(e) {
        console.error(`Failed to initialize TomSelect for ${selector}:`, e);
      }
    });
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
      fetch(`${window.basePath || ''}/admin/api/tags?q=${encodeURIComponent(q || '')}`, { headers: { 'Accept': 'application/json' }})
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
  
  // Generic selects that might be in any admin page
  make('select.tom-select', common);
  make('select[multiple]:not(.ts-hidden-accessible)', common);
}

// Sortable grid and controls on edit page
function initSortableGrid() {
  debugLog('Initializing Sortable grid...');
  
  const grid = document.getElementById('images-grid');
  if (!grid) {
    debugLog('No images-grid found, skipping Sortable initialization');
    return;
  }
  
  // Cleanup existing instance if present
  if (grid._sortableInstance) {
    try {
      grid._sortableInstance.destroy();
      debugLog('Destroyed existing Sortable instance');
    } catch(e) {
      console.warn('Failed to destroy existing Sortable instance:', e);
    }
    delete grid._sortableInstance;
  }
  
  try {
    const sortableInstance = new Sortable(grid, {
      animation: 150,
      draggable: '[data-id]',
      onEnd: async () => {
        const ids = Array.from(grid.querySelectorAll('[data-id]')).map(el=>el.getAttribute('data-id'));
        const rel = grid.dataset.reorderEndpoint || '';
        const endpoint = rel.startsWith('/') ? `${window.basePath}${rel}` : `${window.basePath}/${rel}`;
        try {
          await fetch(endpoint, { 
            method:'POST', 
            headers:{ 
              'Content-Type':'application/json', 
              'X-CSRF-Token': grid.dataset.csrf, 
              'Accept':'application/json' 
            }, 
            body: JSON.stringify({ order: ids }) 
          });
          if (window.showToast) window.showToast(t('admin.common.order_saved'), 'success');
        } catch(error) {
          console.error('Failed to save order:', error);
          if (window.showToast) window.showToast(t('admin.common.order_save_error'), 'error');
        }
      }
    });
    
    // Store instance for cleanup
    grid._sortableInstance = sortableInstance;
    if (!window.sortableInstances) window.sortableInstances = [];
    window.sortableInstances.push(sortableInstance);
    
    debugLog('Sortable initialized successfully');
  } catch(e) {
    console.error('Failed to initialize Sortable:', e);
  }

  const sortSelect = document.getElementById('sort-images');
  if (sortSelect && !sortSelect._sortInitialized) {
    sortSelect._sortInitialized = true;
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
  if (saveBtn && !saveBtn._saveInitialized) {
    saveBtn._saveInitialized = true;
    saveBtn.addEventListener('click', async (e)=>{
      e.preventDefault();
      const ids = Array.from(grid.querySelectorAll('[data-id]')).map(el=>el.getAttribute('data-id'));
      const rel = grid.dataset.reorderEndpoint || '';
      const endpoint = rel.startsWith('/') ? `${window.basePath}${rel}` : `${window.basePath}/${rel}`;
      try {
        await fetch(endpoint, { 
          method:'POST', 
          headers:{ 
            'Content-Type':'application/json', 
            'X-CSRF-Token': grid.dataset.csrf, 
            'Accept':'application/json' 
          }, 
          body: JSON.stringify({ order: ids }) 
        });
        if (window.showToast) window.showToast(t('admin.common.order_saved_manual'), 'success');
      } catch(error) {
        console.error('Failed to save order manually:', error);
        if (window.showToast) window.showToast(t('admin.common.order_save_error'), 'error');
      }
    });
  }
}

// TinyMCE init on all richtext areas (GPL via npm)
function initTinyMCE() {
  debugLog('Initializing TinyMCE...');
  
  const areas = document.querySelectorAll('textarea.richtext');
  if (!areas.length) {
    debugLog('No richtext areas found, skipping TinyMCE initialization');
    return;
  }
  
  // Remove existing instances to prevent conflicts
  try { 
    if (window.tinymce) {
      tinymce.remove(); 
      debugLog('Removed existing TinyMCE instances');
    }
  } catch(e) {
    console.warn('Failed to remove TinyMCE instances:', e);
  }
  
  tinymce.init({
    selector: 'textarea.richtext',
    menubar: false,
    statusbar: true,
    branding: false,
    plugins: 'link lists autoresize',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist | blockquote | link | removeformat',
    block_formats: `${t('admin.tinymce.paragraph')}=p; ${t('admin.tinymce.subtitle')}=h3; ${t('admin.tinymce.section_title')}=h2; ${t('admin.tinymce.note')}=h4`,
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
    valid_elements: 'p,br,strong/b,em/i,u,s,ul,ol,li,blockquote,a[href|target|rel],h2,h3,h4,hr',
    height: 700,
    min_height: 500,
    max_height: 1000,
    resize: true,
    toolbar_mode: 'wrap',
    promotion: false,
    setup: function (editor) {
      editor.on('init', function () {
        debugLog('TinyMCE editor initialized:', editor.id);
        // Force toolbar visibility
        const container = editor.getContainer();
        const toolbar = container.querySelector('.tox-toolbar');
        const header = container.querySelector('.tox-editor-header');
        if (header) header.style.display = 'block';
        if (toolbar) toolbar.style.display = 'flex';
      });
      
      editor.on('remove', function () {
        debugLog('TinyMCE editor removed:', editor.id);
      });
    }
  }).then(function(editors) {
    debugLog('TinyMCE initialized successfully for', editors.length, 'editors');
  }).catch(function(error) {
    console.error('TinyMCE initialization failed:', error);
  });
}

// Expose global initializer for SPA content loads
window.AdminInit = function() {
  debugLog('AdminInit: Initializing admin components...');
  
  // Cleanup any existing instances to prevent duplicates
  cleanupExistingInstances();
  
  // Initialize components in order
  initTomSelects();
  initUppyAreaUpload();
  initLogoUpload();
  initSortableGrid();
  bindGridButtons();
  initTinyMCE();
  initMediaModalOnEdit();
  initTooltips();
  initDropdowns();
  initFormValidation();
  
  debugLog('AdminInit: All components initialized');
};

// Cleanup function to remove existing instances
function cleanupExistingInstances() {
  try {
    // Cleanup TomSelect instances
    document.querySelectorAll('.ts-control').forEach(el => {
      const input = el.previousElementSibling;
      if (input && input.tomselect) {
        try {
          input.tomselect.destroy();
        } catch(e) {
          console.warn('Failed to destroy TomSelect:', e);
        }
      }
    });
    
    // Cleanup TinyMCE instances
    if (window.tinymce) {
      try {
        tinymce.remove();
      } catch(e) {
        console.warn('Failed to remove TinyMCE:', e);
      }
    }
    
    // Cleanup Uppy instances
    if (window.uppyInstances) {
      window.uppyInstances.forEach(uppy => {
        try {
          if (uppy && typeof uppy.close === 'function') {
            uppy.close();
          }
        } catch(e) {
          // Silently ignore - instance may already be closed
        }
      });
      window.uppyInstances = [];
    }
    
    // Cleanup Sortable instances
    if (window.sortableInstances) {
      window.sortableInstances.forEach(sortable => {
        try { sortable.destroy(); } catch(e) { console.warn('Failed to destroy Sortable:', e); }
      });
      window.sortableInstances = [];
    }
    
    // Reset initialization flags
    document.querySelectorAll('#sort-images').forEach(el => {
      delete el._sortInitialized;
    });
    
    document.querySelectorAll('#save-order').forEach(el => {
      delete el._saveInitialized;
    });
    
    document.querySelectorAll('#images-grid').forEach(el => {
      delete el._sortableInstance;
      delete el._gridButtonsBound;
    });
    
    // Detach Uppy click handlers and remove hidden inputs to prevent double dialogs
    document.querySelectorAll('#uppy').forEach(el => {
      try {
        if (el._uppyClickHandler) {
          el.removeEventListener('click', el._uppyClickHandler);
          delete el._uppyClickHandler;
        }
        el.querySelectorAll('input[type="file"].uppy-input').forEach(inp => inp.remove());
        // Allow re-init on the next AdminInit pass
        delete el._uppyInitialized;
      } catch(e) {
        console.warn('Cleanup Uppy click/input failed:', e);
      }
    });
    
    // Reset tooltip and dropdown flags
    document.querySelectorAll('[data-tooltip]').forEach(el => {
      delete el._tooltipInitialized;
    });
    
    document.querySelectorAll('.dropdown').forEach(el => {
      delete el._dropdownInitialized;
    });
    
    document.querySelectorAll('form[data-validate]').forEach(el => {
      delete el._validationInitialized;
    });
    
    debugLog('Cleanup completed successfully');
    
  } catch(e) {
    console.warn('Cleanup warning:', e);
  }
}

// Logo upload in Settings page
function initLogoUpload(){
  const area = document.getElementById('logo-uppy');
  const hidden = document.getElementById('site_logo');
  const preview = document.getElementById('site-logo-preview');
  const clearBtn = document.getElementById('site-logo-clear');
  if (!area || !hidden) return;
  if (area._uppyInitialized) return; area._uppyInitialized = true;
  const endpoint = area.dataset.endpoint;
  const csrf = area.dataset.csrf;
  const uppy = new Uppy({
    autoProceed: true,
    restrictions: { allowedFileTypes: ['image/png','image/jpeg','image/webp'] }
  }).use(XHRUpload, {
    endpoint,
    fieldName: 'file',
    headers: { 'X-CSRF-Token': csrf, 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' }
  });
  if (!window.uppyInstances) window.uppyInstances = [];
  window.uppyInstances.push(uppy);

  let input = area.querySelector('input[type="file"].uppy-input');
  if (!input) {
    input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/png,image/jpeg,image/webp'; input.style.display='none'; input.classList.add('uppy-input');
    area.appendChild(input);
  }
  area.addEventListener('click', ()=> input.click());
  input.addEventListener('change', ()=>{ if (input.files && input.files[0]) { try{ uppy.addFile({ name: input.files[0].name, type: input.files[0].type, data: input.files[0] }); }catch{} input.value=''; } });
  area.addEventListener('dragover', e=>{ e.preventDefault(); area.classList.add('bg-gray-100'); });
  area.addEventListener('dragleave', ()=> area.classList.remove('bg-gray-100'));
  area.addEventListener('drop', e=>{ e.preventDefault(); area.classList.remove('bg-gray-100'); const f=e.dataTransfer?.files?.[0]; if (f) { try{ uppy.addFile({ name:f.name, type:f.type, data:f }); }catch{} } });

  uppy.on('complete', (res)=>{
    const first = res.successful && res.successful[0];
    const body = first && first.response && (first.response.body || {});
    if (body && body.ok && body.path) {
      hidden.value = body.path;
      if (preview) { preview.src = (window.basePath || '') + body.path; preview.classList.remove('hidden'); }
      if (clearBtn) clearBtn.classList.remove('hidden');

      // Show favicon generation result
      if (body.favicons && body.favicons.success) {
        const count = body.favicons.generated ? body.favicons.generated.length : 0;
        if (window.showToast) window.showToast(tf('admin.settings.favicons_generated', { count }), 'success');
      } else if (body.favicons && body.favicons.error) {
        if (window.showToast) {
          window.showToast(t('admin.settings.logo_updated'), 'success');
          window.showToast(tf('admin.settings.favicon_generation_failed', { error: body.favicons.error }), 'warning');
        }
      } else {
        if (window.showToast) window.showToast(t('admin.settings.logo_updated'), 'success');
      }
    } else {
      if (window.showToast) window.showToast(t('admin.settings.logo_upload_failed'), 'error');
    }
  });
  uppy.on('upload-error', (file, err, resp)=>{
    const candidate = (resp && resp.body && (resp.body.error || resp.body.message)) || (err && err.message) || '';
    try { if (candidate) console.warn('[Logo upload error]', candidate); } catch (e) {}
    if (window.showToast) window.showToast(t('admin.settings.logo_upload_error'), 'error');
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      hidden.value = '';
      if (preview) preview.classList.add('hidden');
      if (window.showToast) window.showToast(t('admin.settings.logo_removed_info'), 'info');
    });
  }
}

// Initialize tooltips
function initTooltips() {
  const tooltipElements = document.querySelectorAll('[data-tooltip]');
  tooltipElements.forEach(el => {
    if (!el._tooltipInitialized) {
      el._tooltipInitialized = true;
      el.addEventListener('mouseenter', showTooltip);
      el.addEventListener('mouseleave', hideTooltip);
    }
  });
}

// Initialize dropdown menus
function initDropdowns() {
  const dropdowns = document.querySelectorAll('.dropdown');
  dropdowns.forEach(dropdown => {
    if (!dropdown._dropdownInitialized) {
      dropdown._dropdownInitialized = true;
      const trigger = dropdown.querySelector('.dropdown-trigger');
      const content = dropdown.querySelector('.dropdown-content');
      
      if (trigger && content) {
        trigger.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleDropdown(dropdown);
        });
      }
    }
  });
}

// Initialize form validation
function initFormValidation() {
  const forms = document.querySelectorAll('form[data-validate]');
  forms.forEach(form => {
    if (!form._validationInitialized) {
      form._validationInitialized = true;
      form.addEventListener('submit', validateForm);
    }
  });
}

// Helper functions for tooltips
function showTooltip(e) {
  const text = e.target.getAttribute('data-tooltip');
  if (!text) return;
  
  const tooltip = document.createElement('div');
  tooltip.className = 'tooltip-popup';
  tooltip.textContent = text;
  tooltip.style.cssText = `
    position: absolute;
    background: rgba(0,0,0,0.9);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 14px;
    pointer-events: none;
    z-index: 1000;
    white-space: nowrap;
  `;
  
  document.body.appendChild(tooltip);
  
  const rect = e.target.getBoundingClientRect();
  tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
  tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';
  
  e.target._tooltip = tooltip;
}

function hideTooltip(e) {
  if (e.target._tooltip) {
    e.target._tooltip.remove();
    delete e.target._tooltip;
  }
}

// Helper function for dropdown toggle
function toggleDropdown(dropdown) {
  const content = dropdown.querySelector('.dropdown-content');
  const isOpen = content.classList.contains('show');
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-content.show').forEach(el => {
    el.classList.remove('show');
  });
  
  // Toggle current dropdown
  if (!isOpen) {
    content.classList.add('show');
  }
}

// Helper function for form validation
function validateForm(e) {
  const form = e.target;
  const requiredFields = form.querySelectorAll('[required]');
  let isValid = true;
  
  requiredFields.forEach(field => {
    if (!field.value.trim()) {
      field.classList.add('error');
      isValid = false;
    } else {
      field.classList.remove('error');
    }
  });
  
  if (!isValid) {
    e.preventDefault();
    if (window.showToast) window.showToast(t('admin.common.required_fields'), 'error');
  }
}

// Expose refreshGalleryArea globally
window.refreshGalleryArea = refreshGalleryArea;
// Expose rebindImageModalHandlers globally to prevent tree-shaking
window.rebindImageModalHandlers = rebindImageModalHandlers;

// SPA layout is responsible for calling AdminInit on initial load and swaps.
// Avoid calling AdminInit here to prevent double-initialization.

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
      body.innerHTML = `<div class="p-8 text-center"><i class="fas fa-spinner fa-spin"></i> ${t('admin.common.loading')}</div>`;
      const res = await fetch(`${window.basePath || ''}/admin/media?partial=1`, { 
        headers: { 'Accept':'text/html' }
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const html = await res.text();
      body.innerHTML = html;
    } catch(e) {
      console.error('Failed to load media:', e);
      body.innerHTML = `<div class="text-center text-red-600 p-8">
        <p>${t('admin.media.error_loading_gallery')}</p>
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
        if (window.showToast) window.showToast(t('admin.albums.image_added'), 'success');
        refreshGalleryArea();
      } else if (res.status === 409) {
        // Duplicate image
        const data = await res.json().catch(() => ({}));
        try { if (data && data.error) console.warn('[Attach image 409]', data.error); } catch (e) {}
        if (window.showToast) window.showToast(t('admin.albums.image_already_in_album'), 'error');
      } else {
        console.error('Failed to attach image:', res.status, res.statusText);
        if (window.showToast) window.showToast(t('admin.albums.error_add_image'), 'error');
      }
    } catch(error) {
      console.error('Error attaching image:', error);
      if (window.showToast) window.showToast(t('admin.albums.error_add_image'), 'error');
    }
  });
}

function getCsrf(){ const el = document.querySelector('input[name="csrf"]'); return el ? el.value : ''; }

// Refresh only the gallery grid after uploads (smooth, no flicker)
async function refreshGalleryArea() {
  try {
    debugLog('🔄 refreshGalleryArea called');

    const existingGrid = document.getElementById('images-grid');
    debugLog('📋 Existing grid found:', !!existingGrid);

    if (!existingGrid) {
      console.error('❌ images-grid element not found! The template should always include it now.');
      return;
    }

    // Fade out grid slightly while loading (no overlay, no flicker)
    existingGrid.style.transition = 'opacity 0.15s ease';
    existingGrid.style.opacity = '0.6';

    // Fetch the updated content
    debugLog('🌐 Fetching updated page content...');
    const res = await fetch(window.location.href, { headers: { 'Accept': 'text/html' }});
    if (!res.ok) throw new Error('Failed to fetch page');
    const html = await res.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const newGrid = doc.querySelector('#images-grid');
    const newBulkActions = doc.querySelector('.flex.items-center.justify-between.mt-6.mb-4');

    debugLog('📋 New grid found:', !!newGrid);
    debugLog('🔘 New bulk actions found:', !!newBulkActions);

    if (newGrid) {
      // Update grid content
      debugLog('✅ Updating grid content...');
      existingGrid.innerHTML = newGrid.innerHTML;
      // Update dataset attributes
      Object.assign(existingGrid.dataset, newGrid.dataset);

      // Update bulk actions count if they exist
      if (newBulkActions) {
        const currentBulkActions = document.querySelector('.flex.items-center.justify-between.mt-6.mb-4');
        if (currentBulkActions) {
          const currentLabel = currentBulkActions.querySelector('label');
          const newLabel = newBulkActions.querySelector('label');
          if (currentLabel && newLabel) {
            currentLabel.innerHTML = newLabel.innerHTML;
            debugLog('🔘 Updated bulk actions label');
          }
        }
      }

      // Re-initialize all components that depend on the grid
      debugLog('🔧 Re-initializing components...');
      initSortableGrid();
      bindGridButtons();
      rebindBulkSelection();
      rebindImageModalHandlers();

      // Fade grid back in
      existingGrid.style.opacity = '1';

      debugLog('✅ Gallery refresh completed successfully');
    } else {
      debugLog('⚠️ No grid found in new content');
      existingGrid.style.opacity = '1';
    }

  } catch (e) {
    console.error('❌ Error refreshing gallery:', e);
    // Restore opacity on error
    const existingGrid = document.getElementById('images-grid');
    if (existingGrid) existingGrid.style.opacity = '1';

    // Fallback to full page reload if refresh fails
    debugLog('🔄 Falling back to full page reload');
    window.location.reload();
  }
}

function bindGridButtons() {
  const grid = document.getElementById('images-grid');
  if (!grid) return;
  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  const albumId = grid.dataset.albumId;

  // Bind cover buttons
  grid.querySelectorAll('[data-cover-id]').forEach(btn => {
    if (btn._boundCover) return;
    btn._boundCover = true;
    btn.addEventListener('click', async (e) => {
      e.preventDefault(); e.stopPropagation();
      const id = btn.getAttribute('data-cover-id');
      try {
        const res = await fetch(`${window.basePath || ''}/admin/albums/${albumId}/cover/${id}`, { method:'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }});
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        if (window.refreshGalleryArea) await window.refreshGalleryArea();
        if (window.showToast) window.showToast(t('admin.albums.cover_set'), 'success');
      } catch (err) {
        console.error('Cover set failed:', err);
        if (window.showToast) window.showToast(t('admin.albums.error_cover'), 'error');
        // Fallback: full reload
        try { window.location.reload(); } catch(_){ }
      }
    });
  });

  // Bind delete buttons
  grid.querySelectorAll('[data-delete-id]').forEach(btn => {
    if (btn._boundDelete) return;
    btn._boundDelete = true;
    btn.addEventListener('click', async (e) => {
      e.preventDefault(); e.stopPropagation();
      if(!confirm(t('admin.albums.delete_image_confirm'))) return;
      const id = btn.getAttribute('data-delete-id');
      try {
        const res = await fetch(`${window.basePath || ''}/admin/albums/${albumId}/images/${id}/delete`, { method:'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }});
        if (res.ok) {
          btn.closest('[data-id]')?.remove();
          if (window.refreshGalleryArea) await window.refreshGalleryArea();
          if (window.showToast) window.showToast(t('admin.albums.image_deleted'), 'success');
        } else {
          const responseText = await res.text().catch(()=> '');
          console.error('Delete failed:', res.status, responseText);
          if (window.showToast) window.showToast(t('admin.albums.error_delete'), 'error');
          try { window.location.reload(); } catch(_){ }
        }
      } catch (err) {
        console.error('Delete request error:', err);
        if (window.showToast) window.showToast(t('admin.albums.error_delete'), 'error');
        try { window.location.reload(); } catch(_){ }
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

// Expose functions globally for use in templates
window.bindGridButtons = bindGridButtons;
window.rebindBulkSelection = rebindBulkSelection;

// Do not auto-run AdminInit here.
// The admin layout owns bootstrap and SPA re-initialization (see admin/_layout.twig).
