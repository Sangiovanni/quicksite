/**
 * Preview — Style Source Tab (A3)
 *
 * Hosts the full-CSS code editor. Slice 2 fetches the current style.css
 * via `getStyles` and mounts QSCodeEditor into #preview-source-canvas-mount.
 * Save / dirty / iframe injection / draft persist land in slices 4–5;
 * for now `onChange` is a no-op.
 *
 * Public API:
 *   PreviewStyleSource.init()         — wire DOM refs (called once on load)
 *   PreviewStyleSource.enter()        — Source becomes active (lazy mount)
 *   PreviewStyleSource.leave()        — Source deactivates
 *   PreviewStyleSource.isActive()     — current activation state
 *   PreviewStyleSource.getEditor()    — QSCodeEditor instance or null
 */
(function () {
    'use strict';

    var _initialized = false;
    var _active      = false;

    var _mountEl   = null;
    var _loadingEl = null;
    var _editor    = null;
    var _loaded    = false;   // styles fetched + editor mounted
    var _loading   = false;   // fetch in-flight

    // Dirty + save state (A3 slice 4). _serverContent is the last
    // known content from the server (initial fetch or last successful
    // save); diff against the editor's current value drives the dirty
    // indicator + Save/Cancel button enable state.
    var _serverContent = '';
    var _isDirty       = false;
    var _isSaving      = false;
    var _draftTimer    = null;
    // Slice 5 — debounce timer for the iframe <style> injection. Cleared
    // on save / cancel / leave so the injection is never written after
    // those state-resync actions.
    var _injectTimer   = null;
    // When true, the next page-unload beforeunload event is allowed to
    // proceed without prompting. Set after the user explicitly confirms
    // a same-tab navigation (e.g. the Refine link) — otherwise they'd
    // get our custom confirm AND the native browser prompt.
    var _navigationConsented = false;

    // Sidebar Save/Cancel + dirty UI refs (A3 slice 4)
    var _saveBtn       = null;
    var _cancelBtn     = null;
    var _saveLabel     = null;
    var _statusEl      = null;
    var _statusTextEl  = null;
    var _refineLink    = null;

    // Restore-banner refs in the canvas (A3 slice 4)
    var _restoreBanner     = null;
    var _restoreDetail     = null;
    var _restoreAcceptBtn  = null;
    var _restoreDeclineBtn = null;

    // Search state (A3 slice 3). Empty query → no search active.
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
        // Slice 4 — Save/Cancel + dirty UI
        _saveBtn       = document.getElementById('source-sidebar-save-btn');
        _cancelBtn     = document.getElementById('source-sidebar-cancel-btn');
        _saveLabel     = document.getElementById('source-sidebar-save-label');
        _statusEl      = document.getElementById('source-sidebar-status');
        _statusTextEl  = document.getElementById('source-sidebar-status-text');
        _refineLink    = document.getElementById('source-sidebar-refine-link');
        // Slice 4 — restore banner
        _restoreBanner     = document.getElementById('preview-source-restore-banner');
        _restoreDetail     = document.getElementById('preview-source-restore-detail');
        _restoreAcceptBtn  = document.getElementById('preview-source-restore-accept');
        _restoreDeclineBtn = document.getElementById('preview-source-restore-decline');
        wireSearchHandlers();
        wireSaveCancelHandlers();
    }

    function enter() {
        if (_active) return;
        _active = true;
        // Lazy: fetch + mount on first activation. Reuse on subsequent
        // entries — the editor keeps its content + scroll position.
        if (!_loaded && !_loading) {
            _loading = true;
            fetchStyles()
                .then(function (content) {
                    mountEditor(content);
                    _loaded = true;
                })
                .catch(function (err) {
                    renderError(err);
                })
                .then(function () {
                    _loading = false;
                });
        } else if (_editor) {
            // Already mounted. Reset scroll to the top before focusing —
            // when the canvas goes display:none and back, browsers can
            // reset the textarea's scrollTop while the <pre> overlay
            // (set programmatically) keeps its position, leaving the
            // layers out of sync. Resetting both to 0 keeps them aligned.
            try { _editor.resetScroll(); } catch (e) { /* no-op */ }
            try { _editor.focus();       } catch (e) { /* no-op */ }
            // Slice 5: leave() removed the previous injection — restore
            // it so the iframe is back in sync with the editor's content.
            injectLiveStyles(_editor.getValue());
        }
    }

    function leave() {
        if (!_active) return;
        _active = false;
        // Slice 5: the live <style> injection STAYS across leaves. The
        // whole point of live injection is so unsaved Source edits remain
        // visible in the iframe — including while the user is inspecting
        // other Style tabs (Theme / Selectors / Animations) or other
        // sidebar modes. Only save / cancel tear it down (they reset
        // state authoritatively). A pending debounce is also allowed to
        // fire after leave — it harmlessly updates the iframe to match
        // the latest editor value, ready for when Source is shown again.
    }

    function isActive() {
        return _active;
    }

    function getEditor() {
        return _editor;
    }

    // ── Internals ──

    function fetchStyles() {
        // Prefer the shared API layer (matches preview-style-theme.js).
        if (window.QuickSiteAPI && QuickSiteAPI.request) {
            return QuickSiteAPI.request('getStyles', 'GET').then(function (result) {
                if (result.ok && result.data && result.data.data && typeof result.data.data.content === 'string') {
                    return result.data.data.content;
                }
                var msg = (result.data && (result.data.message || result.data.error)) || 'Failed to fetch style.css';
                throw new Error(msg);
            });
        }
        // Fallback for contexts without QuickSiteAPI (unlikely on the
        // preview page, but mirrors the theme module's defensive code).
        var url = (window.PreviewConfig && PreviewConfig.managementUrl ? PreviewConfig.managementUrl : '/management/') + 'getStyles';
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (data && data.status === 200 && data.data && typeof data.data.content === 'string') {
                return data.data.content;
            }
            throw new Error((data && data.message) || 'Failed to fetch style.css');
        });
    }

    function mountEditor(content) {
        if (!_mountEl) return;
        // Hide the loading indicator. The mount call clears the mount
        // element, but doing this first avoids a flash.
        if (_loadingEl) _loadingEl.style.display = 'none';
        if (!window.QSCodeEditor || !QSCodeEditor.create) {
            renderError(new Error('Code editor not loaded'));
            return;
        }
        var tokenize = (QSCodeEditor.tokenizers && QSCodeEditor.tokenizers.css) || null;
        _editor = QSCodeEditor.create({
            mount:    _mountEl,
            value:    content,
            tokenize: tokenize,
            onChange: handleChange
        });
        // Slice 4: baseline server content — drives dirty diff.
        _serverContent = content;
        _isDirty = false;
        renderDirtyUI();
        try { _editor.focus(); } catch (e) { /* no-op */ }
        // Slice 5: prime the iframe <style> tag with the current content.
        // This is a no-op visually (editor == server right now) but it
        // ensures the tag exists for subsequent live updates.
        injectLiveStyles(content);
        // Offer to restore an unsaved draft if one exists and differs
        // from the server content. The user can Restore (load the draft
        // into the editor + mark dirty) or Discard (clear the draft).
        maybeShowRestoreBanner();
    }

    function handleChange(/* newValue */) {
        // Re-run the current search when the textarea content changes —
        // existing match positions become stale on any edit.
        if (_search.query) runSearch(_search.query, /* preserveIdx */ true);
        // Slice 4: dirty diff vs server content + debounced draft persist.
        updateDirty();
        schedulePersistDraft();
        // Slice 5: debounced <style> inject into the iframe so the
        // preview reflects unsaved edits the moment Source is exited.
        scheduleInjectLiveStyles();
    }

    // ── Dirty / Save / Cancel (A3 slice 4) ──

    function updateDirty() {
        if (!_editor) return;
        var nowDirty = (_editor.getValue() !== _serverContent);
        if (nowDirty === _isDirty) return;
        _isDirty = nowDirty;
        renderDirtyUI();
    }

    function renderDirtyUI() {
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        if (_statusEl) {
            _statusEl.classList.toggle('preview-source-sidebar__status--dirty', _isDirty);
            _statusEl.classList.toggle('preview-source-sidebar__status--clean', !_isDirty);
        }
        if (_statusTextEl) {
            _statusTextEl.textContent = _isDirty
                ? (i18n.styleSourceDirty || 'Unsaved changes')
                : (i18n.styleSourceClean || 'All saved');
        }
        if (_saveBtn)   _saveBtn.disabled   = !_isDirty || _isSaving;
        if (_cancelBtn) _cancelBtn.disabled = !_isDirty || _isSaving;
    }

    function setSaveLabel(saving) {
        if (!_saveLabel) return;
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        _saveLabel.textContent = saving
            ? (i18n.styleSourceSaving || 'Saving…')
            : (i18n.styleSourceSave || 'Save');
    }

    function wireSaveCancelHandlers() {
        if (_saveBtn)    _saveBtn.addEventListener('click', saveStyles);
        if (_cancelBtn)  _cancelBtn.addEventListener('click', cancelEdit);
        if (_refineLink) _refineLink.addEventListener('click', onRefineClick);
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    function onBeforeUnload(e) {
        // The user already confirmed a same-tab navigation (e.g. Refine
        // link) — let it through without the native browser prompt.
        if (_navigationConsented) {
            _navigationConsented = false;
            return;
        }
        if (_isDirty) {
            // Modern browsers show their own generic prompt; setting
            // returnValue is what triggers it.
            e.preventDefault();
            e.returnValue = '';
        }
    }

    function onRefineClick(e) {
        // Same-tab navigation: warn if dirty so the user doesn't lose
        // edits silently. Modifier-clicks (open in new tab / window) and
        // middle-clicks should bypass the warning since they don't leave
        // the current page.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        if (!_isDirty) return;
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var msg = i18n.styleSourceSwitchConfirm || 'You have unsaved Source edits. Discard them and switch?';
        if (!window.confirm(msg)) {
            e.preventDefault();
            return;
        }
        // User confirmed — suppress the native beforeunload prompt so
        // they don't get asked twice. The draft is still in localStorage,
        // so on return the restore banner will offer to bring it back.
        _navigationConsented = true;
    }

    function saveStyles() {
        if (!_editor || _isSaving || !_isDirty) return;
        var content = _editor.getValue();
        _isSaving = true;
        setSaveLabel(true);
        renderDirtyUI();
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var done = function () {
            _isSaving = false;
            setSaveLabel(false);
            renderDirtyUI();
        };
        var failed = function (err) {
            var msg = (err && err.message) || 'Unknown error';
            var tpl = i18n.styleSourceSaveError || 'Save failed: {error}';
            if (window.showToast) window.showToast(tpl.replace('{error}', msg), 'error');
        };
        var ok = function () {
            _serverContent = content;
            _isDirty = false;
            clearDraft();
            if (window.showToast) window.showToast(i18n.styleSourceSaved || 'style.css saved', 'success');
            // Slice 5: drop the live injection and force the iframe's
            // <link rel="stylesheet"> to re-fetch — saved content is now
            // authoritative, the injection has nothing left to add.
            if (_injectTimer) { clearTimeout(_injectTimer); _injectTimer = null; }
            removeLiveStyles();
            if (window.PreviewState && PreviewState.hotReloadCss) {
                PreviewState.hotReloadCss();
            }
            // Slice 6 fix: a Source save can change anything in style.css
            // — including :root variables that the other structured tabs
            // cache. Mark their caches stale so the next view re-fetches.
            invalidateStructuredTabs();
        };
        if (window.QuickSiteAPI && QuickSiteAPI.request) {
            QuickSiteAPI.request('editStyles', 'POST', { content: content })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error((result.data && (result.data.message || result.data.error)) || 'Save failed');
                    }
                    ok();
                })
                .catch(failed)
                .then(done);
        } else {
            var url = (window.PreviewConfig && PreviewConfig.managementUrl ? PreviewConfig.managementUrl : '/management/') + 'editStyles';
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content: content })
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  if (!data || (data.status !== 200 && data.status !== 201)) {
                      throw new Error((data && data.message) || 'Save failed');
                  }
                  ok();
              })
              .catch(failed)
              .then(done);
        }
    }

    function cancelEdit() {
        if (!_isDirty || _isSaving) return;
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var msg = i18n.styleSourceCancelConfirm || 'Discard unsaved changes and reload style.css from the server?';
        if (!window.confirm(msg)) return;
        // Re-fetch from server — picks up any out-of-band changes too.
        fetchStyles().then(function (content) {
            _serverContent = content;
            if (_editor) _editor.setValue(content);
            _isDirty = false;
            clearDraft();
            renderDirtyUI();
            // Slice 5: drop the live injection + flush the iframe's
            // cached style.css. The editor is back to server state, and
            // so should the preview be.
            if (_injectTimer) { clearTimeout(_injectTimer); _injectTimer = null; }
            removeLiveStyles();
            if (window.PreviewState && PreviewState.hotReloadCss) {
                PreviewState.hotReloadCss();
            }
            // Slice 6 fix: cancel re-fetches the file; if it differs from
            // the cached state of the other tabs, their caches are stale.
            invalidateStructuredTabs();
        }).catch(function (err) {
            if (window.showToast) {
                var label = i18n.styleSourceLoadError || 'Failed to load style.css';
                window.showToast(label + ': ' + ((err && err.message) || ''), 'error');
            }
        });
    }

    // ── Draft persist (A3 slice 4) ──
    // Writes the current editor value to localStorage (debounced ~500ms)
    // whenever the content differs from server. Cleared on save / discard.

    function getDraftKey() {
        return (window.QuickSiteStorageKeys && QuickSiteStorageKeys.styleSourceDraft)
            || 'qs_style_source_draft';
    }

    function schedulePersistDraft() {
        if (_draftTimer) clearTimeout(_draftTimer);
        _draftTimer = setTimeout(persistDraft, 500);
    }

    function persistDraft() {
        if (!_editor) return;
        var content = _editor.getValue();
        if (content === _serverContent) {
            clearDraft();
            return;
        }
        try {
            localStorage.setItem(getDraftKey(), JSON.stringify({
                content: content,
                savedAt: Date.now()
            }));
        } catch (e) {
            // localStorage may be full or disabled — silent failure is OK,
            // the editor still works without draft persistence.
        }
    }

    function clearDraft() {
        try { localStorage.removeItem(getDraftKey()); } catch (e) { /* no-op */ }
    }

    function readDraft() {
        try {
            var raw = localStorage.getItem(getDraftKey());
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed.content === 'string') return parsed;
            return null;
        } catch (e) {
            return null;
        }
    }

    function maybeShowRestoreBanner() {
        var draft = readDraft();
        if (!draft) return;
        if (draft.content === _serverContent) {
            // Draft matches what's on the server now — nothing to restore.
            clearDraft();
            return;
        }
        if (!_restoreBanner || !_restoreDetail) return;
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var tpl = i18n.styleSourceRestoreDetail || 'From {time}';
        var when = new Date(draft.savedAt || Date.now());
        _restoreDetail.textContent = tpl.replace('{time}', when.toLocaleString());
        _restoreBanner.style.display = '';
        // Rebind handlers each time so they always close over the freshest
        // draft contents.
        if (_restoreAcceptBtn) {
            _restoreAcceptBtn.onclick = function () {
                if (_editor) _editor.setValue(draft.content);
                _isDirty = (draft.content !== _serverContent);
                renderDirtyUI();
                hideRestoreBanner();
                // Slice 5: re-inject so the iframe matches the restored
                // draft. setValue() doesn't fire handleChange (it's an
                // input-event callback), so we sync the injection here.
                injectLiveStyles(draft.content);
                // Leave the draft in localStorage — it's still the user's
                // working copy until they save / cancel.
            };
        }
        if (_restoreDeclineBtn) {
            _restoreDeclineBtn.onclick = function () {
                clearDraft();
                hideRestoreBanner();
            };
        }
    }

    function hideRestoreBanner() {
        if (_restoreBanner) _restoreBanner.style.display = 'none';
    }

    // ── Live iframe injection (A3 slice 5) ──
    // While Source is active, mirror the editor's content into a
    // <style id="qs-source-live-styles"> element appended to the iframe's
    // <head>. The iframe is hidden during Source mode, so this isn't
    // "visible while typing" — but the moment the user exits Source (tab
    // click, mode switch), the iframe shows the unsaved edits already
    // applied instead of a flash of pre-edit state.
    //
    // Lifecycle: prime on mount + re-entry; update debounced on input;
    // tear down on leave / save / cancel. saveStyles + cancelEdit follow
    // up with PreviewState.hotReloadCss() so the iframe's actual
    // <link rel="stylesheet"> picks up the server-side file again.

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
                tag.setAttribute('data-source', 'a3-live-injection');
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

    function scheduleInjectLiveStyles() {
        if (_injectTimer) clearTimeout(_injectTimer);
        _injectTimer = setTimeout(function () {
            _injectTimer = null;
            if (_editor) injectLiveStyles(_editor.getValue());
        }, 200);
    }

    // ── Stale-cache invalidation for sibling style tabs (A3 slice 6 fix) ──
    // Source writes the whole stylesheet, so anything the other Style tabs
    // had cached can be stale after a save (or after cancel re-fetches the
    // current server file). Each sibling module exposes its own invalidate
    // / reset hook; we call whatever's available without coupling to the
    // module's internals.

    function invalidateStructuredTabs() {
        try {
            if (window.PreviewStyleTheme && PreviewStyleTheme.invalidate) {
                PreviewStyleTheme.invalidate();
            }
        } catch (e) { /* no-op */ }
        try {
            if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.reset) {
                PreviewSelectorBrowser.reset();
            }
        } catch (e) { /* no-op */ }
        try {
            if (window.PreviewStyleAnimations && PreviewStyleAnimations.reset) {
                PreviewStyleAnimations.reset();
            }
        } catch (e) { /* no-op */ }
    }

    // ── Cross-tab / cross-mode guard ──
    // Returns true if the caller may safely leave Source; false if the
    // user cancelled the prompt (i.e. wants to stay).
    function canLeave() {
        if (!_isDirty) return true;
        if (_isSaving) return true;  // let in-flight save finish naturally
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var msg = i18n.styleSourceSwitchConfirm || 'You have unsaved Source edits. Discard them and switch?';
        return window.confirm(msg);
    }

    // ── Search (A3 slice 3) ──

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
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        if (current == null || total == null) {
            _search.countEl.textContent = '';
            _search.countEl.classList.remove('preview-source-canvas__search-count--no-match');
            return;
        }
        if (total === 0) {
            _search.countEl.textContent = i18n.styleSourceFindNoMatch || 'No match';
            _search.countEl.classList.add('preview-source-canvas__search-count--no-match');
            return;
        }
        var tpl = i18n.styleSourceFindCount || '{current}/{total}';
        _search.countEl.textContent = tpl
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
        var msg = (err && err.message) || 'Failed to load style.css';
        var i18n = (window.PreviewConfig && PreviewConfig.i18n) || {};
        var label = i18n.styleSourceLoadError || 'Failed to load style.css';
        _mountEl.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'preview-source-canvas__error';
        var icon = document.createElement('svg');
        // Simple inline-svg via createElementNS to avoid innerHTML for the SVG itself
        icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
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
        title.textContent = label;
        var detail = document.createElement('span');
        detail.className = 'preview-source-canvas__error-detail';
        detail.textContent = msg;
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
        // Slice 4
        isDirty:   function () { return _isDirty; },
        canLeave:  canLeave,
        save:      saveStyles,
        cancel:    cancelEdit
    };
})();
