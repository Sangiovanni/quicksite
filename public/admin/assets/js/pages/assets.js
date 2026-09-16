/**
 * Asset Management Page JavaScript
 * 
 * Full-featured asset manager: upload zone, browser grid, edit area, batch delete.
 * Uses existing API commands: listAssets, uploadAsset, editAsset, deleteAsset.
 * 
 * @version 1.0.0
 */
(function() {
    'use strict';

    // ─── State ───────────────────────────────────────────────────────────────
    let allAssets = {};          // { images: [...], font: [...], audio: [...], videos: [...] }
    let flatAssets = [];         // Flattened array of all assets with category attached
    let activeCategory = 'all';
    let searchQuery = '';
    let selectMode = false;
    let selectedFiles = new Set();
    let uploadQueue = [];        // [{ type: 'file'|'url', file?, url?, name, size, category }]
    let allowedExtensions = [];
    let extensionsMap = {};      // { images: ['jpg',...], font: ['ttf',...], ... }
    // The favicon is a POINTER to one asset, so exactly one filename can hold
    // it. Null means no favicon chosen (the site falls back to its default) OR
    // that the pointer names something that is not an asset in this project,
    // such as an absolute URL — either way no card is marked.
    let currentFavicon = null;
    let faviconExtensions = [];  // favicon-capable subset, from the server
    let currentlyPlaying = null; // Audio element currently playing
    let editingAsset = null;     // Asset currently being edited
    let fontStyleElements = {};  // Track injected @font-face style elements
    // S2.5 — the SERVER's ceilings, fetched once at init. Null until they
    // arrive; every read below treats null as "do not block", so a failed fetch
    // degrades to the previous behaviour (server refuses, honestly, on POST)
    // rather than refusing everything client-side.
    let uploadLimits = null;

    // ─── Init ────────────────────────────────────────────────────────────────
    function init() {
        if (typeof QuickSiteAdmin === 'undefined') {
            setTimeout(init, 50);
            return;
        }
        loadExtensions();
        loadFaviconExtensions();
        loadUploadLimits();
        loadAssets();
        initUploadZone();
        initUrlInputs();
        initQueueControls();
        initBrowser();
        initEditArea();
        initBatchDelete();
    }

    // ─── Data Loading ────────────────────────────────────────────────────────
    /**
     * The favicon-capable subset of the images category, from its own helper
     * arm. Separate from loadExtensions() because 'asset-extensions' is
     * flattened wholesale by two callers — an extra key on that arm would end
     * up in the upload accept list.
     */
    async function loadFaviconExtensions() {
        try {
            faviconExtensions = await QuickSiteAdmin.fetchHelperData('favicon-extensions');
            if (!Array.isArray(faviconExtensions)) faviconExtensions = [];
            renderGrid();
        } catch (e) {
            // Non-critical: the control stays hidden and editFavicon is still
            // reachable from /admin/command.
            faviconExtensions = [];
        }
    }

    async function loadExtensions() {
        try {
            extensionsMap = await QuickSiteAdmin.fetchHelperData('asset-extensions');
            allowedExtensions = Object.values(extensionsMap).flat();
            renderDropzoneHint();
            const fileInput = document.getElementById('asset-file-input');
            if (fileInput) fileInput.setAttribute('accept', allowedExtensions.map(e => '.' + e).join(','));
        } catch (e) { /* non-critical */ }
    }

    // S2.5 — say the size ceiling BEFORE a user finds it by hitting it. The
    // numbers come from the server on every page load because both PHP
    // directives behind them are per-directory settings a deployer can change
    // without QuickSite knowing.
    async function loadUploadLimits() {
        try {
            uploadLimits = await QuickSiteAdmin.fetchHelperData('upload-limits');
            renderDropzoneHint();
        } catch (e) { /* non-critical — the server still refuses honestly */ }
    }

    /** The dropzone's one-line hint: what may be uploaded, and how big. */
    function renderDropzoneHint() {
        const hint = document.getElementById('asset-extensions-hint');
        if (!hint) return;

        const parts = [];
        if (allowedExtensions.length > 0) parts.push(allowedExtensions.join(', '));

        // Per-category, because they genuinely differ — a single "max 5 MB"
        // would be wrong for four of the five things a user can drop here.
        if (uploadLimits && uploadLimits.effective_human) {
            const sizes = Object.entries(uploadLimits.effective_human)
                .map(([cat, human]) => `${cat} ${human}`)
                .join(' · ');
            if (sizes) parts.push(t('media.maxSize', { sizes: sizes }));
        }

        hint.textContent = parts.join('  |  ');
    }

    /**
     * The ceiling that applies to one category, or null when the limits have
     * not arrived. Null means "let the server decide", never "block".
     */
    function limitForCategory(category) {
        if (!uploadLimits || !uploadLimits.effective) return null;
        const bytes = uploadLimits.effective[category];
        return typeof bytes === 'number' && bytes > 0 ? bytes : null;
    }

    async function loadAssets() {
        try {
            const result = await QuickSiteAdmin.apiRequest('listAssets', 'GET');
            if (result.ok && result.data?.data?.assets) {
                allAssets = result.data.data.assets;
                // listAssets reports the pointer as a bare filename when it
                // names an asset in this project, so it matches a card directly.
                currentFavicon = result.data.data.favicon ?? null;
            } else {
                allAssets = {};
                currentFavicon = null;
            }
        } catch (e) {
            allAssets = {};
            currentFavicon = null;
        }
        flattenAssets();
        renderGrid();
        updateCounts();
        hideLoading();
    }

    function flattenAssets() {
        flatAssets = [];
        const categories = ['images', 'font', 'audio', 'videos'];
        for (const cat of categories) {
            const items = allAssets[cat] || [];
            for (const item of items) {
                flatAssets.push({ ...item, category: cat });
            }
        }
    }

    function getFilteredAssets() {
        let list = flatAssets;
        if (activeCategory !== 'all') {
            list = list.filter(a => a.category === activeCategory);
        }
        if (searchQuery) {
            const q = searchQuery.toLowerCase();
            list = list.filter(a => a.filename.toLowerCase().includes(q));
        }
        return list;
    }

    // ─── Category / Extension Helpers ────────────────────────────────────────
    function detectCategory(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        for (const [cat, exts] of Object.entries(extensionsMap)) {
            if (exts.includes(ext)) return cat;
        }
        return null;
    }

    /**
     * Can this asset be the site favicon?
     *
     * The list comes from the SERVER (filePolicy.php's qs_favicon_extensions),
     * so the control appears on exactly the formats editFavicon will accept —
     * a UI that offers a choice the command refuses is worse than no control.
     * Until it arrives the answer is no, which hides the control rather than
     * showing one that would fail.
     */
    function isFaviconCapable(asset) {
        if (!asset || asset.category !== 'images') return false;
        const ext = getExtension(asset.filename).replace(/^\./, '').toLowerCase();
        return faviconExtensions.includes(ext);
    }

    function getFileIcon(category) {
        switch (category) {
            case 'images': return '🖼️';
            case 'font':   return '🔤';
            case 'audio':  return '🎵';
            case 'videos': return '🎬';
            default:       return '📁';
        }
    }

    function formatSize(bytes) {
        if (!bytes || bytes === 0) return '—';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function getAssetUrl(asset) {
        // Build URL from category + filename against the EDITED project's own serving
        // base. Not baseUrl: that is the site ROOT, which serves the SERVED main, so it
        // points at the wrong project whenever another one is being edited.
        // projectContentBase is the root for the served project and '/p/<id>' for
        // every other one.
        const cfg = window.QUICKSITE_CONFIG || {};
        const base = (cfg.projectContentBase || cfg.baseUrl || '').replace(/\/management$/, '');
        return base + '/assets/' + asset.category + '/' + encodeURIComponent(asset.filename);
    }

    /**
     * Resolve one admin string by its FULL dot path, from the sub-trees
     * media.php emits. A path that resolves to nothing returns THE PATH
     * ITSELF, so an unset string is visible on screen.
     *
     * @param {string} path      e.g. 'media.infoCategory'
     * @param {Object} [params]  :name markers, as PHP's t() does
     * @returns {string}
     */
    function t(path, params) {
        let node = window.QS_MEDIA_I18N || {};
        for (const part of String(path).split('.')) {
            if (node === null || typeof node !== 'object' || !(part in node)) return path;
            node = node[part];
        }
        if (typeof node !== 'string') return path;
        let value = node;
        if (params) {
            for (const name of Object.keys(params)) {
                value = value.split(':' + name).join(String(params[name]));
            }
        }
        return value;
    }

    /**
     * One "<strong>Label:</strong> value" line of the edit area's info block.
     * @returns {HTMLElement} one <p>
     */
    function _setStatus(el, message, color) {
        QSDom.clear(el);
        el.appendChild(QSDom.el('span', { style: 'color:' + color, text: message }));
    }

    /**
     * One "<strong>Label:</strong> value" line of the edit area's info block.
     * @returns {HTMLElement} one <p>
     */
    function _renderInfoLine(labelKey, value) {
        return QSDom.el('p', null, [
            QSDom.el('strong', { text: t(labelKey) }),
            ' ' + value
        ]);
    }

    // ─── Upload Zone ─────────────────────────────────────────────────────────
    function initUploadZone() {
        const dropzone = document.getElementById('asset-dropzone');
        const fileInput = document.getElementById('asset-file-input');
        if (!dropzone || !fileInput) return;

        // Click to browse
        dropzone.addEventListener('click', (e) => {
            if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') {
                fileInput.click();
            }
        });

        // File selection
        fileInput.addEventListener('change', () => {
            Array.from(fileInput.files).forEach(file => addToQueue('file', file));
            fileInput.value = '';
        });

        // Drag and drop
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('asset-dropzone--dragover');
        });
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('asset-dropzone--dragover');
        });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('asset-dropzone--dragover');
            Array.from(e.dataTransfer.files).forEach(file => addToQueue('file', file));
        });
    }

    // ─── URL Inputs (auto-growing) ───────────────────────────────────────────
    function initUrlInputs() {
        const container = document.getElementById('asset-url-inputs');
        if (!container) return;

        container.addEventListener('input', (e) => {
            if (!e.target.classList.contains('asset-url-input')) return;
            const value = e.target.value.trim();
            const inputs = container.querySelectorAll('.asset-url-input');
            const last = inputs[inputs.length - 1];

            // If this input has a valid URL with recognized extension, auto-add a new empty input
            if (e.target === last && value && hasValidExtension(value)) {
                const newInput = document.createElement('input');
                newInput.type = 'text';
                newInput.className = 'admin-input asset-url-input';
                newInput.placeholder = 'https://example.com/file.ext';
                newInput.autocomplete = 'off';
                container.appendChild(newInput);
            }
        });

        // On blur: add valid URLs to queue, clean up empty trailing inputs
        container.addEventListener('blur', (e) => {
            if (!e.target.classList.contains('asset-url-input')) return;
            const value = e.target.value.trim();

            if (value) {
                if (/^data:/i.test(value)) {
                    QuickSiteAdmin.showToast(t('media.dataUriUnsupported'), 'warning');
                } else if (hasValidExtension(value)) {
                    addToQueue('url', null, value);
                    e.target.value = '';
                }
            }

            // Remove empty trailing inputs (keep at least one)
            setTimeout(() => {
                const inputs = container.querySelectorAll('.asset-url-input');
                for (let i = inputs.length - 1; i > 0; i--) {
                    if (!inputs[i].value.trim() && document.activeElement !== inputs[i]) {
                        inputs[i].remove();
                    } else {
                        break;
                    }
                }
            }, 100);
        }, true);

        // Enter key in URL input → add to queue
        container.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.classList.contains('asset-url-input')) {
                e.preventDefault();
                const value = e.target.value.trim();
                if (value && /^data:/i.test(value)) {
                    QuickSiteAdmin.showToast(t('media.dataUriUnsupported'), 'warning');
                } else if (value && hasValidExtension(value)) {
                    addToQueue('url', null, value);
                    e.target.value = '';
                    // Focus the next (or newly created) empty input
                    const inputs = container.querySelectorAll('.asset-url-input');
                    const last = inputs[inputs.length - 1];
                    if (last.value.trim()) {
                        const newInput = document.createElement('input');
                        newInput.type = 'text';
                        newInput.className = 'admin-input asset-url-input';
                        newInput.placeholder = 'https://example.com/file.ext';
                        newInput.autocomplete = 'off';
                        container.appendChild(newInput);
                        newInput.focus();
                    } else {
                        last.focus();
                    }
                }
            }
        });
    }

    function hasValidExtension(value) {
        // Reject data: URIs
        if (/^data:/i.test(value)) return false;

        // Extract filename from URL or plain value
        try {
            const pathname = new URL(value).pathname;
            const ext = pathname.split('.').pop().toLowerCase();
            return allowedExtensions.includes(ext);
        } catch {
            const ext = value.split('.').pop().toLowerCase();
            return allowedExtensions.includes(ext);
        }
    }

    function extractFilename(url) {
        try {
            const pathname = new URL(url).pathname;
            return pathname.split('/').pop() || url;
        } catch {
            return url.split('/').pop() || url;
        }
    }

    // ─── Upload Queue ────────────────────────────────────────────────────────
    function addToQueue(type, file, url) {
        const name = type === 'file' ? file.name : extractFilename(url);
        const category = detectCategory(name);
        if (!category) {
            QuickSiteAdmin.showToast(t('media.unsupportedType', { name: name }), 'warning');
            return;
        }
        // S2.5 — refuse an over-sized file HERE, where we can name it, rather
        // than letting it be queued and rejected one round-trip later. Only for
        // 'file': a URL download is fetched server-side and its size is not
        // knowable until then, so it has no client-side answer.
        //
        // ⚠ Not a security control — the server enforces the same ceilings and
        // is the only enforcement that counts. This exists so the answer arrives
        // before a 50 MB upload, not instead of the server's.
        if (type === 'file') {
            const max = limitForCategory(category);
            if (max !== null && file.size > max) {
                QuickSiteAdmin.showToast(
                    t('media.overLimit', {
                        name: name, size: formatSize(file.size),
                        category: category, max: formatSize(max)
                    }),
                    'warning'
                );
                return;
            }
        }

        // Avoid duplicates
        const exists = uploadQueue.some(q => q.name === name && q.type === type);
        if (exists) return;

        uploadQueue.push({
            type,
            file: type === 'file' ? file : null,
            url: type === 'url' ? url : null,
            name,
            size: type === 'file' ? file.size : null,
            category,
            alt: '',
            description: ''
        });
        renderQueue();
    }

    function removeFromQueue(index) {
        uploadQueue.splice(index, 1);
        renderQueue();
    }

    // ─── Queue rendering ─────────────────────────────────────────────────────
    // Built with createElement + textContent. Every value on a queue row comes
    // from OUTSIDE the panel — `name` is a filename the user chose or a segment
    // of a URL they pasted, `alt` and `description` are whatever they type —
    // and the row used to be an interpolated HTML string with those values
    // glued in. Each _render* helper returns exactly ONE Element.

    /** One small labelled span in a queue row. Returns ONE Element. */
    function _renderQueueCell(className, text) {
        const span = document.createElement('span');
        span.className = className;
        span.textContent = text;
        return span;
    }

    /** The remove (×) button for row `index`. Returns ONE Element. */
    function _renderQueueRemoveButton(index) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'asset-queue__remove';
        btn.dataset.remove = String(index);
        btn.title = t('media.remove');
        btn.textContent = '×';   // × — matches the old `&times;` entity
        return btn;
    }

    /** One metadata input (alt / description) for row `index`. Returns ONE Element. */
    function _renderQueueMetaInput(field, placeholder, value, index) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'admin-input admin-input--sm';
        input.dataset.queueField = field;
        input.dataset.queueIndex = String(index);
        input.placeholder = placeholder;
        input.autocomplete = 'off';
        input.value = value || '';
        return input;
    }

    /** One complete queue row. Returns ONE Element. */
    function _renderQueueItem(item, index) {
        const li = document.createElement('li');
        li.className = 'asset-queue__item';
        li.dataset.index = String(index);

        const row = document.createElement('div');
        row.className = 'asset-queue__row';
        row.appendChild(_renderQueueCell('asset-queue__icon', getFileIcon(item.category)));
        row.appendChild(_renderQueueCell('asset-queue__name', item.name));
        row.appendChild(_renderQueueCell('asset-queue__size', item.size ? formatSize(item.size) : 'URL'));
        row.appendChild(_renderQueueCell('asset-queue__category', item.category));
        row.appendChild(_renderQueueRemoveButton(index));
        li.appendChild(row);

        const meta = document.createElement('div');
        meta.className = 'asset-queue__meta';
        meta.appendChild(_renderQueueMetaInput('alt', t('media.altPlaceholder'), item.alt, index));
        meta.appendChild(_renderQueueMetaInput('description', t('media.descriptionPlaceholder'), item.description, index));
        li.appendChild(meta);

        return li;
    }

    function renderQueue() {
        const container = document.getElementById('asset-queue');
        const list = document.getElementById('asset-queue-list');
        const countEl = document.getElementById('asset-queue-count');
        const btn = document.getElementById('asset-upload-btn');

        if (uploadQueue.length === 0) {
            container.style.display = 'none';
            return;
        }

        container.style.display = '';
        countEl.textContent = t(uploadQueue.length !== 1
            ? 'media.queueReadyMany' : 'media.queueReadyOne', { count: uploadQueue.length });
        btn.disabled = false;

        list.replaceChildren(...uploadQueue.map(_renderQueueItem));
    }

    function initQueueControls() {
        const container = document.getElementById('asset-queue');
        if (!container) return;

        // Remove from queue
        container.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('[data-remove]');
            if (removeBtn) {
                removeFromQueue(parseInt(removeBtn.dataset.remove));
            }
        });

        // Track alt/description input changes
        container.addEventListener('input', (e) => {
            const field = e.target.dataset.queueField;
            const index = e.target.dataset.queueIndex;
            if (field && index !== undefined && uploadQueue[index]) {
                uploadQueue[index][field] = e.target.value;
            }
        });

        // Clear all
        document.getElementById('asset-queue-clear')?.addEventListener('click', () => {
            uploadQueue = [];
            renderQueue();
        });

        // Upload all
        document.getElementById('asset-upload-btn')?.addEventListener('click', uploadAll);
    }

    // One upload-status line. Built as an element with textContent rather than
    // interpolated into innerHTML: the text can be a server message, and since
    // an upload may be answered by the web server rather than by QuickSite,
    // "server message" can mean an arbitrary error body. Nothing here needs
    // markup — it is one coloured string.
    function _renderUploadStatus(text, kind) {
        const span = document.createElement('span');
        span.style.color = kind === 'success' ? 'var(--admin-success)' : 'var(--admin-error)';
        span.textContent = text;
        return span;
    }

    async function uploadAll() {
        if (uploadQueue.length === 0) return;

        const btn = document.getElementById('asset-upload-btn');
        const progressDiv = document.getElementById('asset-upload-progress');
        btn.disabled = true;
        btn.textContent = t('media.uploading');
        progressDiv.style.display = '';
        progressDiv.innerHTML = '';

        const total = uploadQueue.length;
        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < total; i++) {
            const item = uploadQueue[i];
            const line = document.createElement('div');
            line.className = 'asset-upload-progress__item';
            line.appendChild(QSDom.el('span', { text: item.name }));
            line.appendChild(QSDom.el('span', {
                class: 'asset-upload-progress__status',
                text: t('media.uploadingProgress', { current: i + 1, total: total })
            }));
            progressDiv.appendChild(line);
            const statusEl = line.querySelector('.asset-upload-progress__status');

            try {
                let result;
                if (item.type === 'file') {
                    const formData = new FormData();
                    formData.append('file', item.file);
                    if (item.alt) formData.append('alt', item.alt);
                    if (item.description) formData.append('description', item.description);
                    result = await QuickSiteAdmin.apiUpload('uploadAsset', formData);
                } else {
                    const data = { url: item.url };
                    if (item.alt) data.alt = item.alt;
                    if (item.description) data.description = item.description;
                    result = await QuickSiteAdmin.apiRequest('uploadAsset', 'POST', data);
                }

                if (result.ok) {
                    successCount++;
                    statusEl.replaceChildren(_renderUploadStatus('→ ' + item.category + '/ ✓', 'success'));
                } else {
                    failCount++;
                    statusEl.replaceChildren(_renderUploadStatus(t('media.uploadItemFailed', {
                        message: result.data?.message || t('media.failed') }), 'error'));
                }
            } catch (error) {
                failCount++;
                statusEl.replaceChildren(_renderUploadStatus('✗ ' + error.message, 'error'));
            }
        }

        // Summary toast
        if (failCount === 0) {
            QuickSiteAdmin.showToast(t(successCount !== 1
                ? 'media.uploadedMany' : 'media.uploadedOne', { count: successCount }), 'success');
        } else if (successCount === 0) {
            QuickSiteAdmin.showToast(t('media.allUploadsFailed', { count: failCount }), 'error');
        } else {
            QuickSiteAdmin.showToast(t('media.uploadedPartial', { ok: successCount, failed: failCount }), 'warning');
        }

        // Clear queue and refresh browser
        uploadQueue = [];
        renderQueue();
        btn.textContent = t('media.uploadAll');
        btn.disabled = true;
        await loadAssets();
    }

    // ─── Asset Browser ───────────────────────────────────────────────────────
    function initBrowser() {
        // Category tabs
        document.getElementById('asset-tabs')?.addEventListener('click', (e) => {
            const tab = e.target.closest('.asset-tabs__tab');
            if (!tab || tab.id === 'asset-select-mode') return;
            document.querySelectorAll('.asset-tabs__tab:not(.asset-tabs__select)').forEach(tab => tab.classList.remove('asset-tabs__tab--active'));
            tab.classList.add('asset-tabs__tab--active');
            activeCategory = tab.dataset.category;
            renderGrid();
        });

        // Search
        document.getElementById('asset-search')?.addEventListener('input', (e) => {
            searchQuery = e.target.value.trim();
            renderGrid();
        });

        // Select mode toggle
        document.getElementById('asset-select-mode')?.addEventListener('click', toggleSelectMode);

        // Grid click delegation
        document.getElementById('asset-grid')?.addEventListener('click', handleGridClick);
    }

    function hideLoading() {
        const loading = document.getElementById('asset-loading');
        if (loading) loading.style.display = 'none';
    }

    function updateCounts() {
        const categories = ['images', 'font', 'audio', 'videos'];
        let total = 0;
        for (const cat of categories) {
            const count = (allAssets[cat] || []).length;
            total += count;
            const el = document.getElementById('count-' + cat);
            if (el) el.textContent = count;
        }
        const allEl = document.getElementById('count-all');
        if (allEl) allEl.textContent = total;
    }

    function renderGrid() {
        const grid = document.getElementById('asset-grid');
        const empty = document.getElementById('asset-empty');
        const emptyText = document.getElementById('asset-empty-text');
        if (!grid) return;

        const assets = getFilteredAssets();

        if (assets.length === 0) {
            grid.replaceChildren();
            if (empty) {
                empty.style.display = '';
                if (emptyText) {
                    if (searchQuery) {
                        emptyText.textContent = t('media.noMatch', { query: searchQuery });
                    } else if (activeCategory !== 'all') {
                        emptyText.textContent = t('media.noneInCategory', { category: activeCategory });
                    } else {
                        emptyText.textContent = t('media.noneYet');
                    }
                }
            }
            return;
        }

        if (empty) empty.style.display = 'none';

        grid.replaceChildren(...assets.map(_renderCard));

        // Inject @font-face for font assets
        assets.filter(a => a.category === 'font').forEach(injectFontFace);
    }

    // ─── Card Rendering ──────────────────────────────────────────────────────
    // Same rule as the upload queue above: every value on a card comes from
    // OUTSIDE the panel — the filename the user chose, the alt text they typed —
    // and the card used to be a multi-line interpolated HTML string with all of
    // it glued in, assembled with `grid.innerHTML = assets.map(...).join('')`.
    // Each _render* helper below returns exactly ONE Element.

    /** A small square action button on a card. Returns ONE Element. */
    function _renderCardButton(className, dataKey, filename, title, label) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = className;
        btn.dataset[dataKey] = filename;
        btn.title = title;
        btn.textContent = label;
        return btn;
    }

    /**
     * The favicon control: a RADIO, not a toggle. Exactly one asset can be the
     * site favicon, so choosing a new one clears the old — which is why this is
     * an <input type="radio"> in a shared group rather than a second star.
     *
     * Deliberately NOT the ⭐ next to it: that star means "include this asset in
     * AI prompts", is multi-select, is capped at 15, and is read by the
     * create-landing / create-website workflows. The two are unrelated choices
     * and each keeps its own control.
     *
     * Only rendered for favicon-capable formats — a .bmp or a .mp3 has no
     * favicon affordance at all, rather than a disabled one nobody can explain.
     *
     * Returns ONE Element, or null when this asset cannot be a favicon.
     */
    function _renderFaviconControl(asset) {
        if (!isFaviconCapable(asset)) return null;

        const label = document.createElement('label');
        label.className = 'asset-card__favicon';
        const isCurrent = currentFavicon === asset.filename;
        label.title = t(isCurrent ? 'media.faviconCurrent' : 'media.faviconUse');
        label.classList.toggle('asset-card__favicon--active', isCurrent);

        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'asset-favicon';
        input.className = 'asset-card__favicon-input';
        input.checked = isCurrent;
        input.dataset.favicon = asset.filename;
        input.setAttribute('aria-label', t('media.faviconUseAria', { name: asset.filename }));
        label.appendChild(input);

        const glyph = document.createElement('span');
        glyph.className = 'asset-card__favicon-glyph';
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = '🌐';
        label.appendChild(glyph);

        return label;
    }

    /** The select-mode checkbox for a card. Returns ONE Element. */
    function _renderCardCheckbox(asset, isSelected) {
        const label = document.createElement('label');
        label.className = 'asset-card__checkbox';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.select = asset.filename;
        input.checked = isSelected;
        label.appendChild(input);
        return label;
    }

    /** The thumbnail area's inner preview. Returns ONE Element. */
    function _renderThumb(asset) {
        const url = getAssetUrl(asset);
        switch (asset.category) {
            case 'images': {
                const img = document.createElement('img');
                img.src = url;
                img.alt = asset.alt || asset.filename;
                img.loading = 'lazy';
                img.className = 'asset-card__image';
                return img;
            }
            case 'font': {
                const span = document.createElement('span');
                span.className = 'asset-font-preview';
                span.style.fontFamily = `'qs-font-${asset.filename}'`;
                span.textContent = t('media.fontSpecimen');
                return span;
            }
            case 'audio': {
                const wrap = document.createElement('div');
                wrap.className = 'asset-audio-player';
                wrap.dataset.audioSrc = url;
                wrap.appendChild(_renderCardButton('asset-audio-player__btn', 'playAudio', asset.filename, t('media.play'), '▶'));
                const bar = document.createElement('div');
                bar.className = 'asset-audio-player__bar';
                const progress = document.createElement('div');
                progress.className = 'asset-audio-player__progress';
                bar.appendChild(progress);
                wrap.appendChild(bar);
                const audio = document.createElement('audio');
                audio.preload = 'none';
                audio.src = url;
                wrap.appendChild(audio);
                return wrap;
            }
            case 'videos': {
                // Two siblings (the video and its overlay button) — wrapped so
                // this helper keeps its one-Element contract.
                const wrap = document.createElement('div');
                wrap.className = 'asset-card__video-wrap';
                const video = document.createElement('video');
                video.src = url;
                video.className = 'asset-card__video';
                video.preload = 'metadata';
                video.muted = true;
                wrap.appendChild(video);
                wrap.appendChild(_renderCardButton('asset-video-overlay', 'playVideo', asset.filename, t('media.play'), '▶'));
                return wrap;
            }
            default: {
                const span = document.createElement('span');
                span.className = 'asset-card__icon-fallback';
                span.textContent = getFileIcon(asset.category);
                return span;
            }
        }
    }

    /** The name row: the (extension-stripped) name plus the rename pencil. */
    function _renderCardName(asset) {
        const wrap = document.createElement('div');
        wrap.className = 'asset-card__name';

        const text = document.createElement('span');
        text.className = 'asset-card__name-text';
        text.title = asset.filename;
        text.textContent = stripExtension(asset.filename);
        wrap.appendChild(text);

        if (!selectMode) {
            wrap.appendChild(_renderCardButton(
                'asset-card__action asset-card__rename', 'rename', asset.filename, 'Rename', '✏️'));
        }
        return wrap;
    }

    /** The info row: size, optional category chip, and the edit/delete actions. */
    function _renderCardInfo(asset) {
        const wrap = document.createElement('div');
        wrap.className = 'asset-card__info';

        const size = document.createElement('span');
        size.className = 'asset-card__size';
        size.textContent = formatSize(asset.size);
        if (activeCategory === 'all') {
            size.appendChild(document.createTextNode(' · '));
            const cat = document.createElement('span');
            cat.className = 'asset-card__category';
            cat.textContent = asset.category;
            size.appendChild(cat);
        }
        wrap.appendChild(size);

        if (!selectMode) {
            const actions = document.createElement('div');
            actions.className = 'asset-card__actions';
            actions.appendChild(_renderCardButton(
                'asset-card__action', 'edit', asset.filename, t('media.editMeta'), '✏️'));
            actions.appendChild(_renderCardButton(
                'asset-card__action asset-card__delete', 'delete', asset.filename, t('common.delete'), '🗑️'));
            wrap.appendChild(actions);
        }
        return wrap;
    }

    /** One complete asset card. Returns ONE Element. */
    function _renderCard(asset) {
        const isSelected = selectedFiles.has(asset.filename);

        const card = document.createElement('div');
        card.className = 'asset-card';
        card.classList.toggle('asset-card--selected', isSelected);
        card.classList.toggle('asset-card--selectable', selectMode);
        card.dataset.filename = asset.filename;
        card.dataset.category = asset.category;

        if (selectMode) {
            card.appendChild(_renderCardCheckbox(asset, isSelected));
        }

        const thumb = document.createElement('div');
        thumb.className = 'asset-card__thumb';
        thumb.appendChild(_renderThumb(asset));
        if (!selectMode) {
            thumb.appendChild(_renderCardButton(
                'asset-card__star' + (asset.starred ? ' asset-card__star--active' : ''),
                'star', asset.filename,
                t(asset.starred ? 'media.unstar' : 'media.star'),
                asset.starred ? '⭐' : '☆'));
            const favicon = _renderFaviconControl(asset);
            if (favicon) thumb.appendChild(favicon);
        }
        card.appendChild(thumb);

        const footer = document.createElement('div');
        footer.className = 'asset-card__footer';
        footer.appendChild(_renderCardName(asset));
        footer.appendChild(_renderCardInfo(asset));
        card.appendChild(footer);

        return card;
    }

    function stripExtension(filename) {
        const lastDot = filename.lastIndexOf('.');
        return lastDot > 0 ? filename.substring(0, lastDot) : filename;
    }

    function getExtension(filename) {
        const lastDot = filename.lastIndexOf('.');
        return lastDot > 0 ? filename.substring(lastDot) : '';
    }

    // ─── Font @font-face Injection ───────────────────────────────────────────
    function injectFontFace(asset) {
        if (fontStyleElements[asset.filename]) return; // already injected
        const url = getAssetUrl(asset);
        const style = document.createElement('style');
        style.textContent = `@font-face { font-family: 'qs-font-${asset.filename}'; src: url('${url}'); }`;
        document.head.appendChild(style);
        fontStyleElements[asset.filename] = style;
    }

    function cleanFontFaces() {
        for (const [name, el] of Object.entries(fontStyleElements)) {
            el.remove();
        }
        fontStyleElements = {};
    }

    // ─── Grid Click Handling ─────────────────────────────────────────────────
    function handleGridClick(e) {
        // In select mode: clicking anywhere on the card toggles selection
        if (selectMode) {
            const card = e.target.closest('.asset-card');
            if (!card) return;
            const filename = card.dataset.filename;
            if (!filename) return;

            // Allow audio/video play even in select mode
            if (e.target.closest('[data-play-audio]') || e.target.closest('[data-play-video]')) {
                // fall through to audio/video handlers below
            } else {
                // Toggle selection
                if (selectedFiles.has(filename)) {
                    selectedFiles.delete(filename);
                } else {
                    selectedFiles.add(filename);
                }
                card.classList.toggle('asset-card--selected', selectedFiles.has(filename));
                const cb = card.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = selectedFiles.has(filename);
                updateBatchBar();
                return;
            }
        }

        // Select checkbox (non-select-mode, shouldn't normally happen but safe fallback)
        const checkbox = e.target.closest('[data-select]');
        if (checkbox) {
            const filename = checkbox.dataset.select || checkbox.querySelector('input')?.dataset.select;
            if (e.target.type === 'checkbox') {
                if (e.target.checked) selectedFiles.add(e.target.dataset.select);
                else selectedFiles.delete(e.target.dataset.select);
                updateBatchBar();
                const card = e.target.closest('.asset-card');
                if (card) card.classList.toggle('asset-card--selected', e.target.checked);
            }
            return;
        }

        // Rename button
        const renameBtn = e.target.closest('[data-rename]');
        if (renameBtn) {
            startInlineRename(renameBtn.dataset.rename);
            return;
        }

        // Star toggle button (AI prompts — unrelated to the favicon below)
        const starBtn = e.target.closest('[data-star]');
        if (starBtn) {
            toggleStar(starBtn.dataset.star);
            return;
        }

        // Favicon radio. The <label> wraps the input, so a click reaches here
        // twice — once for the label and once for the input it activates.
        // Acting only on the input keeps it to one request.
        const faviconInput = e.target.closest('[data-favicon]');
        if (faviconInput) {
            setFavicon(faviconInput.dataset.favicon);
            return;
        }

        // Edit button (alt/desc)
        const editBtn = e.target.closest('[data-edit]');
        if (editBtn && !editBtn.dataset.rename) {
            openEditArea(editBtn.dataset.edit);
            return;
        }

        // Delete button
        const deleteBtn = e.target.closest('[data-delete]');
        if (deleteBtn) {
            deleteSingle(deleteBtn.dataset.delete);
            return;
        }

        // Audio play
        const playAudio = e.target.closest('[data-play-audio]');
        if (playAudio) {
            toggleAudio(playAudio);
            return;
        }

        // Video play
        const playVideo = e.target.closest('[data-play-video]');
        if (playVideo) {
            toggleVideo(playVideo);
            return;
        }
    }

    // ─── Audio / Video Playback ──────────────────────────────────────────────
    function toggleAudio(btn) {
        const player = btn.closest('.asset-audio-player');
        const audio = player?.querySelector('audio');
        if (!audio) return;

        if (audio.paused) {
            // Stop any other playing audio
            if (currentlyPlaying && currentlyPlaying !== audio) {
                currentlyPlaying.pause();
                currentlyPlaying.currentTime = 0;
                const otherBtn = currentlyPlaying.closest?.('.asset-audio-player')?.querySelector('.asset-audio-player__btn');
                if (otherBtn) otherBtn.textContent = '▶';
                const otherBar = currentlyPlaying.closest?.('.asset-audio-player')?.querySelector('.asset-audio-player__progress');
                if (otherBar) otherBar.style.width = '0%';
            }
            audio.play();
            currentlyPlaying = audio;
            btn.textContent = '⏸';

            // Progress tracking
            audio.ontimeupdate = () => {
                const progress = player.querySelector('.asset-audio-player__progress');
                if (progress && audio.duration) {
                    progress.style.width = (audio.currentTime / audio.duration * 100) + '%';
                }
            };
            audio.onended = () => {
                btn.textContent = '▶';
                const progress = player.querySelector('.asset-audio-player__progress');
                if (progress) progress.style.width = '0%';
                currentlyPlaying = null;
            };
        } else {
            audio.pause();
            btn.textContent = '▶';
            currentlyPlaying = null;
        }
    }

    function toggleVideo(btn) {
        const card = btn.closest('.asset-card');
        const video = card?.querySelector('video');
        if (!video) return;

        if (video.paused) {
            video.muted = false;
            video.controls = true;
            video.play();
            btn.style.display = 'none';
            video.onended = () => {
                video.controls = false;
                video.muted = true;
                btn.style.display = '';
            };
            video.onpause = () => {
                if (video.ended) return;
                // Keep controls visible while paused
            };
        } else {
            video.pause();
            video.controls = false;
            video.muted = true;
            btn.style.display = '';
        }
    }

    // ─── Inline Rename ───────────────────────────────────────────────────────
    function startInlineRename(filename) {
        const card = document.querySelector(`.asset-card[data-filename="${CSS.escape(filename)}"]`);
        if (!card) return;
        const nameText = card.querySelector('.asset-card__name-text');
        if (!nameText) return;

        const ext = getExtension(filename);
        const basename = stripExtension(filename);

        // Replace text with input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'admin-input admin-input--sm asset-card__rename-input';
        input.value = basename;
        const suffix = document.createElement('span');
        suffix.className = 'asset-card__rename-ext';
        suffix.textContent = ext;

        const wrapper = document.createElement('span');
        wrapper.className = 'asset-card__rename-wrapper';
        wrapper.appendChild(input);
        wrapper.appendChild(suffix);

        nameText.replaceWith(wrapper);
        input.focus();
        input.select();

        const cleanup = () => {
            const restored = document.createElement('span');
            restored.className = 'asset-card__name-text';
            restored.title = filename;
            restored.textContent = basename;
            wrapper.replaceWith(restored);
        };

        const confirm = async () => {
            const newBasename = input.value.trim();
            if (!newBasename || newBasename === basename) {
                cleanup();
                return;
            }
            const newFilename = newBasename + ext;
            try {
                const result = await QuickSiteAdmin.apiRequest('editAsset', 'POST', {
                    filename: filename,
                    newFilename: newFilename
                });
                if (result.ok) {
                    QuickSiteAdmin.showToast(t('media.renamed', { name: newFilename }), 'success');
                    await loadAssets();
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || t('media.renameFailed'), 'error');
                    cleanup();
                }
            } catch (error) {
                QuickSiteAdmin.showToast(t('media.renameFailedDetail', { message: error.message }), 'error');
                cleanup();
            }
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); confirm(); }
            if (e.key === 'Escape') { e.preventDefault(); cleanup(); }
        });
        input.addEventListener('blur', () => {
            // Small delay so click on another element doesn't cancel rename
            setTimeout(() => {
                if (document.contains(input)) cleanup();
            }, 150);
        });
    }

    // ─── Edit Area (Alt / Description) ───────────────────────────────────────
    function initEditArea() {
        document.getElementById('asset-edit-close')?.addEventListener('click', closeEditArea);
        document.getElementById('asset-edit-save')?.addEventListener('click', saveEdit);
    }

    function openEditArea(filename) {
        const asset = flatAssets.find(a => a.filename === filename);
        if (!asset) return;
        editingAsset = asset;

        const area = document.getElementById('asset-edit-area');
        const title = document.getElementById('asset-edit-title');
        const preview = document.getElementById('asset-edit-preview');
        const info = document.getElementById('asset-edit-info');
        const altInput = document.getElementById('asset-edit-alt');
        const descInput = document.getElementById('asset-edit-description');
        const status = document.getElementById('asset-edit-status');

        title.textContent = t('media.editTitle', { name: asset.filename });
        altInput.value = asset.alt || '';
        descInput.value = asset.description || '';
        status.textContent = '';

        // Preview
        const url = getAssetUrl(asset);
        QSDom.clear(preview);
        switch (asset.category) {
            case 'images':
                // setAttribute, not an interpolated attribute slot: alt is
                // author-entered free text, and the escaper this replaced was
                // the TEXT one, which leaves quotes alone.
                preview.appendChild(QSDom.el('img', {
                    src: url, alt: asset.alt || '', class: 'asset-edit-area__image'
                }));
                break;
            case 'font': {
                injectFontFace(asset);
                const specimen = QSDom.el('span', {
                    class: 'asset-edit-area__font', text: t('media.fontSpecimen')
                });
                specimen.style.fontFamily = "'qs-font-" + asset.filename + "'";
                preview.appendChild(specimen);
                break;
            }
            case 'audio':
                preview.appendChild(QSDom.el('audio', {
                    controls: '', src: url, style: 'width:100%'
                }));
                break;
            case 'videos':
                preview.appendChild(QSDom.el('video', {
                    controls: '', src: url, style: 'width:100%;max-height:240px'
                }));
                break;
            default:
                preview.appendChild(QSDom.el('span', {
                    style: 'font-size:3rem', text: getFileIcon(asset.category)
                }));
        }

        // Info
        const dims = (asset.width && asset.height) ? ` · ${asset.width}×${asset.height}` : '';
        QSDom.clear(info);
        info.appendChild(_renderInfoLine('media.infoCategory', asset.category));
        info.appendChild(_renderInfoLine('media.infoSize',
            formatSize(asset.size) + dims + (asset.mime_type ? ' · ' + asset.mime_type : '')));
        if (asset.modified) {
            info.appendChild(_renderInfoLine('media.infoModified', asset.modified));
        }

        area.style.display = '';
        area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeEditArea() {
        document.getElementById('asset-edit-area').style.display = 'none';
        editingAsset = null;
    }

    async function saveEdit() {
        if (!editingAsset) return;
        const altInput = document.getElementById('asset-edit-alt');
        const descInput = document.getElementById('asset-edit-description');
        const status = document.getElementById('asset-edit-status');
        const saveBtn = document.getElementById('asset-edit-save');

        const data = { filename: editingAsset.filename };
        const alt = altInput.value.trim();
        const desc = descInput.value.trim();

        // Only send changed fields
        if (alt !== (editingAsset.alt || '')) data.alt = alt;
        if (desc !== (editingAsset.description || '')) data.description = desc;

        if (Object.keys(data).length === 1) {
            status.textContent = t('media.noChanges');
            return;
        }

        saveBtn.disabled = true;
        status.textContent = t('common.saving');

        try {
            const result = await QuickSiteAdmin.apiRequest('editAsset', 'POST', data);
            if (result.ok) {
                _setStatus(status, t('media.saved'), 'var(--admin-success)');
                QuickSiteAdmin.showToast(t('media.updated', { name: editingAsset.filename }), 'success');
                await loadAssets();
                // Keep edit area open with refreshed data
                const refreshed = flatAssets.find(a => a.filename === editingAsset.filename);
                if (refreshed) openEditArea(refreshed.filename);
            } else {
                _setStatus(status, result.data?.message || t('media.failed'), 'var(--admin-error)');
            }
        } catch (error) {
            _setStatus(status, error.message, 'var(--admin-error)');
        }
        saveBtn.disabled = false;
    }

    // ─── Single Delete ───────────────────────────────────────────────────────
    async function deleteSingle(filename) {
        if (!confirm(t('media.confirmDeleteOne', { name: filename }))) return;

        try {
            const result = await QuickSiteAdmin.apiRequest('deleteAsset', 'POST', { filename });
            if (result.ok || result.status === 204) {
                QuickSiteAdmin.showToast(t('media.deleted', { name: filename }), 'success');
                if (editingAsset?.filename === filename) closeEditArea();
                await loadAssets();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || t('media.deleteFailed'), 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast(t('media.deleteFailedDetail', { message: error.message }), 'error');
        }
    }

    // ─── Star Toggle ───────────────────────────────────────────────────────
    async function toggleStar(filename) {
        const asset = flatAssets.find(a => a.filename === filename);
        if (!asset) return;

        const newStarred = !asset.starred;

        // Cap at 15 starred assets
        if (newStarred) {
            const starredCount = flatAssets.filter(a => a.starred).length;
            if (starredCount >= 15) {
                QuickSiteAdmin.showToast(t('media.starLimit'), 'warning');
                return;
            }
        }

        try {
            const result = await QuickSiteAdmin.apiRequest('editAsset', 'POST', {
                filename,
                starred: newStarred
            });
            if (result.ok) {
                asset.starred = newStarred;
                renderGrid();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || t('media.starFailed'), 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast(t('media.starFailedDetail', { message: error.message }), 'error');
        }
    }

    // ─── Favicon (radio, not toggle) ─────────────────────────────────────────
    /**
     * Point the site favicon at `filename`, or clear it when `filename` is
     * already the current one.
     *
     * RADIO SEMANTICS. Exactly one asset is the favicon, so this never has to
     * unset the previous card — `editFavicon` overwrites a single config value
     * and the re-render reads the new one. Clicking the CURRENT favicon clears
     * it, which is the only way back to the site default.
     */
    async function setFavicon(filename) {
        const asset = flatAssets.find(a => a.filename === filename);
        if (!asset) return;

        const clearing = (currentFavicon === filename);
        const previous = currentFavicon;

        // Optimistic, then reconciled against the server's answer: the radio
        // has already moved visually by the time the click handler runs, so
        // leaving it stale until the response lands looks like a dropped click.
        currentFavicon = clearing ? null : filename;
        renderGrid();

        try {
            const result = await QuickSiteAdmin.apiRequest('editFavicon', 'POST', {
                imageName: clearing ? null : filename
            });
            if (result.ok) {
                QuickSiteAdmin.showToast(
                    clearing ? t('media.faviconCleared') : t('media.faviconSet', { name: filename }), 'success');
            } else {
                currentFavicon = previous;
                renderGrid();
                QuickSiteAdmin.showToast(result.data?.message || t('media.faviconFailed'), 'error');
            }
        } catch (error) {
            currentFavicon = previous;
            renderGrid();
            QuickSiteAdmin.showToast(t('media.faviconFailedDetail', { message: error.message }), 'error');
        }
    }

    // ─── Batch Delete (Select Mode) ──────────────────────────────────────────
    function initBatchDelete() {
        document.getElementById('asset-batch-delete')?.addEventListener('click', deleteSelected);
        document.getElementById('asset-batch-select-all')?.addEventListener('click', toggleSelectAll);
    }

    function toggleSelectMode() {
        selectMode = !selectMode;
        selectedFiles.clear();
        const btn = document.getElementById('asset-select-mode');
        if (btn) {
            btn.textContent = t(selectMode ? 'media.selectOn' : 'media.selectOff');
            btn.classList.toggle('asset-tabs__select--active', selectMode);
        }
        updateBatchBar();
        renderGrid();
    }

    function updateBatchBar() {
        const bar = document.getElementById('asset-batch-bar');
        const count = document.getElementById('asset-batch-count');
        const selectAllBtn = document.getElementById('asset-batch-select-all');
        const deleteBtn = document.getElementById('asset-batch-delete');
        if (!bar) return;

        if (selectMode) {
            bar.style.display = '';
            count.textContent = selectedFiles.size > 0
                ? t('media.selectedCount', { count: selectedFiles.size })
                : t('media.noneSelected');
            if (deleteBtn) deleteBtn.disabled = selectedFiles.size === 0;
            // Toggle button label
            const visibleAssets = getFilteredAssets();
            const allSelected = visibleAssets.length > 0 && visibleAssets.every(a => selectedFiles.has(a.filename));
            if (selectAllBtn) selectAllBtn.textContent = t(allSelected ? 'media.deselectAll' : 'media.selectAll');
        } else {
            bar.style.display = 'none';
        }
    }

    function toggleSelectAll() {
        const visibleAssets = getFilteredAssets();
        const allSelected = visibleAssets.length > 0 && visibleAssets.every(a => selectedFiles.has(a.filename));
        if (allSelected) {
            // Deselect all visible
            visibleAssets.forEach(a => selectedFiles.delete(a.filename));
        } else {
            // Select all visible
            visibleAssets.forEach(a => selectedFiles.add(a.filename));
        }
        updateBatchBar();
        renderGrid();
    }

    async function deleteSelected() {
        if (selectedFiles.size === 0) return;
        const filenames = Array.from(selectedFiles);
        if (!confirm(t(filenames.length !== 1
            ? 'media.confirmDeleteManyMany'
            : 'media.confirmDeleteManyOne', { count: filenames.length }))) return;

        try {
            const result = await QuickSiteAdmin.apiRequest('deleteAsset', 'POST', { filenames });
            if (result.ok || result.status === 204) {
                QuickSiteAdmin.showToast(t(filenames.length !== 1
                    ? 'media.deletedManyMany'
                    : 'media.deletedManyOne', { count: filenames.length }), 'success');
                selectedFiles.clear();
                toggleSelectMode();
                if (editingAsset && filenames.includes(editingAsset.filename)) closeEditArea();
                await loadAssets();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || t('media.deleteFailed'), 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast(t('media.deleteFailedDetail', { message: error.message }), 'error');
        }
    }

    // ─── Bootstrap ───────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
