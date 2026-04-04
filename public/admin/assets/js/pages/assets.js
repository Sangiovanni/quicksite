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
    let currentlyPlaying = null; // Audio element currently playing
    let editingAsset = null;     // Asset currently being edited
    let fontStyleElements = {};  // Track injected @font-face style elements

    // ─── Init ────────────────────────────────────────────────────────────────
    function init() {
        if (typeof QuickSiteAdmin === 'undefined') {
            setTimeout(init, 50);
            return;
        }
        loadExtensions();
        loadAssets();
        initUploadZone();
        initUrlInputs();
        initQueueControls();
        initBrowser();
        initEditArea();
        initBatchDelete();
    }

    // ─── Data Loading ────────────────────────────────────────────────────────
    async function loadExtensions() {
        try {
            extensionsMap = await QuickSiteAdmin.fetchHelperData('asset-extensions');
            allowedExtensions = Object.values(extensionsMap).flat();
            const hint = document.getElementById('asset-extensions-hint');
            if (hint) hint.textContent = allowedExtensions.join(', ');
            const fileInput = document.getElementById('asset-file-input');
            if (fileInput) fileInput.setAttribute('accept', allowedExtensions.map(e => '.' + e).join(','));
        } catch (e) { /* non-critical */ }
    }

    async function loadAssets() {
        try {
            const result = await QuickSiteAdmin.apiRequest('listAssets', 'GET');
            if (result.ok && result.data?.data?.assets) {
                allAssets = result.data.data.assets;
            } else {
                allAssets = {};
            }
        } catch (e) {
            allAssets = {};
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
        // Build URL from category + filename
        const base = window.QUICKSITE_CONFIG?.baseUrl?.replace(/\/management$/, '') || '';
        return base + '/assets/' + asset.category + '/' + encodeURIComponent(asset.filename);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
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
                    QuickSiteAdmin.showToast('Data URIs not supported \u2014 save the file locally first, then upload it', 'warning');
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
                    QuickSiteAdmin.showToast('Data URIs not supported \u2014 save the file locally first, then upload it', 'warning');
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
            QuickSiteAdmin.showToast(`Unsupported file type: ${name}`, 'warning');
            return;
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
        countEl.textContent = `${uploadQueue.length} file${uploadQueue.length !== 1 ? 's' : ''} ready`;
        btn.disabled = false;

        list.innerHTML = uploadQueue.map((item, i) => `
            <li class="asset-queue__item" data-index="${i}">
                <div class="asset-queue__row">
                    <span class="asset-queue__icon">${getFileIcon(item.category)}</span>
                    <span class="asset-queue__name">${escapeHtml(item.name)}</span>
                    <span class="asset-queue__size">${item.size ? formatSize(item.size) : 'URL'}</span>
                    <span class="asset-queue__category">${escapeHtml(item.category)}</span>
                    <button type="button" class="asset-queue__remove" data-remove="${i}" title="Remove">&times;</button>
                </div>
                <div class="asset-queue__meta">
                    <input type="text" class="admin-input admin-input--sm" data-queue-field="alt" data-queue-index="${i}"
                           placeholder="Alt text" autocomplete="off" value="${escapeHtml(item.alt)}">
                    <input type="text" class="admin-input admin-input--sm" data-queue-field="description" data-queue-index="${i}"
                           placeholder="Description" autocomplete="off" value="${escapeHtml(item.description)}">
                </div>
            </li>
        `).join('');
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

    async function uploadAll() {
        if (uploadQueue.length === 0) return;

        const btn = document.getElementById('asset-upload-btn');
        const progressDiv = document.getElementById('asset-upload-progress');
        btn.disabled = true;
        btn.textContent = 'Uploading...';
        progressDiv.style.display = '';
        progressDiv.innerHTML = '';

        const total = uploadQueue.length;
        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < total; i++) {
            const item = uploadQueue[i];
            const line = document.createElement('div');
            line.className = 'asset-upload-progress__item';
            line.innerHTML = `<span>${escapeHtml(item.name)}</span><span class="asset-upload-progress__status">Uploading ${i + 1}/${total}...</span>`;
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
                    statusEl.innerHTML = `<span style="color:var(--admin-success)">→ ${item.category}/ ✓</span>`;
                } else {
                    failCount++;
                    statusEl.innerHTML = `<span style="color:var(--admin-error)">✗ ${result.data?.message || 'Failed'}</span>`;
                }
            } catch (error) {
                failCount++;
                statusEl.innerHTML = `<span style="color:var(--admin-error)">✗ ${error.message}</span>`;
            }
        }

        // Summary toast
        if (failCount === 0) {
            QuickSiteAdmin.showToast(`${successCount} file${successCount !== 1 ? 's' : ''} uploaded`, 'success');
        } else if (successCount === 0) {
            QuickSiteAdmin.showToast(`All ${failCount} uploads failed`, 'error');
        } else {
            QuickSiteAdmin.showToast(`${successCount} uploaded, ${failCount} failed`, 'warning');
        }

        // Clear queue and refresh browser
        uploadQueue = [];
        renderQueue();
        btn.textContent = 'Upload All';
        btn.disabled = true;
        await loadAssets();
    }

    // ─── Asset Browser ───────────────────────────────────────────────────────
    function initBrowser() {
        // Category tabs
        document.getElementById('asset-tabs')?.addEventListener('click', (e) => {
            const tab = e.target.closest('.asset-tabs__tab');
            if (!tab || tab.id === 'asset-select-mode') return;
            document.querySelectorAll('.asset-tabs__tab:not(.asset-tabs__select)').forEach(t => t.classList.remove('asset-tabs__tab--active'));
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
            grid.innerHTML = '';
            if (empty) {
                empty.style.display = '';
                if (emptyText) {
                    if (searchQuery) {
                        emptyText.textContent = `No assets matching '${searchQuery}'.`;
                    } else if (activeCategory !== 'all') {
                        emptyText.textContent = `No ${activeCategory} found.`;
                    } else {
                        emptyText.textContent = 'No assets yet. Drop files above to get started.';
                    }
                }
            }
            return;
        }

        if (empty) empty.style.display = 'none';

        grid.innerHTML = assets.map(asset => renderCard(asset)).join('');

        // Inject @font-face for font assets
        assets.filter(a => a.category === 'font').forEach(injectFontFace);
    }

    function renderCard(asset) {
        const thumb = renderThumb(asset);
        const isSelected = selectedFiles.has(asset.filename);
        const checkboxHtml = selectMode
            ? `<label class="asset-card__checkbox"><input type="checkbox" data-select="${escapeHtml(asset.filename)}" ${isSelected ? 'checked' : ''}></label>`
            : '';

        const actionsHtml = selectMode ? '' : `
                    <button type="button" class="asset-card__action asset-card__rename" data-rename="${escapeHtml(asset.filename)}" title="Rename">✏️</button>`;
        const starHtml = selectMode ? '' : `<button type="button" class="asset-card__star${asset.starred ? ' asset-card__star--active' : ''}" data-star="${escapeHtml(asset.filename)}" title="${asset.starred ? 'Unstar' : 'Star'}">${asset.starred ? '⭐' : '☆'}</button>`;
        const infoActionsHtml = selectMode ? '' : `
                    <div class="asset-card__actions">
                        <button type="button" class="asset-card__action" data-edit="${escapeHtml(asset.filename)}" title="Edit alt/description">✏️</button>
                        <button type="button" class="asset-card__action asset-card__delete" data-delete="${escapeHtml(asset.filename)}" title="Delete">🗑️</button>
                    </div>`;

        return `
        <div class="asset-card${isSelected ? ' asset-card--selected' : ''}${selectMode ? ' asset-card--selectable' : ''}" data-filename="${escapeHtml(asset.filename)}" data-category="${asset.category}">
            ${checkboxHtml}
            <div class="asset-card__thumb">${thumb}${starHtml}</div>
            <div class="asset-card__footer">
                <div class="asset-card__name">
                    <span class="asset-card__name-text" title="${escapeHtml(asset.filename)}">${escapeHtml(stripExtension(asset.filename))}</span>${actionsHtml}
                </div>
                <div class="asset-card__info">
                    <span class="asset-card__size">${formatSize(asset.size)}${activeCategory === 'all' ? ' · <span class="asset-card__category">' + escapeHtml(asset.category) + '</span>' : ''}</span>${infoActionsHtml}
                </div>
            </div>
        </div>`;
    }

    function renderThumb(asset) {
        const url = getAssetUrl(asset);
        switch (asset.category) {
            case 'images':
                return `<img src="${url}" alt="${escapeHtml(asset.alt || asset.filename)}" loading="lazy" class="asset-card__image">`;
            case 'font':
                return `<span class="asset-font-preview" style="font-family:'qs-font-${escapeHtml(asset.filename)}'">AaBbCc</span>`;
            case 'audio':
                return `
                    <div class="asset-audio-player" data-audio-src="${url}">
                        <button type="button" class="asset-audio-player__btn" data-play-audio="${escapeHtml(asset.filename)}">▶</button>
                        <div class="asset-audio-player__bar"><div class="asset-audio-player__progress"></div></div>
                        <audio preload="none" src="${url}"></audio>
                    </div>`;
            case 'videos':
                return `<video src="${url}" class="asset-card__video" preload="metadata" muted></video>
                        <button type="button" class="asset-video-overlay" data-play-video="${escapeHtml(asset.filename)}">▶</button>`;
            default:
                return `<span class="asset-card__icon-fallback">${getFileIcon(asset.category)}</span>`;
        }
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

        // Star toggle button
        const starBtn = e.target.closest('[data-star]');
        if (starBtn) {
            toggleStar(starBtn.dataset.star);
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
                    QuickSiteAdmin.showToast(`Renamed to ${newFilename} ✓`, 'success');
                    await loadAssets();
                } else {
                    QuickSiteAdmin.showToast(result.data?.message || 'Rename failed', 'error');
                    cleanup();
                }
            } catch (error) {
                QuickSiteAdmin.showToast('Rename failed: ' + error.message, 'error');
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

        title.textContent = `Edit: ${asset.filename}`;
        altInput.value = asset.alt || '';
        descInput.value = asset.description || '';
        status.textContent = '';

        // Preview
        const url = getAssetUrl(asset);
        switch (asset.category) {
            case 'images':
                preview.innerHTML = `<img src="${url}" alt="${escapeHtml(asset.alt || '')}" class="asset-edit-area__image">`;
                break;
            case 'font':
                injectFontFace(asset);
                preview.innerHTML = `<span class="asset-edit-area__font" style="font-family:'qs-font-${escapeHtml(asset.filename)}'">AaBbCc</span>`;
                break;
            case 'audio':
                preview.innerHTML = `<audio controls src="${url}" style="width:100%"></audio>`;
                break;
            case 'videos':
                preview.innerHTML = `<video controls src="${url}" style="width:100%;max-height:240px"></video>`;
                break;
            default:
                preview.innerHTML = `<span style="font-size:3rem">${getFileIcon(asset.category)}</span>`;
        }

        // Info
        const dims = (asset.width && asset.height) ? ` · ${asset.width}×${asset.height}` : '';
        info.innerHTML = `
            <p><strong>Category:</strong> ${escapeHtml(asset.category)}</p>
            <p><strong>Size:</strong> ${formatSize(asset.size)}${dims}${asset.mime_type ? ' · ' + escapeHtml(asset.mime_type) : ''}</p>
            ${asset.modified ? '<p><strong>Modified:</strong> ' + escapeHtml(asset.modified) + '</p>' : ''}
        `;

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
            status.textContent = 'No changes.';
            return;
        }

        saveBtn.disabled = true;
        status.textContent = 'Saving...';

        try {
            const result = await QuickSiteAdmin.apiRequest('editAsset', 'POST', data);
            if (result.ok) {
                status.innerHTML = '<span style="color:var(--admin-success)">Saved ✓</span>';
                QuickSiteAdmin.showToast(`${editingAsset.filename} updated ✓`, 'success');
                await loadAssets();
                // Keep edit area open with refreshed data
                const refreshed = flatAssets.find(a => a.filename === editingAsset.filename);
                if (refreshed) openEditArea(refreshed.filename);
            } else {
                status.innerHTML = `<span style="color:var(--admin-error)">${result.data?.message || 'Failed'}</span>`;
            }
        } catch (error) {
            status.innerHTML = `<span style="color:var(--admin-error)">${error.message}</span>`;
        }
        saveBtn.disabled = false;
    }

    // ─── Single Delete ───────────────────────────────────────────────────────
    async function deleteSingle(filename) {
        if (!confirm(`Delete ${filename}?`)) return;

        try {
            const result = await QuickSiteAdmin.apiRequest('deleteAsset', 'POST', { filename });
            if (result.ok || result.status === 204) {
                QuickSiteAdmin.showToast(`${filename} deleted ✓`, 'success');
                if (editingAsset?.filename === filename) closeEditArea();
                await loadAssets();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Delete failed', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Delete failed: ' + error.message, 'error');
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
                QuickSiteAdmin.showToast('Maximum 15 starred assets. Unstar one first.', 'warning');
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
                QuickSiteAdmin.showToast(result.data?.message || 'Star toggle failed', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Star toggle failed: ' + error.message, 'error');
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
            btn.innerHTML = selectMode ? '&#9745; Select' : '&#9744; Select';
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
            count.textContent = selectedFiles.size > 0 ? `${selectedFiles.size} selected` : 'None selected';
            if (deleteBtn) deleteBtn.disabled = selectedFiles.size === 0;
            // Toggle button label
            const visibleAssets = getFilteredAssets();
            const allSelected = visibleAssets.length > 0 && visibleAssets.every(a => selectedFiles.has(a.filename));
            if (selectAllBtn) selectAllBtn.textContent = allSelected ? 'Deselect All' : 'Select All';
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
        if (!confirm(`Delete ${filenames.length} asset${filenames.length !== 1 ? 's' : ''}? This cannot be undone.`)) return;

        try {
            const result = await QuickSiteAdmin.apiRequest('deleteAsset', 'POST', { filenames });
            if (result.ok || result.status === 204) {
                QuickSiteAdmin.showToast(`${filenames.length} file${filenames.length !== 1 ? 's' : ''} deleted ✓`, 'success');
                selectedFiles.clear();
                toggleSelectMode();
                if (editingAsset && filenames.includes(editingAsset.filename)) closeEditArea();
                await loadAssets();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Delete failed', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Delete failed: ' + error.message, 'error');
        }
    }

    // ─── Bootstrap ───────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
