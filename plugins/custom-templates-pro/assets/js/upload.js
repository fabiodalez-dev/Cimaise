/**
 * Custom Templates Pro - Upload Handler
 */

(function() {
    'use strict';

    // Configuration
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXTENSIONS = ['.zip'];
    const configEl = document.getElementById('upload-config');

    function parseConfig(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    const config = {
        typeDescriptions: parseConfig(configEl?.dataset.typeDescriptions, {}),
        errors: parseConfig(configEl?.dataset.errors, {}),
        messages: parseConfig(configEl?.dataset.messages, {}),
        existingCountTemplate: configEl?.dataset.existingCountTemplate || '{count}'
    };

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
            errors.push(config.errors.type || 'Only ZIP files are allowed');
        }

        // Check file size
        if (file.size > MAX_FILE_SIZE) {
            const defaultMessage = `File exceeds maximum size of ${formatFileSize(MAX_FILE_SIZE)}`;
            errors.push(config.errors.size || defaultMessage);
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

        const content = document.getElementById('validation-content');
        if (content) {
            content.textContent = '';
            errors.forEach((error) => {
                const item = document.createElement('div');
                item.className = 'validation-item error';

                const icon = document.createElement('i');
                icon.className = 'fas fa-exclamation-circle';

                const text = document.createElement('span');
                text.textContent = error;

                item.appendChild(icon);
                item.appendChild(text);
                content.appendChild(item);
            });
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
            content.textContent = '';

            const successItem = document.createElement('div');
            successItem.className = 'validation-item success';

            const successIcon = document.createElement('i');
            successIcon.className = 'fas fa-check-circle';

            const info = document.createElement('div');
            info.className = 'flex-1';

            const nameDiv = document.createElement('div');
            nameDiv.className = 'font-medium';
            nameDiv.textContent = file.name;

            const sizeDiv = document.createElement('div');
            sizeDiv.className = 'text-xs mt-1';
            sizeDiv.textContent = formatFileSize(file.size);

            info.appendChild(nameDiv);
            info.appendChild(sizeDiv);
            successItem.appendChild(successIcon);
            successItem.appendChild(info);

            const sizeItem = document.createElement('div');
            sizeItem.className = 'validation-item success';

            const sizeIcon = document.createElement('i');
            sizeIcon.className = 'fas fa-shield-alt';

            const sizeText = document.createElement('span');
            sizeText.textContent = config.messages.size_ok || 'Size validation: OK';

            sizeItem.appendChild(sizeIcon);
            sizeItem.appendChild(sizeText);

            const note = document.createElement('div');
            note.className = 'text-xs text-gray-500 mt-2';
            note.textContent = config.messages.full_validation || 'File will be fully validated during upload.';

            content.appendChild(successItem);
            content.appendChild(sizeItem);
            content.appendChild(note);
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
            form.addEventListener('submit', function() {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    const uploadingText = config.messages.uploading || 'Uploading...';
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + uploadingText;
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

        const countText = document.getElementById('count-text');

        function updateTypeInfo() {
            const selectedOption = typeSelect.options[typeSelect.selectedIndex];
            typeDescription.textContent = config.typeDescriptions[typeSelect.value] || '';

            if (countText) {
                const count = selectedOption?.dataset?.count || 0;
                countText.textContent = config.existingCountTemplate.replace('{count}', count);
            }
        }

        typeSelect.addEventListener('change', updateTypeInfo);
        updateTypeInfo();
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
