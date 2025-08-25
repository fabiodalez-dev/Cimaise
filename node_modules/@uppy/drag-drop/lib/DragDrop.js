import { jsx as _jsx, jsxs as _jsxs } from "preact/jsx-runtime";
import { UIPlugin } from '@uppy/core';
import getDroppedFiles from '@uppy/utils/lib/getDroppedFiles';
import isDragDropSupported from '@uppy/utils/lib/isDragDropSupported';
import toArray from '@uppy/utils/lib/toArray';
import { h } from 'preact';
import packageJson from '../package.json' with { type: 'json' };
import locale from './locale.js';
const defaultOptions = {
    inputName: 'files[]',
    width: '100%',
    height: '100%',
};
/**
 * Drag & Drop plugin
 *
 */
export default class DragDrop extends UIPlugin {
    static VERSION = packageJson.version;
    // Check for browser dragDrop support
    isDragDropSupported = isDragDropSupported();
    fileInputRef;
    constructor(uppy, opts) {
        super(uppy, {
            ...defaultOptions,
            ...opts,
        });
        this.type = 'acquirer';
        this.id = this.opts.id || 'DragDrop';
        this.title = 'Drag & Drop';
        this.defaultLocale = locale;
        this.i18nInit();
    }
    addFiles = (files) => {
        const descriptors = files.map((file) => ({
            source: this.id,
            name: file.name,
            type: file.type,
            data: file,
            meta: {
                // path of the file relative to the ancestor directory the user selected.
                // e.g. 'docs/Old Prague/airbnb.pdf'
                relativePath: file.relativePath || null,
            },
        }));
        try {
            this.uppy.addFiles(descriptors);
        }
        catch (err) {
            this.uppy.log(err);
        }
    };
    onInputChange = (event) => {
        const files = toArray(event.currentTarget.files || []);
        if (files.length > 0) {
            this.uppy.log('[DragDrop] Files selected through input');
            this.addFiles(files);
        }
        // Clear the input so that Chrome can detect file section when the same file is repeatedly selected
        // (see https://github.com/transloadit/uppy/issues/768#issuecomment-2264902758)
        event.currentTarget.value = '';
    };
    handleDragOver = (event) => {
        event.preventDefault();
        event.stopPropagation();
        // Check if the "type" of the datatransfer object includes files. If not, deny drop.
        const { types } = event.dataTransfer;
        const hasFiles = types.some((type) => type === 'Files');
        const { allowNewUpload } = this.uppy.getState();
        if (!hasFiles || !allowNewUpload) {
            event.dataTransfer.dropEffect = 'none';
            return;
        }
        // Add a small (+) icon on drop
        // (and prevent browsers from interpreting this as files being _moved_ into the browser
        // https://github.com/transloadit/uppy/issues/1978)
        //
        event.dataTransfer.dropEffect = 'copy';
        this.setPluginState({ isDraggingOver: true });
        this.opts.onDragOver?.(event);
    };
    handleDragLeave = (event) => {
        event.preventDefault();
        event.stopPropagation();
        this.setPluginState({ isDraggingOver: false });
        this.opts.onDragLeave?.(event);
    };
    handleDrop = async (event) => {
        event.preventDefault();
        event.stopPropagation();
        this.setPluginState({ isDraggingOver: false });
        const logDropError = (error) => {
            this.uppy.log(error, 'error');
        };
        // Add all dropped files
        const files = await getDroppedFiles(event.dataTransfer, { logDropError });
        if (files.length > 0) {
            this.uppy.log('[DragDrop] Files dropped');
            this.addFiles(files);
        }
        this.opts.onDrop?.(event);
    };
    renderHiddenFileInput() {
        const { restrictions } = this.uppy.opts;
        return (_jsx("input", { className: "uppy-DragDrop-input", type: "file", hidden: true, ref: (ref) => {
                this.fileInputRef = ref;
            }, name: this.opts.inputName, multiple: restrictions.maxNumberOfFiles !== 1, accept: restrictions.allowedFileTypes?.join(', '), onChange: this.onInputChange }));
    }
    static renderArrowSvg() {
        return (_jsx("svg", { "aria-hidden": "true", focusable: "false", className: "uppy-c-icon uppy-DragDrop-arrow", width: "16", height: "16", viewBox: "0 0 16 16", children: _jsx("path", { d: "M11 10V0H5v10H2l6 6 6-6h-3zm0 0", fillRule: "evenodd" }) }));
    }
    renderLabel() {
        return (_jsx("div", { className: "uppy-DragDrop-label", children: this.i18nArray('dropHereOr', {
                browse: (_jsx("span", { className: "uppy-DragDrop-browse", children: this.i18n('browse') })),
            }) }));
    }
    renderNote() {
        return _jsx("span", { className: "uppy-DragDrop-note", children: this.opts.note });
    }
    render() {
        const dragDropClass = `uppy-u-reset
      uppy-DragDrop-container
      ${this.isDragDropSupported ? 'uppy-DragDrop--isDragDropSupported' : ''}
      ${this.getPluginState().isDraggingOver ? 'uppy-DragDrop--isDraggingOver' : ''}
    `;
        const dragDropStyle = {
            width: this.opts.width,
            height: this.opts.height,
        };
        return (_jsxs("button", { type: "button", className: dragDropClass, style: dragDropStyle, onClick: () => this.fileInputRef.click(), onDragOver: this.handleDragOver, onDragLeave: this.handleDragLeave, onDrop: this.handleDrop, children: [this.renderHiddenFileInput(), _jsxs("div", { className: "uppy-DragDrop-inner", children: [DragDrop.renderArrowSvg(), this.renderLabel(), this.renderNote()] })] }));
    }
    install() {
        const { target } = this.opts;
        this.setPluginState({
            isDraggingOver: false,
        });
        if (target) {
            this.mount(target, this);
        }
    }
    uninstall() {
        this.unmount();
    }
}
