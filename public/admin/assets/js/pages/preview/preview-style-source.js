/**
 * Preview — Style Source view
 *
 * UI path: the CSS tool in the sidebar → the **Source** button above the Theme / Selectors / Motion
 * tabs. It opens the whole style.css in QSCodeEditor, mounted into #preview-source-canvas-mount; the
 * canvas takes the preview iframe's place while Source is shown.
 *
 * What Source shows. It reads style.css (getStyles) every time it is opened. With no unsaved edits it
 * shows the file as it is now. With unsaved edits on a version that has changed since — saved from the
 * Theme, Selectors or Motion tab, another window or another person — it keeps the edits and shows the
 * conflict notice (Reload / Overwrite). Save reads the file again first and writes the whole file
 * (editStyles) only over the version the edits are based on; a change that lands between that read and
 * the write is reported from the save's answer, which carries the content it replaced.
 *
 * Public API:
 *   PreviewStyleSource.init()         — wire DOM refs (called once on load)
 *   PreviewStyleSource.enter()        — Source becomes active (mounts on the first entry, reads the file again on every later one)
 *   PreviewStyleSource.leave()        — Source deactivates; unsaved edits are kept
 *   PreviewStyleSource.isActive()     — current activation state
 *   PreviewStyleSource.getEditor()    — QSCodeEditor instance or null
 *   PreviewStyleSource.isDirty() / canLeave() / save() / cancel()
 */
(function () {
    'use strict';

    var _initialized = false;
    var _active      = false;

    var _mountEl   = null;
    var _loadingEl = null;
    var _editor    = null;
    var _loading   = false;   // first fetch in flight

    // _serverContent is the version of style.css the editor's content is based on: the file as Source
    // last read it with no unsaved edits, or last saved it. The editor differing from it is what
    // "unsaved" means, and it drives the dirty indicator + Save/Cancel button state.
    var _serverContent = '';
    var _isDirty       = false;
    var _isSaving      = false;
    // Set when style.css was read while edits were unsaved and it is no longer the version they are
    // based on: { content: the file as read }. The conflict notice stands while it is set.
    var _conflict      = null;
    // Every read and every save takes the next number. A read's answer is applied only while it is the
    // latest, so an opening's read that answers after a save cannot put an older file back.
    var _readSeq       = 0;
    var _draftTimer    = null;
    // Debounce timer for the iframe <style> injection. Cleared on save / reload so the injection is
    // never written after those state-resync actions.
    var _injectTimer   = null;
    // When true, the next page-unload beforeunload event is allowed to proceed without prompting. Set
    // after the user explicitly confirms a same-tab navigation (the Refine link) — otherwise they'd
    // get our custom confirm AND the native browser prompt.
    var _navigationConsented = false;

    // Sidebar Save/Cancel + dirty UI refs
    var _saveBtn       = null;
    var _cancelBtn     = null;
    var _saveLabel     = null;
    var _statusEl      = null;
    var _statusTextEl  = null;
    var _refineLink    = null;

    // Draft-restore banner refs in the canvas, and the draft it offers
    var _restoreBanner     = null;
    var _restoreDetail     = null;
    var _restoreAcceptBtn  = null;
    var _restoreDeclineBtn = null;
    var _offeredDraft      = null;

    // Conflict notice refs in the canvas
    var _conflictNotice       = null;
    var _conflictReloadBtn    = null;
    var _conflictOverwriteBtn = null;

    // Search state. Empty query → no search active.
    var _search = {
        input:        null,
        countEl:      null,
        prevBtn:      null,
        nextBtn:      null,
        query:        '',
        matches:      [],
        currentIdx:   -1,
        debounceTimer: null
    };

    function init() {
        if (_initialized) return;
        _initialized = true;
        _mountEl   = document.getElementById('preview-source-canvas-mount');
        _loadingEl = document.getElementById('preview-source-canvas-loading');
        _search.input   = document.getElementById('preview-source-search-input');
        _search.countEl = document.getElementById('preview-source-search-count');
        _search.prevBtn = document.getElementById('preview-source-search-prev');
        _search.nextBtn = document.getElementById('preview-source-search-next');
        _saveBtn       = document.getElementById('source-sidebar-save-btn');
        _cancelBtn     = document.getElementById('source-sidebar-cancel-btn');
        _saveLabel     = document.getElementById('source-sidebar-save-label');
        _statusEl      = document.getElementById('source-sidebar-status');
        _statusTextEl  = document.getElementById('source-sidebar-status-text');
        _refineLink    = document.getElementById('source-sidebar-refine-link');
        _restoreBanner     = document.getElementById('preview-source-restore-banner');
        _restoreDetail     = document.getElementById('preview-source-restore-detail');
        _restoreAcceptBtn  = document.getElementById('preview-source-restore-accept');
        _restoreDeclineBtn = document.getElementById('preview-source-restore-decline');
        _conflictNotice       = document.getElementById('preview-source-conflict-notice');
        _conflictReloadBtn    = document.getElementById('preview-source-conflict-reload');
        _conflictOverwriteBtn = document.getElementById('preview-source-conflict-overwrite');
        wireSearchHandlers();
        wireSaveCancelHandlers();
        wireCanvasHandlers();
    }

    function enter() {
        if (_active) return;
        _active = true;
        if (!_editor) {
            // First entry: fetch + mount. A failed fetch leaves no editor, so the next entry tries again.
            if (_loading) return;
            _loading = true;
            fetchStyles()
                .then(mountEditor)
                .catch(renderError)
                .then(function () {
                    _loading = false;
                });
            return;
        }
        // Later entries reuse the editor. Reset scroll to the top before focusing — when the canvas goes
        // display:none and back, browsers can reset the textarea's scrollTop while the <pre> overlay
        // (set programmatically) keeps its position, leaving the layers out of sync. Resetting both to 0
        // keeps them aligned.
        try { _editor.resetScroll(); } catch (e) { /* no-op */ }
        try { _editor.focus();       } catch (e) { /* no-op */ }
        // The iframe may have reloaded while Source was away, which drops the injection of unsaved edits.
        syncLiveStyles();
        // Every opening reads the file again: whatever wrote it meanwhile, Source shows it or says so.
        rereadStyles();
    }

    function leave() {
        if (!_active) return;
        _active = false;
        // Unsaved edits stay — in the editor, in the draft, and in the preview's injection, which keeps
        // showing them while the user looks at the other tabs or modes. Only save / reload / cancel put
        // the preview back on the file.
    }

    function isActive() {
        return _active;
    }

    function getEditor() {
        return _editor;
    }

    // ── Internals ──

    // A panel string by its PreviewConfig.i18n name. A missing one shows its name, so it is seen and
    // keyed rather than hidden.
    function t(name) {
        var value = PreviewConfig.i18n[name];
        return (typeof value === 'string') ? value : name;
    }

    function errorText(err) {
        return (err && err.message) || t('styleSourceUnknownError');
    }

    function fetchStyles() {
        return QuickSiteAPI.request('getStyles', 'GET').then(function (result) {
            var env = result.data || {};
            if (result.ok && env.data && typeof env.data.content === 'string') {
                return env.data.content;
            }
            throw new Error(env.message || env.error || t('styleSourceLoadError'));
        });
    }

    function mountEditor(content) {
        if (!_mountEl) return;
        // Hide the loading indicator. The mount call clears the mount element, but doing this first
        // avoids a flash.
        if (_loadingEl) _loadingEl.style.display = 'none';
        _editor = QSCodeEditor.create({
            mount:    _mountEl,
            value:    content,
            tokenize: QSCodeEditor.tokenizers.css,
            onChange: handleChange
        });
        _serverContent = content;
        updateDirty();
        try { _editor.focus(); } catch (e) { /* no-op */ }
        // Offer to restore an unsaved draft of this project if one exists and differs from the file.
        offerDraft();
    }

    function handleChange(/* newValue */) {
        // Re-run the current search when the textarea content changes — existing match positions
        // become stale on any edit.
        if (_search.query) runSearch(_search.query, /* preserveIdx */ true);
        updateDirty();
        schedulePersistDraft();
        // Debounced <style> sync into the iframe, so the preview reflects unsaved edits the moment
        // Source is exited.
        scheduleInjectLiveStyles();
    }

    function hasUnsavedEdits() {
        return !!_editor && _editor.getValue() !== _serverContent;
    }

    // ── Reading the file again ──

    function rereadStyles() {
        var seq = ++_readSeq;
        fetchStyles().then(function (content) {
            if (seq === _readSeq) reconcile(content);
        }, function (err) {
            // Source could not check the file: it keeps what it shows, and says so.
            if (seq === _readSeq) QuickSiteUtils.showToast(t('styleSourceLoadError') + ': ' + errorText(err), 'error');
        });
    }

    // What a read found, applied to the editor.
    function reconcile(content) {
        if (!_editor) return;
        if (content === _serverContent) {
            // The file is the version the edits are based on: a notice about another version no longer
            // applies (that change was undone).
            setConflict(null);
            return;
        }
        if (!hasUnsavedEdits()) {
            showFile(content);
            return;
        }
        // Unsaved edits on a version that has changed since: keep them, and say so.
        setConflict(content);
    }

    // No unsaved edits: show style.css as it is now.
    function showFile(content) {
        _serverContent = content;
        _editor.setValue(content);
        setConflict(null);
        updateDirty();
        if (_search.query) runSearch(_search.query, /* preserveIdx */ true);
        syncLiveStyles();
        renderRestoreBanner();
    }

    function setConflict(content) {
        _conflict = (content === null) ? null : { content: content };
        if (_conflictNotice) _conflictNotice.style.display = _conflict ? '' : 'none';
        renderDirtyUI();
    }

    // ── Dirty / Save / Cancel ──

    function updateDirty() {
        if (!_editor) return;
        _isDirty = hasUnsavedEdits();
        renderDirtyUI();
    }

    function renderDirtyUI() {
        var flagged = _isDirty || !!_conflict;
        if (_statusEl) {
            _statusEl.classList.toggle('preview-source-sidebar__status--dirty', flagged);
            _statusEl.classList.toggle('preview-source-sidebar__status--clean', !flagged);
        }
        if (_statusTextEl) {
            _statusTextEl.textContent = _conflict ? t('styleSourceConflictStatus')
                : (_isDirty ? t('styleSourceDirty') : t('styleSourceClean'));
        }
        // While the conflict notice stands, Save waits for the user's choice in it.
        if (_saveBtn)   _saveBtn.disabled   = !_isDirty || _isSaving || !!_conflict;
        if (_cancelBtn) _cancelBtn.disabled = !_isDirty || _isSaving;
        if (_conflictReloadBtn)    _conflictReloadBtn.disabled    = _isSaving;
        if (_conflictOverwriteBtn) _conflictOverwriteBtn.disabled = !_isDirty || _isSaving;
    }

    function setSaveLabel(saving) {
        if (!_saveLabel) return;
        _saveLabel.textContent = saving ? t('styleSourceSaving') : t('styleSourceSave');
    }

    function wireSaveCancelHandlers() {
        if (_saveBtn)    _saveBtn.addEventListener('click', saveStyles);
        if (_cancelBtn)  _cancelBtn.addEventListener('click', cancelEdit);
        if (_refineLink) _refineLink.addEventListener('click', onRefineClick);
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    function wireCanvasHandlers() {
        if (_restoreAcceptBtn)     _restoreAcceptBtn.addEventListener('click', restoreDraft);
        if (_restoreDeclineBtn)    _restoreDeclineBtn.addEventListener('click', discardDraft);
        if (_conflictReloadBtn)    _conflictReloadBtn.addEventListener('click', reloadFromServer);
        if (_conflictOverwriteBtn) _conflictOverwriteBtn.addEventListener('click', overwrite);
    }

    function onBeforeUnload(e) {
        // The user already confirmed a same-tab navigation (e.g. Refine link) — let it through without
        // the native browser prompt.
        if (_navigationConsented) {
            _navigationConsented = false;
            return;
        }
        if (_isDirty) {
            // What was typed in the last half second is in the draft too, whatever the user answers.
            flushDraft();
            // Modern browsers show their own generic prompt; setting returnValue is what triggers it.
            e.preventDefault();
            e.returnValue = '';
        }
    }

    function onRefineClick(e) {
        // Same-tab navigation: warn if dirty so the user doesn't lose edits silently. Modifier-clicks
        // (open in new tab / window) and middle-clicks should bypass the warning since they don't leave
        // the current page.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        if (!_isDirty) return;
        if (!window.confirm(t('styleSourceLeaveConfirm'))) {
            e.preventDefault();
            return;
        }
        // User confirmed — the draft takes the edits (Source offers them back on return), and the
        // native beforeunload prompt is suppressed so they are not asked twice.
        flushDraft();
        _navigationConsented = true;
    }

    // Build a save-error message that surfaces the SPECIFIC reason the server gave (e.g. "Remote
    // @import … blocked") from the response envelope's errors[], not just the generic top-line message.
    function saveErrorMessage(data) {
        data = data || {};
        var base = data.message || data.error || t('styleSourceUnknownError');
        var e = data.errors && data.errors[0];
        var detail = e && (e.pattern || e.hint || e.reason);
        return detail ? base + ' — ' + detail : base;
    }

    function saveStyles() {
        if (!_editor || _isSaving || !_isDirty || _conflict) return;
        var content = _editor.getValue();
        var base = _serverContent;
        // An opening's read still in flight must not answer into the editor after this save.
        ++_readSeq;
        _isSaving = true;
        setSaveLabel(true);
        renderDirtyUI();
        // Never write over a version these edits are not based on: read the file first, and write only
        // if it is still that version.
        fetchStyles().then(function (current) {
            if (current !== base) {
                setConflict(current);
                QuickSiteUtils.showToast(t('styleSourceConflictNotSaved'), 'warning');
                return null;
            }
            return QuickSiteAPI.request('editStyles', 'POST', { content: content });
        }, function (err) {
            QuickSiteUtils.showToast(t('styleSourceCheckFailed').replace('{error}', errorText(err)), 'error');
            return null;
        }).then(function (result) {
            if (!result) return;
            if (!result.ok) {
                throw new Error(saveErrorMessage(result.data));
            }
            onSaved(content, base, result.data);
        }).catch(function (err) {
            QuickSiteUtils.showToast(t('styleSourceSaveError').replace('{error}', errorText(err)), 'error');
        }).then(function () {
            _isSaving = false;
            setSaveLabel(false);
            renderDirtyUI();
        });
    }

    function onSaved(content, base, env) {
        _serverContent = content;
        // editStyles answers with the content it replaced, read just before its write. Anything but the
        // version this save was based on means a change landed between the check and the write.
        var replaced = (env && env.data && typeof env.data.backup_content === 'string') ? env.data.backup_content : null;
        // Whether the save succeeded was decided by the server, and it has already answered. Everything
        // in this block is LOCAL follow-up work — clearing the draft, syncing the injection, making the
        // iframe re-fetch its stylesheet, invalidating the other tabs' caches. It is isolated so a local
        // refresh failure cannot contradict the server's verdict with a second toast; it is reported to
        // the console, where it belongs.
        try {
            updateDirty();
            clearDraft();
            if (_injectTimer) { clearTimeout(_injectTimer); _injectTimer = null; }
            // Saved content is now authoritative: the injection has nothing left to add (unless the user
            // typed on while the save was in flight), and the iframe's <link rel="stylesheet"> re-fetches.
            syncLiveStyles();
            PreviewState.hotReloadCss();
            // A Source save can change anything in style.css — including :root variables that the other
            // structured tabs cache. Mark their caches stale so the next view re-fetches.
            invalidateStructuredTabs();
        } catch (e) {
            console.error('[style-source] post-save refresh failed', e);
        }
        // Last, and outside the try: one save produces exactly one toast, and it reports what the server
        // did.
        if (replaced !== null && replaced !== base) {
            QuickSiteUtils.showToast(t('styleSourceSavedOverChange'), 'warning');
        } else {
            QuickSiteUtils.showToast(t('styleSourceSaved'), 'success');
        }
    }

    // The conflict notice's Overwrite: the version the notice spoke about becomes the base, so the save
    // writes over exactly that version — and stops again if yet another change has landed since.
    function overwrite() {
        if (!_conflict || _isSaving) return;
        _serverContent = _conflict.content;
        setConflict(null);
        updateDirty();
        saveStyles();
    }

    function cancelEdit() {
        if (!_isDirty || _isSaving) return;
        if (!window.confirm(t('styleSourceCancelConfirm'))) return;
        reloadFromServer();
    }

    // Discard the unsaved edits and load style.css as it is now: the conflict notice's Reload, and
    // Cancel once confirmed.
    function reloadFromServer() {
        if (_isSaving || !_editor) return;
        var seq = ++_readSeq;
        fetchStyles().then(function (content) {
            if (seq !== _readSeq) return;
            clearDraft();
            if (_injectTimer) { clearTimeout(_injectTimer); _injectTimer = null; }
            showFile(content);
            // The iframe's stylesheet is re-fetched, and the other tabs' caches are marked stale: the
            // file may differ from what they hold.
            PreviewState.hotReloadCss();
            invalidateStructuredTabs();
        }).catch(function (err) {
            QuickSiteUtils.showToast(t('styleSourceLoadError') + ': ' + errorText(err), 'error');
        });
    }

    // ── Draft persist ──
    // The editor's content is written to localStorage (debounced ~500ms) whenever it differs from the
    // version it is based on, together with that version and the project. A draft is offered back only
    // in its own project, and one made on a version that has changed since restores with the conflict
    // notice standing. Cleared on save / reload / cancel / discard.

    function getDraftKey() {
        return QuickSiteStorageKeys.styleSourceDraft;
    }

    function schedulePersistDraft() {
        if (_draftTimer) clearTimeout(_draftTimer);
        _draftTimer = setTimeout(persistDraft, 500);
    }

    function persistDraft() {
        _draftTimer = null;
        if (!_editor) return;
        var content = _editor.getValue();
        if (content === _serverContent) {
            clearDraft();
            return;
        }
        try {
            localStorage.setItem(getDraftKey(), JSON.stringify({
                content: content,
                base:    _serverContent,
                project: PreviewConfig.currentProject,
                savedAt: Date.now()
            }));
        } catch (e) {
            // localStorage may be full or disabled — silent failure is OK, the editor still works without
            // draft persistence.
        }
    }

    // Write a pending draft now rather than at the end of its debounce: the page is being left.
    function flushDraft() {
        if (_draftTimer) {
            clearTimeout(_draftTimer);
            persistDraft();
        }
    }

    function clearDraft() {
        if (_draftTimer) { clearTimeout(_draftTimer); _draftTimer = null; }
        try { localStorage.removeItem(getDraftKey()); } catch (e) { /* no-op */ }
    }

    // The stored draft, when it belongs to this project. Another project's draft is left for it; one
    // without its base or its project cannot be checked, and is dropped.
    function readDraft() {
        var parsed;
        try {
            var raw = localStorage.getItem(getDraftKey());
            if (!raw) return null;
            parsed = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!parsed || typeof parsed.content !== 'string'
            || typeof parsed.base !== 'string' || typeof parsed.project !== 'string') {
            clearDraft();
            return null;
        }
        return (parsed.project === PreviewConfig.currentProject) ? parsed : null;
    }

    function offerDraft() {
        var draft = readDraft();
        if (!draft) return;
        if (draft.content === _serverContent) {
            // The draft is the file as it is now — nothing to restore.
            clearDraft();
            return;
        }
        _offeredDraft = draft;
        renderRestoreBanner();
    }

    function renderRestoreBanner() {
        if (!_restoreBanner) return;
        if (!_offeredDraft) {
            _restoreBanner.style.display = 'none';
            return;
        }
        var when = new Date(_offeredDraft.savedAt || Date.now()).toLocaleString();
        var tpl = (_offeredDraft.base === _serverContent) ? t('styleSourceRestoreDetail') : t('styleSourceRestoreDetailChanged');
        if (_restoreDetail) _restoreDetail.textContent = tpl.replace('{time}', when);
        _restoreBanner.style.display = '';
    }

    // The banner's Restore: the draft's edits come back on the version they were made on. If the file has
    // changed since, the conflict notice stands at once.
    function restoreDraft() {
        var draft = _offeredDraft;
        if (!draft || !_editor || _isSaving) return;
        _offeredDraft = null;
        renderRestoreBanner();
        var current = _serverContent;
        _serverContent = draft.base;
        _editor.setValue(draft.content);
        updateDirty();
        setConflict((_isDirty && draft.base !== current) ? current : null);
        if (_search.query) runSearch(_search.query, /* preserveIdx */ true);
        // setValue() doesn't fire handleChange (it's an input-event callback), so the injection is
        // synced here. The draft stays in localStorage — it's still the user's working copy until they
        // save, reload or cancel.
        syncLiveStyles();
    }

    // The banner's Discard. The stored draft is cleared only while it is still the one offered: edits
    // typed since the banner appeared have replaced it with the current draft.
    function discardDraft() {
        var offered = _offeredDraft;
        _offeredDraft = null;
        renderRestoreBanner();
        var stored = readDraft();
        if (offered && stored && stored.savedAt === offered.savedAt) clearDraft();
    }

    // ── Live iframe injection ──
    // While the editor holds unsaved edits, its content is mirrored into a
    // <style id="qs-source-live-styles"> appended to the iframe's <head>. The iframe is hidden while
    // Source is shown, so this isn't "visible while typing" — but the moment the user exits Source (tab
    // click, mode switch), the iframe shows the unsaved edits already applied. With no unsaved edits
    // there is no injection, so the preview shows the file itself, including what the other tabs write.
    //
    // Lifecycle: synced on every edit (debounced), on re-entry (the iframe may have reloaded) and on
    // restore; gone once the edits are saved, reloaded or cancelled, which then make the iframe's
    // <link rel="stylesheet"> re-fetch the file.

    var LIVE_INJECT_ID = 'qs-source-live-styles';

    function getIframeDoc() {
        var iframe = document.getElementById('preview-iframe');
        if (!iframe) return null;
        try {
            return iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
        } catch (e) {
            // Cross-origin or mid-navigation — silent caller-side check.
            return null;
        }
    }

    function injectLiveStyles(content) {
        try {
            var doc = getIframeDoc();
            if (!doc || !doc.head) return;
            var tag = doc.getElementById(LIVE_INJECT_ID);
            if (!tag) {
                tag = doc.createElement('style');
                tag.id = LIVE_INJECT_ID;
                doc.head.appendChild(tag);
            }
            tag.textContent = (content == null) ? '' : String(content);
        } catch (e) {
            // Silent — iframe may be navigating or unattached.
        }
    }

    function removeLiveStyles() {
        try {
            var doc = getIframeDoc();
            if (!doc) return;
            var tag = doc.getElementById(LIVE_INJECT_ID);
            if (tag && tag.parentNode) tag.parentNode.removeChild(tag);
        } catch (e) { /* silent */ }
    }

    // The preview carries Source's content only while it holds unsaved edits.
    function syncLiveStyles() {
        if (hasUnsavedEdits()) injectLiveStyles(_editor.getValue());
        else removeLiveStyles();
    }

    function scheduleInjectLiveStyles() {
        if (_injectTimer) clearTimeout(_injectTimer);
        _injectTimer = setTimeout(function () {
            _injectTimer = null;
            syncLiveStyles();
        }, 200);
    }

    // ── Stale-cache invalidation for sibling style tabs ──
    // Source writes the whole stylesheet, so anything the other Style tabs had cached can be stale after
    // a save (or after a reload re-fetches the current server file). Each sibling module exposes its own
    // invalidate / reset hook; a failure in one must not stop the others.

    function invalidateStructuredTabs() {
        try { PreviewStyleTheme.invalidate(); } catch (e) { /* no-op */ }
        try { PreviewSelectorBrowser.reset(); } catch (e) { /* no-op */ }
        try { PreviewStyleMotion.reset();     } catch (e) { /* no-op */ }
    }

    // ── Cross-tab / cross-mode guard ──
    // Returns true if the caller may leave Source; false if the user answered the prompt by staying.
    // Leaving discards nothing: the prompt says where unsaved edits go.
    function canLeave() {
        if (!_isDirty) return true;
        if (_isSaving) return true;  // let in-flight save finish naturally
        return window.confirm(t('styleSourceSwitchConfirm'));
    }

    // ── Search ──

    function wireSearchHandlers() {
        if (_search.input) {
            _search.input.addEventListener('input', onSearchInput);
            _search.input.addEventListener('keydown', onSearchKeydown);
        }
        if (_search.prevBtn) {
            _search.prevBtn.addEventListener('click', function () { navigateSearch(-1); });
        }
        if (_search.nextBtn) {
            _search.nextBtn.addEventListener('click', function () { navigateSearch(1); });
        }
        // Global shortcuts only fire when Source is active.
        document.addEventListener('keydown', onGlobalKeydown);
    }

    function onSearchInput() {
        var v = _search.input.value;
        // Debounce typing — 80ms is snappy without rerunning on every key
        // for long files.
        if (_search.debounceTimer) clearTimeout(_search.debounceTimer);
        _search.debounceTimer = setTimeout(function () {
            // ':N' is reserved for jump-to-line — don't run search on it.
            // The actual jump happens on Enter.
            if (/^:\d+$/.test(v)) {
                clearSearchHighlights();
                updateSearchCount(null, null);
                return;
            }
            runSearch(v, /* preserveIdx */ false);
        }, 80);
    }

    function onSearchKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeSearch();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            var v = _search.input.value;
            // Jump-to-line: ':' followed by digits → go to line N.
            var lineMatch = v.match(/^:(\d+)$/);
            if (lineMatch) {
                jumpToLine(parseInt(lineMatch[1], 10));
                return;
            }
            // Otherwise navigate matches.
            if (e.shiftKey) navigateSearch(-1);
            else            navigateSearch(1);
        }
    }

    function onGlobalKeydown(e) {
        if (!_active) return;
        // Ctrl+F / Cmd+F → focus find input.
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            if (_search.input) {
                e.preventDefault();
                _search.input.focus();
                _search.input.select();
            }
            return;
        }
        // Ctrl+G / Cmd+G → focus find input with ':' prefilled for line jump.
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'g') {
            if (_search.input) {
                e.preventDefault();
                _search.input.value = ':';
                _search.input.focus();
                // Position caret AFTER the ':' so user can type digits.
                var pos = _search.input.value.length;
                try { _search.input.setSelectionRange(pos, pos); } catch (er) {}
                // Clear any existing matches — ':' alone isn't a search.
                clearSearchHighlights();
                updateSearchCount(null, null);
            }
            return;
        }
    }

    function runSearch(query, preserveIdx) {
        _search.query = query || '';
        if (!_editor || !_search.query) {
            _search.matches = [];
            _search.currentIdx = -1;
            clearSearchHighlights();
            updateSearchCount(null, null);
            setNavDisabled(true);
            return;
        }
        var text = _editor.getValue();
        var matches = findAll(text, _search.query);
        _search.matches = matches;
        if (matches.length === 0) {
            _search.currentIdx = -1;
            clearSearchHighlights();
            updateSearchCount(0, 0);
            setNavDisabled(true);
            return;
        }
        // On a fresh search, pick the first match at or after the caret.
        // On preserve (e.g. textarea edited), keep the same index if valid.
        var idx = preserveIdx && _search.currentIdx >= 0
            ? Math.min(_search.currentIdx, matches.length - 1)
            : pickInitialMatch(matches);
        _search.currentIdx = idx;
        _editor.setMatches(matches, idx);
        updateSearchCount(idx + 1, matches.length);
        setNavDisabled(false);
        // Only navigate (set textarea selection + scroll to match) when
        // this is a user-driven search action — typing in the search
        // input, pressing Enter, or clicking ▲/▼. Re-runs triggered by
        // textarea edits (preserveIdx=true) must NOT touch the textarea's
        // selection or scrollTop: doing so would replace the current
        // match with whatever the user just typed (selection was on the
        // match, so the keystroke overwrites it) and yank the viewport
        // away from where they're editing.
        if (!preserveIdx) {
            scrollCurrentIntoView();
        }
    }

    function findAll(text, query) {
        if (!query) return [];
        var matches = [];
        // Case-insensitive substring search. (Regex / case toggles can land later.)
        var hay = text.toLowerCase();
        var needle = query.toLowerCase();
        var i = 0;
        while (true) {
            var pos = hay.indexOf(needle, i);
            if (pos === -1) break;
            matches.push({ start: pos, end: pos + needle.length });
            i = pos + Math.max(needle.length, 1);
        }
        return matches;
    }

    function pickInitialMatch(matches) {
        if (!_editor) return 0;
        // Use the textarea's selectionStart so a fresh search lands at the
        // first match at-or-after the caret. If the caret is past every
        // match, wrap around to the first.
        var ta = _editor.getTextarea();
        var caret = ta ? ta.selectionStart : 0;
        for (var i = 0; i < matches.length; i++) {
            if (matches[i].start >= caret) return i;
        }
        return 0;
    }

    function navigateSearch(direction) {
        if (!_editor || _search.matches.length === 0) return;
        var n = _search.matches.length;
        _search.currentIdx = ((_search.currentIdx + direction) % n + n) % n;
        _editor.setMatches(_search.matches, _search.currentIdx);
        updateSearchCount(_search.currentIdx + 1, n);
        scrollCurrentIntoView();
    }

    function scrollCurrentIntoView() {
        var m = _search.matches[_search.currentIdx];
        if (!m || !_editor) return;
        _editor.scrollRangeIntoView(m.start, m.end);
    }

    function jumpToLine(line) {
        if (!_editor) return;
        _editor.scrollToLine(line);
        // Clear any visible search matches — line jump isn't a search.
        clearSearchHighlights();
        updateSearchCount(null, null);
        setNavDisabled(true);
        _search.matches = [];
        _search.currentIdx = -1;
    }

    function clearSearchHighlights() {
        if (_editor) _editor.clearMatches();
    }

    function closeSearch() {
        if (_search.input) _search.input.value = '';
        _search.query = '';
        _search.matches = [];
        _search.currentIdx = -1;
        clearSearchHighlights();
        updateSearchCount(null, null);
        setNavDisabled(true);
        // Return focus to the editor so the user can resume editing.
        if (_editor) { try { _editor.focus(); } catch (e) {} }
    }

    function updateSearchCount(current, total) {
        if (!_search.countEl) return;
        if (current == null || total == null) {
            _search.countEl.textContent = '';
            _search.countEl.classList.remove('preview-source-canvas__search-count--no-match');
            return;
        }
        if (total === 0) {
            _search.countEl.textContent = t('styleSourceFindNoMatch');
            _search.countEl.classList.add('preview-source-canvas__search-count--no-match');
            return;
        }
        _search.countEl.textContent = t('styleSourceFindCount')
            .replace('{current}', String(current))
            .replace('{total}', String(total));
        _search.countEl.classList.remove('preview-source-canvas__search-count--no-match');
    }

    function setNavDisabled(disabled) {
        if (_search.prevBtn) _search.prevBtn.disabled = !!disabled;
        if (_search.nextBtn) _search.nextBtn.disabled = !!disabled;
    }

    function renderError(err) {
        if (!_mountEl) return;
        QSDom.clear(_mountEl);
        var box = document.createElement('div');
        box.className = 'preview-source-canvas__error';
        var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('fill', 'none');
        icon.setAttribute('stroke', 'currentColor');
        icon.setAttribute('stroke-width', '2');
        icon.setAttribute('width', '32');
        icon.setAttribute('height', '32');
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '12'); circle.setAttribute('cy', '12'); circle.setAttribute('r', '10');
        var l1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        l1.setAttribute('x1', '12'); l1.setAttribute('y1', '8'); l1.setAttribute('x2', '12'); l1.setAttribute('y2', '12');
        var l2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        l2.setAttribute('x1', '12'); l2.setAttribute('y1', '16'); l2.setAttribute('x2', '12.01'); l2.setAttribute('y2', '16');
        icon.appendChild(circle); icon.appendChild(l1); icon.appendChild(l2);
        var title = document.createElement('span');
        title.className = 'preview-source-canvas__error-title';
        title.textContent = t('styleSourceLoadError');
        var detail = document.createElement('span');
        detail.className = 'preview-source-canvas__error-detail';
        detail.textContent = errorText(err);
        box.appendChild(icon);
        box.appendChild(title);
        box.appendChild(detail);
        _mountEl.appendChild(box);
    }

    window.PreviewStyleSource = {
        init:      init,
        enter:     enter,
        leave:     leave,
        isActive:  isActive,
        getEditor: getEditor,
        isDirty:   function () { return _isDirty; },
        canLeave:  canLeave,
        save:      saveStyles,
        cancel:    cancelEdit
    };
})();
