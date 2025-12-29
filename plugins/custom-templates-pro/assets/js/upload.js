/**
 * Custom Templates Pro - Upload Handler
 */

(function() {
    'use strict';

    // Configuration
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXTENSIONS = ['.zip'];

    /**
     * Format file size to human readable format
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * Validate file
     */
    function validateFile(file) {
        const errors = [];

        // Check file type
        if (!file.name.toLowerCase().endsWith('.zip')) {
            errors.push('Il file deve essere un archivio ZIP');
        }

        // Check file size
        if (file.size > MAX_FILE_SIZE) {
            errors.push(`Il file supera la dimensione massima di ${formatFileSize(MAX_FILE_SIZE)}`);
        }

        return {
            valid: errors.length === 0,
            errors: errors
        };
    }

    /**
     * Display validation errors
     */
    function displayErrors(errors) {
        const container = document.getElementById('validation-results');
        if (!container) return;

        const html = errors.map(error => `
            <div class="validation-item error">
                <i class="fas fa-exclamation-circle"></i>
                <span>${error}</span>
            </div>
        `).join('');

        const content = document.getElementById('validation-content');
        if (content) {
            content.innerHTML = html;
            container.classList.remove('hidden');
        }
    }

    /**
     * Display file preview
     */
    function displayFilePreview(file) {
        const validation = validateFile(file);

        if (!validation.valid) {
            displayErrors(validation.errors);
            return false;
        }

        // Show file info
        const container = document.getElementById('validation-results');
        const content = document.getElementById('validation-content');

        if (container && content) {
            content.innerHTML = `
                <div class="validation-item success">
                    <i class="fas fa-check-circle"></i>
                    <div class="flex-1">
                        <div class="font-medium">${file.name}</div>
                        <div class="text-xs mt-1">${formatFileSize(file.size)}</div>
                    </div>
                </div>
                <div class="validation-item success">
                    <i class="fas fa-shield-alt"></i>
                    <span>Validazione dimensione: OK</span>
                </div>
                <div class="text-xs text-gray-500 mt-2">
                    Il file verrà validato completamente durante l'upload (sintassi Twig, malware scan, ecc.)
                </div>
            `;
            container.classList.remove('hidden');
        }

        return true;
    }

    /**
     * Initialize upload handling
     */
    function initUploadHandling() {
        const fileInput = document.getElementById('template-zip');
        const dropZone = document.getElementById('drop-zone');
        const submitBtn = document.getElementById('submit-btn');

        if (!fileInput || !dropZone) return;

        // File input change event
        fileInput.addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const file = this.files[0];
                const isValid = displayFilePreview(file);

                if (submitBtn) {
                    submitBtn.disabled = !isValid;
                }
            }
        });

        // Drag and drop events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        // Highlight drop zone when dragging
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('border-blue-500', 'bg-blue-50', 'dragging');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('border-blue-500', 'bg-blue-50', 'dragging');
            }, false);
        });

        // Handle dropped files
        dropZone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;

            if (files.length > 0) {
                fileInput.files = files;

                // Trigger change event
                const event = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(event);
            }
        }, false);

        // Form submission
        const form = document.getElementById('upload-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Caricamento...';
                }
            });
        }
    }

    /**
     * Initialize type selector descriptions
     */
    function initTypeSelector() {
        const typeSelect = document.getElementById('template-type');
        const typeDescription = document.getElementById('type-description');

        if (!typeSelect || !typeDescription) return;

        const descriptions = {
            'gallery': 'Template per la griglia/masonry delle immagini negli album',
            'album_page': 'Template completo della pagina album (header + galleria + footer)',
            'homepage': 'Template per la homepage del portfolio'
        };

        typeSelect.addEventListener('change', function() {
            typeDescription.textContent = descriptions[this.value] || '';
        });
    }

    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initUploadHandling();
            initTypeSelector();
        });
    } else {
        initUploadHandling();
        initTypeSelector();
    }
})();
