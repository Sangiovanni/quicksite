/**
 * preview-ai-tools.js — the Workflows panel (mode ai-tools: the file keeps the panel's first name, AI tools).
 *
 * In-editor Workflows panel:
 *   list view  → workflow picker: search, then Options (closed by default; its line names the switches on and
 *                how many rows they hide): No AI, Nothing deleted, the compact view and, for a role that cannot
 *                run some, the box that shows those greyed, all four kept in this browser; the groups by what you want to do,
 *                each row saying in words what a run does, each fact with its icon (QSDom.icon()), or in the
 *                compact view small cards with the icons alone; from FOLD_AT workflows the groups fold and
 *                Recently used appears
 *   runner view → spec runner (3 zones: INPUTS / AI EXCHANGE / EXECUTION)
 *                  + footer line (Model + Touches)
 *
 * Activation: the tool "Workflows" → setMode('ai-tools') in preview.js
 * shows #contextual-ai-tools and calls PreviewAiTools.enter().
 *
 * Workflow runner flow:
 *   1. INPUTS — Your prompt (one per AI phase) + Parameters + [Run / Generate prompt / Send to AI]
 *   2. AI EXCHANGE — Generated prompt (copy target) + AI response (paste target)
 *   3. EXECUTION — Run without stopping + the run's phases + the review + Batch (by phase)
 *   Footer — Touches (inline, discreet)
 *
 * A run goes through the workflow's phases: its pre-workflows, itself, its post-workflows, each
 * resolved by the server when the run reaches it (workflow-generate-steps). A run with no AI phase
 * skips AI EXCHANGE entirely.
 */
window.PreviewAiTools = (function () {
    'use strict';

    var SEARCH_DEBOUNCE_MS = 180;
    // From this many workflows the groups fold, each showing its name and count, and Recently used appears. A convention.
    var FOLD_AT = 30;
    // How many workflows Recently used keeps. A convention.
    var RECENT_KEPT = 3;

    // ──────────────────────────── State ─────────────────────────────────

    var _entered = false;

    // List view state
    var _allWorkflows = [];
    var _groups = [];                        // the panel's groups in order, { id, title, line } (PreviewConfig.workflowGroups)
    var _factsById = {};                     // each row's second line, its parts in words (_factParts())
    var _hayById = {};                       // what a search reads of each row, folded (_fold())
    var _searchWords = [];                   // the search, folded and cut into words: a row holds every one
    var _searchDebounceId = null;
    var _noAi = false;                       // the No AI switch: only the rows that ask no model
    var _nothingDeleted = false;             // the Nothing deleted switch: only the rows that can delete nothing
    var _compact = false;                    // the compact view: small cards, the icons alone (kept in this browser)
    var _unrunnable = [];                    // the workflows the role cannot run (PreviewConfig.aiToolsUnrunnable): never opened
    var _showUnrunnable = false;             // the box that shows them, greyed; not remembered
    var _factsIdSeq = 0;                     // a small card's facts get an id its button is described by
    var _listState = { recent: [], open: null }; // what the browser keeps of the list (_readListState())

    // Runner view state
    var _activeWorkflowSummary = null;
    var _activeWorkflowSpec = null;
    var _activeTemplate = null;
    var _fetchedData = {};
    var _paramValues = {};
    var _templateKindShown = {};             // the kind each picker of templates shows, by its parameter's id (none: all); not stored
    var _requests = {};                    // "Your prompt" of each AI phase, by its key (_requestKey())
    var _generatedPrompt = '';
    var _aiResponseText = '';
    var _aiResponseStatus = { type: 'idle' };  // 'idle'|'ok'|'error', + message + commandCount

    // The run's phases (its pre-workflows, the workflow, its post-workflows), as the server last
    // planned them: the preview before a run, the plan of a run.
    var _plan = [];
    var _planSeq = 0;                        // an older plan's answer arriving after a newer one is dropped
    var _run = null;                         // the run under way (_startRun()), null when none
    var _answerWaiter = null;                // a run waiting for an AI answer: resolve(commands) on Execute
    var _answerPhase = null;                 // the AI answer's commands classified by the server (the phase)
    var _answerSeq = 0;
    var _continuation = null;                // an answer being put together: { kept: [commands whole so far], cut, parts }
    var _answerParts = 0;                    // the answer ready was put together from this many answers (0: one); it waits for Execute
    var _aiSending = false;                  // a send to the AI is under way
    var _recoveryEls = null;                 // the waiting bar's Continue the answer and Send again ({ continueBtn, sendAgainBtn })
    // A run's cost: the last run's tally of each workflow (_costRecord() builds the record the display
    // reads), in memory only — nothing is written; a new run of the workflow starts it again.
    var _lastCost = {};
    var _exchangePhase = null;               // the AI phase the AI exchange last served, in the last run

    // DOM refs — bound on init().

    // The /admin/api helper endpoint is project-scoped: it authorizes each
    // arm against the marker project and binds that context. QuickSiteAPI.helperPath
    // is the single owner of the marker convention; this delegates so the URL shape
    // lives in ONE place. Falls back to the bare action if core/api.js is absent —
    // the server then answers 400 rather than silently reading another project.
    function _helperPath(action) {
        return (window.QuickSiteAPI && typeof window.QuickSiteAPI.helperPath === 'function')
            ? window.QuickSiteAPI.helperPath(action)
            : action;
    }
    var _section = null;
    var _listView = null;
    var _runnerView = null;
    var _searchInput = null;
    var _noAiSwitch = null;
    var _nothingDeletedSwitch = null;
    var _compactSwitch = null;
    var _unrunnableSwitch = null;
    var _unrunnableRow = null;
    var _optionsToggle = null;
    var _optionsPanel = null;
    var _optionsSummary = null;
    var _optionsEmptyEl = null;
    var _showAllBtn = null;
    var _allList = null;
    var _loadingEl = null;
    var _emptyEl = null;
    var _backupBtnEl = null;
    var _backupBannerEl = null;
    var _backupHideBtn = null;

    // Live runner DOM refs (per-render).
    var _generalPromptTextarea = null;
    var _copyBtn = null;
    var _aiResponseTextarea = null;
    var _aiResponseStatusEl = null;
    var _paramErrors = {};
    var _executing = false;
    var _stepsBody = null;
    var _stepsHeaderState = null;
    var _stepsSetOpen = null;
    var _primaryActionBtn = null;
    var _secondaryActionBtn = null;
    var _executeBtnEl = null;
    var _modelSelectEl = null;
    var _currentCommands = [];           // the parsed AI answer's commands
    var _pasteAutoExecTimer = null;      // unified 1.5s grace timer for auto-execute
    var _autoExecHintEl = null;          // DOM ref for "Auto-executing in 1.5s…" hint
    var _runLineEl = null;               // the run's phases in order, with their state
    var _reviewEl = null;                // the pause before a phase: what it does, Continue / Stop
    var _yourPromptBody = null;          // the Your prompt section's body: one field per AI phase
    var _exchangeHeaderEl = null;        // the AI exchange zone's header, naming the AI phase it serves
    var _phaseCostEl = null;             // the AI exchange's cost: the AI phase it serves
    var _costEl = null;                  // the Run zone's cost block: the run's total and each AI phase

    // ──────────────────────────── Words ─────────────────────────────────

    // A word of the editor's own section, named by the rest of its key's path (_t('aiToolsRunActive') reads
    // preview.aiToolsRunActive); a word of another section, or a counted one, is read through QSPanelWords itself.
    function _t(name, params) { return QSPanelWords.t('preview.' + name, params); }

    // ──────────────────────────── Init ──────────────────────────────────

    function init() {
        _section = document.getElementById('contextual-ai-tools');
        if (!_section) {
            console.warn('[PreviewAiTools] #contextual-ai-tools missing — panel disabled');
            return;
        }
        _listView = document.getElementById('ai-tools-list-view');
        _runnerView = document.getElementById('ai-tools-runner-view');
        _searchInput = document.getElementById('ai-tools-search');
        _noAiSwitch = document.getElementById('ai-tools-switch-no-ai');
        _nothingDeletedSwitch = document.getElementById('ai-tools-switch-nothing-deleted');
        _compactSwitch = document.getElementById('ai-tools-switch-compact');
        _unrunnableSwitch = document.getElementById('ai-tools-switch-unrunnable');
        _unrunnableRow = document.getElementById('ai-tools-switch-unrunnable-row');
        _optionsToggle = document.getElementById('ai-tools-options-toggle');
        _optionsPanel = document.getElementById('ai-tools-options-panel');
        _optionsSummary = document.getElementById('ai-tools-options-summary');
        _optionsEmptyEl = document.getElementById('ai-tools-options-empty');
        _showAllBtn = document.getElementById('ai-tools-show-all');
        _allList = document.getElementById('ai-tools-all-list');
        _loadingEl = document.getElementById('ai-tools-loading');
        _emptyEl = document.getElementById('ai-tools-empty');
        // The four boxes are kept in this browser: the closed Options line names what they hide, so none hides a row unseen.
        _applyKeptOptions();
        _backupBtnEl = document.getElementById('ai-tools-backup-btn');
        _backupBannerEl = document.getElementById('ai-tools-backup-banner');
        _backupHideBtn = document.getElementById('ai-tools-backup-hide');
        _showBackupNote(!_isBackupNoteHidden());

        _bindEvents();
        _subscribeToSelectionChanges();
        // The kept steps' banner follows the allowance: Run enables once it is back.
        window.addEventListener('quicksite:write-rate', function () {
            if (_keptEl && !_keptEl.hidden) _renderKept();
        });
    }

    function _subscribeToSelectionChanges() {
        // For workflows that declare a `type: selector` parameter — re-render
        // those param displays when the user picks a different element in
        // the iframe. The subscription is global (page-load) but no-ops
        // when not in ai-tools mode or when the active workflow has no
        // selector params.
        if (!window.PreviewState || typeof window.PreviewState.on !== 'function') return;
        window.PreviewState.on('selectedNode', function () {
            if (!window.PreviewManager) return;
            if (window.PreviewManager.getCurrentMode() !== 'ai-tools') return;
            _refreshAllSelectorParams();
        });
    }

    function _bindEvents() {
        if (_searchInput) {
            _searchInput.addEventListener('input', _onSearchInput);
        }
        [_noAiSwitch, _nothingDeletedSwitch, _compactSwitch, _unrunnableSwitch].forEach(function (box) {
            if (box) box.addEventListener('change', _onOptionChange);
        });
        if (_optionsToggle) {
            _optionsToggle.addEventListener('click', _onOptionsToggle);
        }
        if (_showAllBtn) {
            _showAllBtn.addEventListener('click', _onShowAll);
        }
        if (_backupBtnEl) {
            _backupBtnEl.addEventListener('click', _onBackupClick);
        }
        if (_backupHideBtn) {
            _backupHideBtn.addEventListener('click', _onBackupNoteHide);
        }
    }

    /** A name of the panel's storage registry (QuickSiteStorageKeys), or null when the registry does not hold it. */
    function _storageKey(name) {
        var keys = window.QuickSiteStorageKeys;
        return keys && typeof keys[name] === 'string' ? keys[name] : null;
    }

    function _isBackupNoteHidden() {
        try {
            var key = _storageKey('workflowsBackupNoteHidden');
            return key !== null && localStorage.getItem(key) === '1';
        } catch (e) {
            return false;
        }
    }

    function _showBackupNote(show) {
        if (_backupBannerEl) {
            _backupBannerEl.classList.toggle('preview-contextual-ai-tools__backup-banner--note-hidden', !show);
        }
    }

    function _onBackupNoteHide() {
        try {
            var key = _storageKey('workflowsBackupNoteHidden');
            if (key !== null) localStorage.setItem(key, '1');
        } catch (e) { /* the message goes for this visit, and comes back on the next */ }
        _showBackupNote(false);
    }

    /** The Options' four boxes, each by the name it is kept under, with its element. */
    function _optionBoxes() {
        return { noAi: _noAiSwitch, nothingDeleted: _nothingDeletedSwitch, compact: _compactSwitch, unrunnable: _unrunnableSwitch };
    }

    /**
     * The Options this browser keeps (QuickSiteStorageKeys.workflowsOptions), put on the four boxes. A missing or malformed
     * part, or a failure to read, leaves its box unticked.
     */
    function _applyKeptOptions() {
        var kept = {};
        try {
            var key = _storageKey('workflowsOptions');
            var raw = key !== null ? JSON.parse(localStorage.getItem(key) || 'null') : null;
            if (raw && typeof raw === 'object') kept = raw;
        } catch (e) { /* every box starts unticked */ }
        var boxes = _optionBoxes();
        Object.keys(boxes).forEach(function (name) {
            if (boxes[name]) boxes[name].checked = kept[name] === true;
        });
    }

    /** The four boxes as they stand, kept for the next visit. */
    function _keepOptions() {
        var state = {};
        var boxes = _optionBoxes();
        Object.keys(boxes).forEach(function (name) { state[name] = !!(boxes[name] && boxes[name].checked); });
        try {
            var key = _storageKey('workflowsOptions');
            if (key !== null) localStorage.setItem(key, JSON.stringify(state));
        } catch (e) { /* the boxes hold for this visit only */ }
    }

    function _onBackupClick() {
        if (!_backupBtnEl) return;
        var origLabel = _backupBtnEl.textContent;
        _backupBtnEl.disabled = true;
        _backupBtnEl.textContent = _t('aiToolsBackupCreating');
        var toaster = (window.QuickSiteAdmin && typeof window.QuickSiteAdmin.showToast === 'function')
            ? window.QuickSiteAdmin.showToast.bind(window.QuickSiteAdmin)
            : null;
        // Through the panel's API client, like every management call: it carries the
        // project marker and keeps the write allowance current.
        window.QuickSiteAdmin.apiRequest('backupProject', 'GET').then(function (res) {
            var json = res.data || {};
            if (!res.ok) {
                throw new Error(json.error || json.message || ('HTTP ' + res.status));
            }
            var path = (json.data && (json.data.path || json.data.backupPath)) || '';
            if (toaster) {
                toaster(_t('aiToolsBackupSuccess', { path: path || '✓' }), 'success');
            }
        }).catch(function (err) {
            if (toaster) {
                toaster(_t('aiToolsBackupFailed', { error: (err && err.message) || String(err) }), 'error');
            }
        }).then(function () {
            _backupBtnEl.disabled = false;
            _backupBtnEl.textContent = origLabel;
        });
    }

    function enter(opts) {
        opts = opts || {};
        if (!_section) return;
        // A run under way (a review or an AI answer awaited included) is where the panel comes back to.
        if (_run) {
            _showRunnerView();
        } else {
            _showListView();
        }
        _renderKept();
        if (_entered && !opts.refresh) return;
        _entered = true;
        _initData();
        _onSwitchChange();
    }

    function leave() {
        // Light cleanup; cache persists for fast re-entry.
    }

    function _showListView() {
        if (_listView) _listView.style.display = '';
        if (_runnerView) _runnerView.style.display = 'none';
    }

    function _showRunnerView() {
        if (_listView) _listView.style.display = 'none';
        if (_runnerView) _runnerView.style.display = '';
    }

    // ──────────────────────────── Data (list) ───────────────────────────

    function _initData() {
        var config = window.PreviewConfig || {};
        _allWorkflows = Array.isArray(config.aiToolsWorkflows) ? config.aiToolsWorkflows.slice() : [];
        _unrunnable = Array.isArray(config.aiToolsUnrunnable) ? config.aiToolsUnrunnable.slice() : [];
        _groups = Array.isArray(config.workflowGroups) ? config.workflowGroups.slice() : [];
        _listState = _readListState();
        // The box that shows the workflows the role cannot run is offered only where there is one.
        if (_unrunnableRow) _unrunnableRow.hidden = _unrunnable.length === 0;
        var groupTitle = {};
        _groups.forEach(function (g) { groupTitle[g.id] = g.title || ''; });
        _factsById = {};
        _hayById = {};
        _allWorkflows.concat(_unrunnable).forEach(function (w) {
            _factsById[w.id] = _factParts(w);
            var facts = _factsById[w.id].map(function (part) { return part.text; }).join(' ');
            // A search reads the title, the description, the second line, the group's name and the tags, which nothing shows.
            _hayById[w.id] = _fold([w.title, w.description, facts, groupTitle[w.category]].concat(w.tags || []).join(' '));
        });
    }

    /** Text as a search compares it: lower case, its accents dropped ("Créer" finds "creer" and the other way round). */
    function _fold(text) {
        return String(text || '').normalize('NFD').replace(/\p{M}+/gu, '').toLowerCase();
    }

    /**
     * What the browser keeps of the list (QuickSiteStorageKeys.workflowsList): the workflows last opened, newest first, and
     * the groups left open once the list folds (null until the reader opens or closes one). A failure is silent: the list
     * then starts as new.
     */
    function _readListState() {
        var state = { recent: [], open: null };
        try {
            var key = _storageKey('workflowsList');
            var raw = key !== null ? JSON.parse(localStorage.getItem(key) || 'null') : null;
            var strings = function (list) { return list.filter(function (id) { return typeof id === 'string'; }); };
            if (raw && Array.isArray(raw.recent)) state.recent = strings(raw.recent).slice(0, RECENT_KEPT);
            if (raw && Array.isArray(raw.open)) state.open = strings(raw.open);
        } catch (e) { /* the list starts as new */ }
        return state;
    }

    function _writeListState() {
        try {
            var key = _storageKey('workflowsList');
            if (key !== null) localStorage.setItem(key, JSON.stringify({ recent: _listState.recent, open: _listState.open || [] }));
        } catch (e) { /* kept for this visit only */ }
    }

    function _rememberRecent(id) {
        var recent = _listState.recent.filter(function (r) { return r !== id; });
        recent.unshift(id);
        _listState.recent = recent.slice(0, RECENT_KEPT);
        _writeListState();
    }

    // ──────────────────────────── A row's second line ───────────────────

    /**
     * A row's second line, part by part: what a run does, in words, from the facts the server derived from the workflow's
     * file (WorkflowManager::rowFacts()): AI or not and how many requests, what it can delete, what it changes, whether it
     * runs any command, what it needs. The groups are the run review's own words (EFFECT_WHAT, EFFECT_DOES), so a row and its
     * review name the same things. Each part is { kind: 'ai'|'delete'|'change'|'need', text, icon }, its icon a name of
     * QSDom's icons (FACT_ICONS), or null where a fact has none (what a run changes).
     */
    function _factParts(workflow) {
        // A row the server sent no facts for says nothing of what a run does, rather than guess.
        if (!workflow.facts) return [];
        var parts = _factWords(workflow.facts);
        parts.forEach(function (part) {
            part.icon = part.kind === 'ai' ? (workflow.facts.ai ? 'ai' : 'noAi') : (FACT_ICONS[part.kind] || null);
        });
        return parts;
    }

    // A fact's icon, by its kind; AI or not draws 'ai' or 'noAi'. What a run changes has none.
    var FACT_ICONS = { delete: 'canDelete', need: 'needs' };

    function _factWords(f) {
        var effects = Array.isArray(f.effects) ? f.effects : [];
        var parts = [{ kind: 'ai', text: _aiWords(f) }];
        var deleted = {};
        var deletes = [];
        var does = [];
        effects.forEach(function (e) {
            if (e.effect !== 'delete') return;
            var own = _effectDoes(e);
            if (own) {
                var line = _t(own.line);
                if (does.indexOf(line) === -1) does.push(line);
                return;
            }
            if (deleted[e.what]) return;
            deleted[e.what] = true;
            deletes.push(_whatWord(e.what));
        });
        // What it deletes, it is not said to change as well.
        var changed = {};
        var changes = [];
        effects.forEach(function (e) {
            if (e.effect !== 'change' || deleted[e.what] || changed[e.what]) return;
            changed[e.what] = true;
            changes.push(_whatWord(e.what));
        });
        if (deletes.length > 0) {
            parts.push({ kind: 'delete', text: _t('workflowsFactCanDelete', { what: deletes.join(', ') }) });
        }
        does.forEach(function (line) { parts.push({ kind: 'delete', text: line }); });
        if (changes.length > 0) {
            parts.push({ kind: 'change', text: _t('workflowsFactChanges', { what: changes.join(', ') }) });
        }
        if (f.anyCommand) parts.push({ kind: 'delete', text: _t('workflowsFactAnyCommand') });
        if (f.needs) parts.push({ kind: 'need', text: String(f.needs) });
        return parts;
    }

    /** AI or not, and how many requests a run sends (and how long the answer runs, when the workflow's file says). */
    function _aiWords(f) {
        if (f.paste) return _t('workflowsFactPaste');
        if (!f.ai) return _t('workflowsFactNoAi');
        var several = f.requests > 1;
        if (f.answerLength === 'long') {
            return several ? _t('workflowsFactAiSeveralLong') : _t('workflowsFactAiOneLong');
        }
        if (f.answerLength === 'short') {
            return several ? _t('workflowsFactAiSeveralShort') : _t('workflowsFactAiOneShort');
        }
        return several ? _t('workflowsFactAiSeveral') : _t('workflowsFactAiOne');
    }

    function _whatWord(what) {
        return _t(EFFECT_WHAT[what] || EFFECT_WHAT.other);
    }

    /** Whether a run can delete: a step that deletes, or an answer that may send any command. */
    function _canDelete(workflow) {
        var f = workflow.facts || {};
        return !!f.anyCommand || (Array.isArray(f.effects) && f.effects.some(function (e) { return e.effect === 'delete'; }));
    }

    // ──────────────────────────── Search and switches ───────────────────

    function _filtering() {
        return _searchWords.length > 0 || _noAi || _nothingDeleted;
    }

    function _matches(workflow) {
        return _matchesSwitches(workflow) && _matchesSearch(workflow);
    }

    function _matchesSwitches(workflow) {
        if (_noAi && workflow.facts && workflow.facts.ai) return false;
        if (_nothingDeleted && _canDelete(workflow)) return false;
        return true;
    }

    function _matchesSearch(workflow) {
        var hay = _hayById[workflow.id] || '';
        return _searchWords.every(function (word) { return hay.indexOf(word) !== -1; });
    }

    /** The Options line opens and closes; its state is not kept. */
    function _onOptionsToggle() {
        if (!_optionsToggle || !_optionsPanel) return;
        var open = _optionsToggle.getAttribute('aria-expanded') !== 'true';
        _optionsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        _optionsPanel.hidden = !open;
    }

    /** Show all: the two switches off, so every row the search keeps is back. */
    function _onShowAll() {
        if (_noAiSwitch) _noAiSwitch.checked = false;
        if (_nothingDeletedSwitch) _nothingDeletedSwitch.checked = false;
        _onOptionChange();
    }

    /** A box of the Options changed: kept, then the list drawn again. */
    function _onOptionChange() {
        _keepOptions();
        _onSwitchChange();
    }

    /**
     * The Options line's words beside its name: the switches that are on, by their own labels, then how many rows they take
     * from what the search keeps. The rows the role cannot run are not counted: no switch of the person's hides them.
     */
    function _renderOptionsSummary(hidden) {
        if (!_optionsSummary) return;
        var parts = [_noAiSwitch, _nothingDeletedSwitch]
            .filter(function (input) { return input && input.checked; })
            .map(function (input) { return input.parentNode.textContent.trim(); });
        if (hidden > 0) parts.push(_t('workflowsOptionsHidden', { n: QSPanelWords.number(hidden) }));
        _optionsSummary.textContent = parts.length > 0 ? ': ' + parts.join(' · ') : '';
    }

    function _onSearchInput(e) {
        var raw = String(e.target.value || '');
        if (_searchDebounceId) clearTimeout(_searchDebounceId);
        _searchDebounceId = setTimeout(function () {
            _searchDebounceId = null;
            _searchWords = _fold(raw).split(/\s+/).filter(Boolean);
            _renderList();
        }, SEARCH_DEBOUNCE_MS);
    }

    /** An option changed, or the list is drawn again: each box is read as it stands. */
    function _onSwitchChange() {
        _noAi = !!(_noAiSwitch && _noAiSwitch.checked);
        _nothingDeleted = !!(_nothingDeletedSwitch && _nothingDeletedSwitch.checked);
        _compact = !!(_compactSwitch && _compactSwitch.checked);
        _showUnrunnable = _unrunnable.length > 0 && !!(_unrunnableSwitch && _unrunnableSwitch.checked);
        _renderList();
    }

    /** A group opened or closed by the reader, once the list folds: the browser keeps which are open. */
    function _onGroupToggle() {
        // A search opens every group holding a match: that is not the reader's choice, so it is not kept.
        if (_filtering() || !_allList) return;
        _listState.open = Array.prototype.slice.call(_allList.querySelectorAll('details.preview-contextual-ai-tools__category-group'))
            .filter(function (g) { return g.open; })
            .map(function (g) { return g.dataset.category; });
        _writeListState();
    }

    // ──────────────────────────── Render: the list ──────────────────────

    /**
     * The list: the rows the search and the switches keep, by group in the panel's order, a group with none left out. From
     * FOLD_AT workflows each group folds to its name and count (the first open until the reader chooses, every group holding
     * a match open during a search), and Recently used comes first.
     */
    function _renderList() {
        if (!_allList) return;
        _allList.replaceChildren();
        if (_loadingEl) _loadingEl.style.display = 'none';
        // What the search keeps, then what the switches keep of it: the difference is what the Options line counts.
        var found = _allWorkflows.filter(_matchesSearch);
        var rows = found.filter(_matchesSwitches);
        var hidden = found.length - rows.length;
        _renderOptionsSummary(hidden);
        // The workflows the role cannot run, greyed, only when asked for; they follow the search and the switches too.
        var inert = _showUnrunnable ? _unrunnable.filter(_matches) : [];
        if (inert.length > 0) rows = _inGroupOrder(rows.concat(inert));
        // Nothing left: the switches hid it (Show all gives it back), or the search found nothing.
        var hiddenAll = rows.length === 0 && hidden > 0;
        if (_optionsEmptyEl) _optionsEmptyEl.hidden = !hiddenAll;
        if (_emptyEl) _emptyEl.style.display = rows.length === 0 && !hiddenAll ? '' : 'none';
        if (rows.length === 0) return;

        var folds = _allWorkflows.length + (_showUnrunnable ? _unrunnable.length : 0) >= FOLD_AT;
        if (folds && !_filtering()) {
            var recent = _recentRows();
            if (recent.length > 0) _allList.appendChild(_renderRecent(recent));
        }
        // The server lists every group a row names; one it did not is shown last, by its own name.
        var order = _groups.slice();
        var known = {};
        order.forEach(function (g) { known[g.id] = true; });
        rows.forEach(function (w) {
            if (known[w.category]) return;
            known[w.category] = true;
            order.push({ id: w.category, title: w.category, line: '' });
        });
        var first = true;
        order.forEach(function (group) {
            var inGroup = rows.filter(function (w) { return w.category === group.id; });
            if (inGroup.length === 0) return;
            var open = !folds || _filtering()
                || (_listState.open === null ? first : _listState.open.indexOf(group.id) !== -1);
            _allList.appendChild(_renderGroup(group, inGroup, folds, open));
            first = false;
        });
    }

    /** Rows of both lists in one: by group in the panel's order (a group it does not hold last), then by title. */
    function _inGroupOrder(rows) {
        var index = {};
        _groups.forEach(function (g, i) { index[g.id] = i; });
        var at = function (w) { return Object.prototype.hasOwnProperty.call(index, w.category) ? index[w.category] : _groups.length; };
        return rows.slice().sort(function (a, b) {
            return at(a) - at(b) || String(a.title || a.id).localeCompare(String(b.title || b.id), QSPanelWords.lang, { sensitivity: 'base' });
        });
    }

    function _recentRows() {
        var byId = {};
        _allWorkflows.forEach(function (w) { byId[w.id] = w; });
        return _listState.recent.map(function (id) { return byId[id]; }).filter(Boolean);
    }

    /** One group: its title and the line under it, then its rows; folded, a <details> whose summary also gives its count. */
    function _renderGroup(group, rows, folds, open) {
        var el = document.createElement(folds ? 'details' : 'section');
        el.className = 'preview-contextual-ai-tools__category-group';
        el.dataset.category = group.id;
        el.appendChild(_renderGroupHeader(group, folds ? rows.length : null));
        el.appendChild(_renderRows(rows));
        if (folds) {
            el.open = open;
            el.addEventListener('toggle', _onGroupToggle);
        }
        return el;
    }

    function _renderGroupHeader(group, count) {
        var header = document.createElement(count === null ? 'h4' : 'summary');
        header.className = 'preview-contextual-ai-tools__category-header'
            + (count === null ? '' : ' preview-contextual-ai-tools__category-header--fold');
        header.appendChild(document.createTextNode(group.title || group.id));
        if (count !== null) {
            header.appendChild(document.createTextNode(' '));
            var countEl = document.createElement('span');
            countEl.className = 'preview-contextual-ai-tools__category-count';
            countEl.textContent = '(' + QSPanelWords.number(count) + ')';
            header.appendChild(countEl);
        }
        if (group.line) {
            var line = document.createElement('span');
            line.className = 'preview-contextual-ai-tools__category-line';
            line.textContent = group.line;
            header.appendChild(line);
        }
        return header;
    }

    /** Recently used: the workflows last opened in this browser, newest first. */
    function _renderRecent(rows) {
        var el = document.createElement('section');
        el.className = 'preview-contextual-ai-tools__recent';
        el.appendChild(_renderGroupHeader({ id: 'recent', title: _t('workflowsRecent'), line: '' }, null));
        el.appendChild(_renderRows(rows));
        return el;
    }

    /** A group's rows: full cards, or small ones side by side in the compact view. A row the role cannot run has nothing to press. */
    function _renderRows(rows) {
        var list = document.createElement('div');
        list.className = 'preview-contextual-ai-tools__tool-list'
            + (_compact ? ' preview-contextual-ai-tools__tool-list--compact' : '');
        rows.forEach(function (w) {
            var inert = _unrunnable.indexOf(w) !== -1;
            list.appendChild(_compact ? _renderSmallCard(w, inert) : _renderCard(w, inert));
        });
        return list;
    }

    // ──────────────────────────── Render: a row ─────────────────────────

    /**
     * A row: its icon and title, the second line (what a run does, in words), then its description. A row the role cannot
     * run (inert) is no button: greyed, it says so first, and nothing in it opens the workflow.
     */
    function _renderCard(workflow, inert) {
        var card = document.createElement(inert ? 'div' : 'button');
        card.className = 'preview-contextual-ai-tools__tool-card'
            + (inert ? ' preview-contextual-ai-tools__tool-card--unrunnable' : '');
        card.dataset.id = workflow.id;
        if (!inert) {
            card.type = 'button';
            card.addEventListener('click', function () {
                _rememberRecent(workflow.id);
                _onCardClick(workflow);
            });
        }

        var header = document.createElement('div');
        header.className = 'preview-contextual-ai-tools__tool-card-header';
        var iconEl = document.createElement('span');
        iconEl.className = 'preview-contextual-ai-tools__tool-card-icon';
        iconEl.textContent = workflow.icon || '📋';
        var titleEl = document.createElement('span');
        titleEl.className = 'preview-contextual-ai-tools__tool-card-title';
        titleEl.textContent = workflow.title || workflow.id;
        header.appendChild(iconEl);
        header.appendChild(titleEl);
        card.appendChild(header);
        if (inert) card.appendChild(_renderUnrunnableLine());
        card.appendChild(_renderRunFacts(workflow));

        if (workflow.description) {
            var desc = document.createElement('p');
            desc.className = 'preview-contextual-ai-tools__tool-card-desc';
            desc.textContent = workflow.description;
            card.appendChild(desc);
        }
        return card;
    }

    /**
     * The second line: one line per part, its icon first (it teaches the icon the compact view shows alone: here the words
     * beside it are what is read, so the icon is hidden from assistive technology), what can be deleted in the warning colour.
     */
    function _renderRunFacts(workflow) {
        var facts = document.createElement('span');
        facts.className = 'preview-contextual-ai-tools__tool-card-facts';
        (_factsById[workflow.id] || _factParts(workflow)).forEach(function (part) {
            var line = document.createElement('span');
            line.className = 'preview-contextual-ai-tools__tool-card-fact'
                + (part.kind === 'delete' ? ' preview-contextual-ai-tools__tool-card-fact--delete' : '');
            var icon = document.createElement('span');
            icon.className = 'preview-contextual-ai-tools__fact-icon';
            icon.setAttribute('aria-hidden', 'true');
            var svg = part.icon ? window.QSDom.icon(part.icon) : null;
            if (svg) icon.appendChild(svg);
            line.appendChild(icon);
            line.appendChild(document.createTextNode(part.text));
            facts.appendChild(line);
        });
        return facts;
    }

    /** The line a row the role cannot run opens with. */
    function _renderUnrunnableLine() {
        var line = document.createElement('span');
        line.className = 'preview-contextual-ai-tools__tool-card-unrunnable';
        line.textContent = _t('workflowsUnrunnable');
        return line;
    }

    /**
     * A small card (the compact view): the workflow's icon, bigger, its name under it (cut after three lines, whole as the
     * card's hover text and accessible name), then the facts' icons alone. Each icon carries its words as its hover text
     * and its accessible name, and the card's button is described by them; what a run can delete is always there, in the
     * warning colour. A tap anywhere on the card opens the workflow, whose review says what it does.
     */
    function _renderSmallCard(workflow, inert) {
        var name = workflow.title || workflow.id;
        var card = document.createElement('div');
        card.className = 'preview-contextual-ai-tools__small-card'
            + (inert ? ' preview-contextual-ai-tools__small-card--unrunnable' : '');
        card.dataset.id = workflow.id;
        card.title = inert ? name + ' · ' + _t('workflowsUnrunnable') : name;

        var face = document.createElement(inert ? 'div' : 'button');
        face.className = 'preview-contextual-ai-tools__small-card-open';
        var iconEl = document.createElement('span');
        iconEl.className = 'preview-contextual-ai-tools__small-card-icon';
        iconEl.setAttribute('aria-hidden', 'true');
        iconEl.textContent = workflow.icon || '📋';
        var nameEl = document.createElement('span');
        nameEl.className = 'preview-contextual-ai-tools__small-card-name';
        nameEl.textContent = name;
        face.appendChild(iconEl);
        face.appendChild(nameEl);
        card.appendChild(face);

        var facts = _renderFactIcons(workflow);
        card.appendChild(facts);
        if (inert) {
            // Nothing to press: the card is named with its reason, as its line is not drawn here.
            card.setAttribute('role', 'group');
            card.setAttribute('aria-label', card.title);
        } else {
            face.type = 'button';
            face.setAttribute('aria-label', name);
            face.setAttribute('aria-describedby', facts.id);
            card.addEventListener('click', function () {
                _rememberRecent(workflow.id);
                _onCardClick(workflow);
            });
        }
        return card;
    }

    /**
     * A small card's facts: one icon per kind (AI or not, can delete, needs), its words those of the row's lines of that
     * kind; a kind with no words is not drawn, so no icon is ever nameless. What a run changes has no icon.
     */
    function _renderFactIcons(workflow) {
        var facts = document.createElement('span');
        facts.className = 'preview-contextual-ai-tools__small-card-facts';
        facts.id = 'ai-tools-facts-' + (++_factsIdSeq);
        var byKind = {};
        var kinds = [];
        (_factsById[workflow.id] || _factParts(workflow)).forEach(function (part) {
            if (!part.icon || !part.text) return;
            if (!byKind[part.kind]) {
                byKind[part.kind] = { icon: part.icon, words: [] };
                kinds.push(part.kind);
            }
            byKind[part.kind].words.push(part.text);
        });
        kinds.forEach(function (kind) {
            var words = byKind[kind].words.join(' · ');
            var svg = window.QSDom.icon(byKind[kind].icon);
            if (!svg) return;
            var icon = document.createElement('span');
            icon.className = 'preview-contextual-ai-tools__fact-icon'
                + (kind === 'delete' ? ' preview-contextual-ai-tools__fact-icon--delete' : '');
            icon.setAttribute('role', 'img');
            icon.setAttribute('aria-label', words);
            icon.title = words;
            icon.dataset.kind = kind;
            icon.appendChild(svg);
            facts.appendChild(icon);
        });
        return facts;
    }


    // ════════════════════════════════════════════════════════════════════
    //                          RUNNER VIEW
    // ════════════════════════════════════════════════════════════════════

    /**
     * Open a workflow's runner. opts.restore: {params, requests} put back over the defaults (kept steps
     * resumed); the promise settles once the runner is drawn and its plan asked for.
     */
    function _onCardClick(workflow, opts) {
        opts = opts || {};
        _activeWorkflowSummary = workflow;
        _activeWorkflowSpec = null;
        _activeTemplate = null;
        _fetchedData = {};
        _paramValues = {};
        _requests = {};
        _generatedPrompt = '';
        _aiResponseText = '';
        _aiResponseStatus = { type: 'idle' };
        _plan = [];
        _answerPhase = null;
        _exchangePhase = null;
        _showRunnerView();
        _renderRunnerLoading(workflow);
        return _fetchWorkflowDetail(workflow.id).then(function (detail) {
            _activeWorkflowSpec = detail.spec || null;
            _activeTemplate = detail.template || '';
            _fetchedData = detail.fetchedData || {};
            _initParamValues();
            if (opts.restore) {
                Object.keys(opts.restore.params || {}).forEach(function (k) { _paramValues[k] = opts.restore.params[k]; });
                _requests = Object.assign({}, opts.restore.requests || {});
            }
            _renderRunner();
            // The plan before Run: every phase with its steps and what they delete or change.
            return _refreshPlan();
        }).catch(function (err) {
            _renderRunnerError(err && err.message ? err.message : String(err));
        });
    }

    function _onBackToList() {
        if (_run) return;
        _activeWorkflowSummary = null;
        _activeWorkflowSpec = null;
        _activeTemplate = null;
        _fetchedData = {};
        _paramValues = {};
        _requests = {};
        _generatedPrompt = '';
        _aiResponseText = '';
        _aiResponseStatus = { type: 'idle' };
        _plan = [];
        _answerPhase = null;
        _exchangePhase = null;
        _showListView();
    }

    function _fetchWorkflowDetail(id) {
        var cfg = window.PreviewConfig || {};
        var url = (cfg.adminUrl || '') + 'api/' + _helperPath('ai-spec-raw') + '/' + encodeURIComponent(id) + '?withData=1';
        var headers = { 'Accept': 'application/json', 'Accept-Language': QSPanelWords.lang };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        return fetch(url, { headers: headers }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            if (!json || !json.success) throw new Error((json && json.error) || QSPanelWords.t('common.unknownError'));
            return json.data || {};
        });
    }

    function _initParamValues() {
        _paramValues = {};
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            if (p.type === 'selector') {
                _paramValues[p.id] = _readSelectionForSelector();
            } else if (Object.prototype.hasOwnProperty.call(p, 'default')) {
                _paramValues[p.id] = p.default;
            } else if (p.type === 'checkbox') {
                _paramValues[p.id] = false;
            } else if (p.type === 'tag-select') {
                _paramValues[p.id] = [];
            } else {
                _paramValues[p.id] = '';
            }
        }
    }

    function _readSelectionForSelector() {
        try {
            var pm = window.PreviewManager;
            if (!pm || typeof pm.getSelectedNode !== 'function') return null;
            var sel = pm.getSelectedNode() || {};
            if (!sel.tag && (!sel.classes || sel.classes.length === 0) && !sel.node) return null;
            return {
                tag: sel.tag || '',
                classes: Array.isArray(sel.classes) ? sel.classes : [],
                struct: sel.struct || '',
                node: sel.node || ''
            };
        } catch (e) {
            return null;
        }
    }

    /** Whether the workflow itself is an AI one (its main phase sends a prompt). */
    function _isAiWorkflow() {
        if (!_activeWorkflowSpec) return false;
        return !!(_activeWorkflowSpec.promptTemplate || _activeTemplate);
    }

    /** The AI phases of the run as last planned; before the plan, the workflow itself when it is an AI one. */
    function _aiPhases() {
        if (_plan.length > 0) return _plan.filter(function (p) { return p.ai; });
        return _isAiWorkflow() ? [{ ai: true, index: 0, workflowId: _activeWorkflowSpec.id, title: '' }] : [];
    }

    /** Whether the run has an AI phase: the AI exchange zone and a Your prompt field are drawn for it. */
    function _chainHasAi() {
        return _aiPhases().length > 0;
    }

    /** Whether the run's first phase is an AI one: an answer can then be pasted before anything runs. */
    function _firstPhaseIsAi() {
        return _plan.length > 0 ? !!_plan[0].ai : _isAiWorkflow() && !_hasSubWorkflows(_activeWorkflowSpec);
    }

    function _hasSubWorkflows(spec) {
        return !!(spec && ((spec.preWorkflows && spec.preWorkflows.length) || (spec.postWorkflows && spec.postWorkflows.length)));
    }

    /**
     * An AI phase's key for its "Your prompt": its workflow, numbered when the run holds it more than
     * once — stable when a conditional phase before it comes and goes. A phase that takes its parent's
     * request (the server's requestFrom, from "request": "parent") has that phase's key.
     */
    function _requestKey(phase, phases) {
        var list = phases || _plan;
        if (typeof phase.requestFrom === 'number' && list[phase.requestFrom]) {
            return _requestKey(list[phase.requestFrom], list);
        }
        var n = 0;
        for (var i = 0; i < list.length && i < phase.index; i++) {
            if (list[i].workflowId === phase.workflowId) n++;
        }
        return phase.workflowId + (n > 0 ? '#' + (n + 1) : '');
    }

    /** The AI phases whose prompt carries this request, in order (more than one: a request handed down). */
    function _requestPhases(key, phases) {
        var list = phases || _plan;
        return list.filter(function (p) { return p.ai && _requestKey(p, list) === key; });
    }

    // ──────────────────────────── Condition evaluation ──────────────────

    function _evalCondition(expr, paramValues) {
        if (!expr || typeof expr !== 'string') return true;
        try {
            var keys = Object.keys(paramValues);
            var vals = keys.map(function (k) { return paramValues[k]; });
            // `chosen` holds, for each picker of templates, the template picked (or null): chosen.template.dark.
            var args = keys.concat(['param', 'chosen', 'return (' + expr + ');']);
            var fn = Function.apply(null, args);
            return !!fn.apply(null, vals.concat([paramValues, _chosenTemplates()]));
        } catch (e) {
            console.warn('[PreviewAiTools] condition eval failed:', expr, e);
            return true;
        }
    }

    function _isParamVisible(param) {
        return _evalCondition(param.condition, _paramValues);
    }

    function _updateParamVisibility() {
        if (!_runnerView) return;
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            var el = _runnerView.querySelector('[data-param-id="' + p.id + '"]');
            if (!el) continue;
            var visible = _isParamVisible(p);
            el.classList.toggle('preview-contextual-ai-tools__param--hidden', !visible);
        }
    }

    function _onParamChanged(changedParamId) {
        var changed = _findParam(changedParamId);
        if (changed && changed.type === 'template') _applyTemplateChoice(changed);
        _updateParamVisibility();
        _updateDependentSelects(changedParamId);
        _renderParamErrors();
        _schedulePreviewRefresh();
    }

    var _previewRefreshTimer = null;
    /** The plan again, a short while after the last parameter change (never during a run: its fields are locked). */
    function _schedulePreviewRefresh() {
        if (_previewRefreshTimer) { clearTimeout(_previewRefreshTimer); _previewRefreshTimer = null; }
        if (_run) return;
        _previewRefreshTimer = setTimeout(function () {
            _previewRefreshTimer = null;
            if (!_run) _refreshPlan();
        }, 400);
    }

    // ──────────────────────────── optionsFrom (incl. filterFrom, excludeFrom) ──

    /**
     * A field's options from its data requirement: a list (each item its value and label, or the
     * item's `value` / `label` fields) or a map (each key a value, its value the label: Translate
     * Site's language names). filterFrom keeps only the values another parameter holds, excludeFrom
     * leaves them out (e.g. defaultLanguage restricted to the languages picked; Translate Site's
     * language to write never the one translated from).
     */
    function _resolveOptionsFromData(param) {
        var of = param.optionsFrom;
        if (!of) return null;
        var dataKey = typeof of === 'string' ? of : of.data;
        if (!dataKey) return null;
        var fetched = _fetchedData ? _fetchedData[dataKey] : null;
        var isMap = fetched !== null && typeof fetched === 'object' && !Array.isArray(fetched);
        if (!Array.isArray(fetched) && !isMap) return null;
        var valueField = (typeof of === 'object') ? of.value : null;
        var labelField = (typeof of === 'object') ? of.label : null;
        var prepend = (typeof of === 'object' && Array.isArray(of.prepend)) ? of.prepend : [];
        var options = prepend.slice();
        if (isMap) {
            Object.keys(fetched).forEach(function (key) {
                options.push({ value: key, label: fetched[key] != null ? String(fetched[key]) : key });
            });
        } else {
            for (var i = 0; i < fetched.length; i++) {
                var item = fetched[i];
                if (valueField) {
                    options.push({
                        value: item[valueField],
                        label: item[labelField] != null ? String(item[labelField]) : String(item[valueField])
                    });
                } else {
                    options.push({ value: item, label: String(item) });
                }
            }
        }

        if (typeof of === 'object') {
            // filterFrom names one parameter or several: each one that holds something narrows the list.
            [].concat(of.filterFrom || []).forEach(function (id) {
                var keep = _refValues(id);
                if (keep) options = options.filter(function (o) { return keep.indexOf(o.value) !== -1; });
            });
            var drop = of.excludeFrom ? _refValues(of.excludeFrom) : null;
            if (drop) options = options.filter(function (o) { return drop.indexOf(o.value) === -1; });
        }
        return options;
    }

    /**
     * The value(s) another parameter holds, as a list, for filterFrom and excludeFrom: null while that
     * parameter is hidden or empty, which then filters nothing.
     */
    function _refValues(id) {
        var refParam = _findParam(id);
        if (!refParam || !_isParamVisible(refParam)) return null;
        if (refParam.type === 'template') {
            // A picker of templates stands for the languages of the template picked.
            var picked = _chosenTemplate(refParam);
            return picked && Array.isArray(picked.languages) && picked.languages.length > 0 ? picked.languages : null;
        }
        var refVal = _paramValues[id];
        var list = Array.isArray(refVal) ? refVal : (refVal != null && refVal !== '' ? [refVal] : []);
        return list.length > 0 ? list : null;
    }

    function _findParam(id) {
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            if (params[i].id === id) return params[i];
        }
        return null;
    }

    function _updateDependentSelects(changedParamId) {
        // Rebuild ALL selects with a filterFrom or an excludeFrom on any param change but their own.
        // Why not restrict to selects that name changedParamId?
        // Because the dependency can be transitive: defaultLanguage filters
        // from `languages`, but `languages` becomes hidden when multilingual
        // changes — so defaultLanguage's filter outcome can shift without
        // languages itself being the immediate trigger. Rebuilding them all
        // on any change is cheap (typically 0–2 per workflow) and side-steps
        // cascade tracking. A field's own change never rebuilds it: its options
        // never depend on its own value (filterFrom and excludeFrom name another
        // parameter), and a searchable one would be destroyed under the pick
        // that changed it.
        if (!_runnerView) return;
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            var of = p.optionsFrom;
            if (!of || typeof of !== 'object' || !(of.filterFrom || of.excludeFrom) || p.id === changedParamId) continue;
            var wrap = _runnerView.querySelector('[data-param-id="' + p.id + '"]');
            if (!wrap) continue;
            // The field's control as a whole: a searchable one holds more than one input.
            var oldInput = wrap.querySelector('[data-param-control]');
            if (!oldInput) continue;
            var newInput = _renderParamInput(p);
            if (!newInput) continue;
            _destroyParamPicker(oldInput);
            oldInput.replaceWith(newInput);
            _mountParamPicker(newInput);
        }
    }

    // ──────────────────────────── Searchable fields ─────────────────────

    var _paramPickers = []; // the QSSearchableSelect over each searchable select on screen

    /** The id of a field's control, which its label points at; the label's own id adds '-label'. */
    function _paramControlId(pid) {
        return 'ai-tools-param-' + String(pid).replace(/\s+/g, '-');
    }

    /** The element of a field's control that its label names (it carries the control's id), or null. */
    function _labelledControl(control, pid) {
        var id = _paramControlId(pid);
        if (control.id === id) return control;
        var inner = control.querySelectorAll('[id]');
        for (var i = 0; i < inner.length; i++) {
            if (inner[i].id === id) return inner[i];
        }
        return null;
    }

    /**
     * A searchable select's dropdown (the panel's QSSearchableSelect), mounted once its field is
     * in the page: the widget takes its name from the label that points at its select, and points
     * that label at itself, so a click on the label opens it.
     */
    function _mountParamPicker(control) {
        if (!control.classList.contains('preview-contextual-ai-tools__param-searchable')
            || control._qsPicker || !window.QSSearchableSelect) return;
        var select = control.querySelector('select');
        if (!select) return;
        control._qsPicker = new window.QSSearchableSelect(select);
        _paramPickers.push(control._qsPicker);
    }

    function _destroyParamPicker(control) {
        var picker = control._qsPicker;
        if (!picker) return;
        picker.destroy();
        control._qsPicker = null;
        _paramPickers = _paramPickers.filter(function (p) { return p !== picker; });
    }

    function _destroyParamPickers() {
        _paramPickers.forEach(function (p) { p.destroy(); });
        _paramPickers = [];
    }

    // ──────────────────────────── Runner: render shells ─────────────────

    function _renderRunnerLoading(workflow) {
        if (!_runnerView) return;
        _destroyParamPickers();
        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(workflow));
        var loading = document.createElement('div');
        loading.className = 'preview-contextual-ai-tools__runner-loading';
        loading.textContent = _t('aiToolsRunnerLoading');
        _runnerView.appendChild(loading);
    }

    function _renderRunnerError(message) {
        if (!_runnerView) return;
        _destroyParamPickers();
        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(_activeWorkflowSummary || {}));
        var err = document.createElement('div');
        err.className = 'preview-contextual-ai-tools__runner-error';
        err.textContent = _t('aiToolsRunnerError', { error: message });
        _runnerView.appendChild(err);
    }

    function _renderRunner() {
        if (!_runnerView) return;
        var workflow = _activeWorkflowSummary || {};
        var spec = _activeWorkflowSpec || {};
        var isAI = _chainHasAi();
        var conn = _getDefaultConnection();
        _renderedWithAi = isAI;

        // Reset live refs
        _generalPromptTextarea = null;
        _copyBtn = null;
        _aiResponseTextarea = null;
        _aiResponseStatusEl = null;
        _paramErrors = {};
        _stepsBody = null;
        _stepsHeaderState = null;
        _stepsSetOpen = null;
        _primaryActionBtn = null;
        _secondaryActionBtn = null;
        _executeBtnEl = null;
        _modelSelectEl = null;
        _currentCommands = [];
        _autoExecHintEl = null;
        _runLineEl = null;
        _reviewEl = null;
        _yourPromptBody = null;
        _exchangeHeaderEl = null;
        _phaseCostEl = null;
        _costEl = null;
        if (_pasteAutoExecTimer) { clearTimeout(_pasteAutoExecTimer); _pasteAutoExecTimer = null; }

        _destroyParamPickers();
        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(workflow));
        if (workflow.description) {
            _runnerView.appendChild(_renderRunnerDescription(workflow.description));
        }

        _runnerView.appendChild(_renderInputsZone(spec, isAI));
        // Each searchable select's dropdown, now that its field and its label are in the page.
        var searchable = _runnerView.querySelectorAll('.preview-contextual-ai-tools__param-searchable');
        for (var s = 0; s < searchable.length; s++) {
            _mountParamPicker(searchable[s]);
        }
        if (isAI) {
            _runnerView.appendChild(_renderAiExchangeZone(spec, conn !== null));
        }
        _runnerView.appendChild(_renderExecutionZone(spec));
        _runnerView.appendChild(_renderFooter(spec, conn, isAI));

        // Initial visibility + validation pass — must happen AFTER the
        // parameters section + action row are in the DOM. _renderParamErrors
        // is safe with zero params (it iterates an empty list and still
        // calls _updateActionStates so the primary button shows the right
        // label for the current workflow/BYOK/auto-execute state).
        if (Array.isArray(spec.parameters) && spec.parameters.length > 0) {
            _updateParamVisibility();
        }
        _renderParamErrors();
        _renderCost();
    }

    function _renderRunnerHeader(workflow) {
        var bar = document.createElement('div');
        bar.className = 'preview-contextual-ai-tools__runner-header';

        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'preview-contextual-ai-tools__runner-back';
        backBtn.textContent = _t('aiToolsBackToList');
        backBtn.addEventListener('click', _onBackToList);
        bar.appendChild(backBtn);

        var titleWrap = document.createElement('div');
        titleWrap.className = 'preview-contextual-ai-tools__runner-title-wrap';

        var icon = document.createElement('span');
        icon.className = 'preview-contextual-ai-tools__runner-icon';
        icon.textContent = workflow.icon || '📋';
        titleWrap.appendChild(icon);

        var title = document.createElement('h3');
        title.className = 'preview-contextual-ai-tools__runner-title';
        title.textContent = workflow.title || workflow.id || '';
        titleWrap.appendChild(title);

        bar.appendChild(titleWrap);
        return bar;
    }

    function _renderRunnerDescription(text) {
        var p = document.createElement('p');
        p.className = 'preview-contextual-ai-tools__runner-description';
        p.textContent = text;
        return p;
    }

    // ──────────────────────────── Zones ─────────────────────────────────

    function _renderZone(zoneKey, label) {
        var zone = document.createElement('section');
        zone.className = 'preview-contextual-ai-tools__zone preview-contextual-ai-tools__zone--' + zoneKey;
        var header = document.createElement('h4');
        header.className = 'preview-contextual-ai-tools__zone-header';
        header.textContent = label;
        zone.appendChild(header);
        return { section: zone, body: zone };
    }

    // Whether the runner on screen was drawn with an AI exchange: a plan that adds or drops an AI
    // phase draws it again (_refreshPlan()).
    var _renderedWithAi = false;

    function _renderInputsZone(spec, isAI) {
        var z = _renderZone('inputs', _t('aiToolsZoneInputs'));
        if (isAI) {
            z.body.appendChild(_renderSectionYourPrompt());
        }
        if (Array.isArray(spec.parameters) && spec.parameters.length > 0) {
            z.body.appendChild(_renderSectionParameters(spec.parameters));
        }
        z.body.appendChild(_renderActionRow(isAI));
        return z.section;
    }

    function _renderActionRow(isAI) {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__input-action-row';
        var conn = _getDefaultConnection();
        var hasBYOK = !!conn;

        // Model picker — inline select, BYOK + AI workflow only
        if (isAI && hasBYOK) {
            wrap.appendChild(_renderModelRow(conn));
        }

        // Action button row: primary + optional secondary
        var row = document.createElement('div');
        row.className = 'preview-contextual-ai-tools__action-row';

        _primaryActionBtn = document.createElement('button');
        _primaryActionBtn.type = 'button';
        _primaryActionBtn.className = 'preview-contextual-ai-tools__action-btn--primary';
        _primaryActionBtn.addEventListener('click', _onPrimaryActionClick);
        row.appendChild(_primaryActionBtn);

        // Secondary "Generate for copy" — available for AI workflows even
        // when BYOK is configured (user can still inspect / copy the prompt
        // to use elsewhere).
        if (isAI && hasBYOK) {
            _secondaryActionBtn = document.createElement('button');
            _secondaryActionBtn.type = 'button';
            _secondaryActionBtn.className = 'preview-contextual-ai-tools__action-btn--secondary';
            _secondaryActionBtn.textContent = _t('aiToolsActionGenerateForCopy');
            _secondaryActionBtn.addEventListener('click', _onSecondaryActionClick);
            row.appendChild(_secondaryActionBtn);
        }

        wrap.appendChild(row);
        return wrap;
    }

    function _renderModelRow(connection) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-ai-tools__model-row';
        var label = document.createElement('span');
        label.className = 'preview-contextual-ai-tools__model-row-label';
        label.textContent = _t('aiToolsModelLabel');
        row.appendChild(label);

        _modelSelectEl = document.createElement('select');
        _modelSelectEl.className = 'preview-contextual-ai-tools__model-select';

        var models = Array.isArray(connection.enabledModels) && connection.enabledModels.length
            ? connection.enabledModels
            : (Array.isArray(connection.models) ? connection.models : []);
        if (!models.length && connection.defaultModel) models = [connection.defaultModel];
        if (!models.length && connection.model) models = [connection.model];

        var selected = connection.defaultModel || connection.model || (models[0] || '');
        for (var i = 0; i < models.length; i++) {
            var opt = document.createElement('option');
            opt.value = models[i];
            opt.textContent = models[i];
            if (models[i] === selected) opt.selected = true;
            _modelSelectEl.appendChild(opt);
        }
        _modelSelectEl.addEventListener('change', function () {
            // Persist to the connection's defaultModel so other surfaces honor it.
            if (window.QSConnectionsStore && typeof window.QSConnectionsStore.updateConnection === 'function') {
                try {
                    window.QSConnectionsStore.updateConnection(connection.id, { defaultModel: _modelSelectEl.value });
                } catch (e) { /* ignore */ }
            }
        });
        row.appendChild(_modelSelectEl);
        return row;
    }

    function _renderAiExchangeZone(spec, hasBYOK) {
        var z = _renderZone('exchange', _t('aiToolsZoneExchange'));
        _exchangeHeaderEl = z.section.querySelector('.preview-contextual-ai-tools__zone-header');
        z.body.appendChild(_renderSectionGeneralPrompt(hasBYOK));
        z.body.appendChild(_renderSectionAiResponseTextarea(hasBYOK));
        return z.section;
    }

    function _renderExecutionZone(spec) {
        var z = _renderZone('execution', _t('aiToolsZoneExecution'));
        z.body.appendChild(_renderExecuteRow());
        _runLineEl = document.createElement('p');
        _runLineEl.className = 'preview-contextual-ai-tools__run-line';
        _runLineEl.hidden = true;
        z.body.appendChild(_runLineEl);
        _reviewEl = document.createElement('div');
        _reviewEl.className = 'preview-contextual-ai-tools__review';
        _reviewEl.hidden = true;
        z.body.appendChild(_reviewEl);
        // The run's outside pictures, once it ends (_renderOutside()).
        _outsideEl = document.createElement('div');
        _outsideEl.className = 'preview-contextual-ai-tools__outside';
        _outsideEl.setAttribute('role', 'status');
        _outsideEl.hidden = true;
        z.body.appendChild(_outsideEl);
        _costEl = document.createElement('div');
        _costEl.className = 'preview-contextual-ai-tools__cost';
        _costEl.hidden = true;
        z.body.appendChild(_costEl);
        z.body.appendChild(_renderSectionBatch(spec));
        return z.section;
    }

    function _renderExecuteRow() {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__execute-row';

        // "Run without stopping", for every workflow: an AI answer runs once it parses, and the run goes
        // from one phase to the next without a review (a phase that deletes or changes content more
        // than was confirmed still asks).
        var toggles = document.createElement('div');
        toggles.className = 'preview-contextual-ai-tools__toggles';
        var toggle = _renderToggle('aiAutoExecute', _t('aiToolsRunWithoutStoppingLabel'));
        toggle.title = _t('aiToolsRunWithoutStoppingHint');
        toggles.appendChild(toggle);
        wrap.appendChild(toggles);

        // The AI answer's "Execute" — shown while an answer is ready and the switch is off; its label
        // says what the answer deletes or changes (_updateActionStates()).
        _executeBtnEl = document.createElement('button');
        _executeBtnEl.type = 'button';
        _executeBtnEl.className = 'preview-contextual-ai-tools__action-btn--primary';
        _executeBtnEl.textContent = _t('aiToolsActionExecute');
        _executeBtnEl.style.display = 'none';
        _executeBtnEl.addEventListener('click', _onExecuteClick);
        wrap.appendChild(_executeBtnEl);
        return wrap;
    }

    function _renderFooter(spec, conn, isAI) {
        var footer = document.createElement('div');
        footer.className = 'preview-contextual-ai-tools__footer';
        var commands = _relatedCommandNames(spec);
        if (commands.length > 0) {
            footer.appendChild(_renderFooterItem(_t('aiToolsFooterTouches'), _renderFooterCommands(commands)));
        }
        return footer;
    }

    /**
     * Every command a spec's relatedCommands names, whatever an entry's condition: what a run can touch, as the
     * server's WorkflowManager::relatedCommands() reads it with no parameters (an entry is a name or {command, condition}).
     */
    function _relatedCommandNames(spec) {
        var list = Array.isArray(spec.relatedCommands) ? spec.relatedCommands : [];
        var names = [];
        list.forEach(function (entry) {
            var name = typeof entry === 'string' ? entry : (entry && typeof entry.command === 'string' ? entry.command : '');
            if (name) names.push(name);
        });
        return names;
    }

    function _renderFooterItem(label, valueEl) {
        var item = document.createElement('span');
        item.className = 'preview-contextual-ai-tools__footer-item';
        var labelEl = document.createElement('span');
        labelEl.className = 'preview-contextual-ai-tools__footer-label';
        labelEl.textContent = label;
        item.appendChild(labelEl);
        item.appendChild(valueEl);
        return item;
    }

    function _renderFooterCommands(commands) {
        var wrap = document.createElement('span');
        wrap.className = 'preview-contextual-ai-tools__footer-commands';
        for (var i = 0; i < commands.length; i++) {
            var chip = document.createElement('span');
            chip.className = 'preview-contextual-ai-tools__footer-command';
            chip.textContent = commands[i];
            wrap.appendChild(chip);
        }
        return wrap;
    }

    // ──────────────────────────── Runner: collapsibles ──────────────────

    function _renderCollapsibleSection(opts) {
        var section = document.createElement('section');
        section.className = 'preview-contextual-ai-tools__runner-section';
        if (opts.dataKey) section.dataset.section = opts.dataKey;

        var header = document.createElement('button');
        header.type = 'button';
        header.className = 'preview-contextual-ai-tools__runner-section-header';

        var chevron = document.createElement('span');
        chevron.className = 'preview-contextual-ai-tools__runner-section-chevron';
        chevron.textContent = opts.defaultOpen ? '▾' : '▸';
        header.appendChild(chevron);

        var titleEl = document.createElement('span');
        titleEl.className = 'preview-contextual-ai-tools__runner-section-title';
        titleEl.textContent = opts.title;
        header.appendChild(titleEl);

        var stateEl = null;
        if (opts.stateHeader !== undefined && opts.stateHeader !== null) {
            stateEl = document.createElement('span');
            stateEl.className = 'preview-contextual-ai-tools__runner-section-state';
            stateEl.textContent = opts.stateHeader;
            header.appendChild(stateEl);
        }

        var body = document.createElement('div');
        body.className = 'preview-contextual-ai-tools__runner-section-body';
        if (!opts.defaultOpen) {
            body.style.display = 'none';
        }

        function setOpen(open) {
            body.style.display = open ? '' : 'none';
            chevron.textContent = open ? '▾' : '▸';
        }
        header.addEventListener('click', function () {
            setOpen(body.style.display === 'none');
        });

        section.appendChild(header);
        section.appendChild(body);
        return { section: section, body: body, header: header, stateEl: stateEl, setOpen: setOpen };
    }

    // ──────────────────────────── Sections ──────────────────────────────

    function _renderSectionYourPrompt() {
        var s = _renderCollapsibleSection({
            title: _t('aiToolsSectionYourPrompt'),
            defaultOpen: true,
            dataKey: 'your-prompt'
        });
        _yourPromptBody = s.body;
        _renderYourPromptFields();
        return s.section;
    }

    /**
     * One "Your prompt" field per request of the run — an AI phase's own, or one its sub-workflows take
     * from it — each read when a phase that takes it starts: named by its workflow when the run has more
     * than one. A field locks while a phase that takes it runs, and for good once the last one starts.
     */
    function _renderYourPromptFields() {
        if (!_yourPromptBody) return;
        _yourPromptBody.replaceChildren();
        var phases = _aiPhases();
        var owners = [];
        var seen = {};
        for (var i = 0; i < phases.length; i++) {
            var key = _requestKey(phases[i]);
            if (seen[key]) continue;
            seen[key] = true;
            owners.push(phases[i]);
        }
        for (var o = 0; o < owners.length; o++) {
            _yourPromptBody.appendChild(_renderRequestField(owners[o], owners.length > 1));
        }
    }

    /**
     * One request's field (named when several are listed, or when idSuffix marks the review's copy of
     * it); its text kept in _requests. A request several phases take is named as shared in a review.
     */
    function _renderRequestField(phase, named, idSuffix) {
        var key = _requestKey(phase);
        var shared = _requestPhases(key).length > 1;
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__request';
        var ta = document.createElement('textarea');
        ta.className = 'preview-contextual-ai-tools__user-prompt';
        ta.id = 'ai-tools-request-' + key.replace(/[^\w-]/g, '-') + (idSuffix || '');
        ta.dataset.requestKey = key;
        ta.placeholder = _t('aiToolsYourPromptPlaceholder');
        ta.value = _requests[key] || '';
        ta.disabled = !!(_run && ((_run.started && _run.started[key]) || _run.activeRequest === key));
        ta.addEventListener('input', function () {
            _requests[key] = ta.value;
            // The same request may be on screen twice: in the inputs and in the review before its phase.
            var twins = _runnerView ? _runnerView.querySelectorAll('textarea[data-request-key]') : [];
            for (var t = 0; t < twins.length; t++) {
                if (twins[t] !== ta && twins[t].dataset.requestKey === key) twins[t].value = ta.value;
            }
        });
        if (named) {
            var label = document.createElement('label');
            label.className = 'preview-contextual-ai-tools__request-label';
            label.htmlFor = ta.id;
            label.textContent = shared && idSuffix
                ? _t('aiToolsRequestShared')
                : _t('aiToolsRequestFor', { name: phase.title || phase.workflowId });
            wrap.appendChild(label);
        }
        wrap.appendChild(ta);
        return wrap;
    }

    function _renderSectionParameters(params) {
        var s = _renderCollapsibleSection({
            title: _t('aiToolsSectionParameters'),
            defaultOpen: true,
            dataKey: 'parameters'
        });
        for (var i = 0; i < params.length; i++) {
            s.body.appendChild(_renderParamField(params[i]));
        }
        return s.section;
    }

    function _renderParamField(param) {
        var type = param.type || 'text';
        if (type === 'checkbox') {
            return _renderParamFieldInline(param);
        }
        return _renderParamFieldStacked(param);
    }

    function _renderParamFieldStacked(param) {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__param';
        wrap.dataset.paramId = param.id;

        var label = document.createElement('label');
        label.className = 'preview-contextual-ai-tools__param-label';
        label.id = _paramControlId(param.id) + '-label';
        label.textContent = param.label || param.labelKey || param.id;
        wrap.appendChild(label);

        if (param.help) {
            var help = document.createElement('p');
            help.className = 'preview-contextual-ai-tools__param-help';
            help.textContent = param.help;
            wrap.appendChild(help);
        }

        var input = _renderParamInput(param);
        if (input) {
            wrap.appendChild(input);
            // The label names its control and a click on it reaches it: the select (a
            // searchable one's dropdown, once mounted), the input or textarea, or a
            // searchable list's search field. A list of checkboxes is a group it names.
            if (_labelledControl(input, param.id)) label.htmlFor = _paramControlId(param.id);
        }

        wrap.appendChild(_renderParamErrorSlot());
        return wrap;
    }

    function _renderParamFieldInline(param) {
        // Checkbox layout: [☑] Label / help (label wraps everything for click-anywhere).
        var wrap = document.createElement('label');
        wrap.className = 'preview-contextual-ai-tools__param preview-contextual-ai-tools__param--inline';
        wrap.dataset.paramId = param.id;

        var input = _renderParamInput(param);
        if (input) wrap.appendChild(input);

        var content = document.createElement('div');
        content.className = 'preview-contextual-ai-tools__param-inline-content';

        var labelEl = document.createElement('span');
        labelEl.className = 'preview-contextual-ai-tools__param-label';
        labelEl.textContent = param.label || param.labelKey || param.id;
        content.appendChild(labelEl);

        if (param.help) {
            var help = document.createElement('p');
            help.className = 'preview-contextual-ai-tools__param-help';
            help.textContent = param.help;
            content.appendChild(help);
        }
        content.appendChild(_renderParamErrorSlot());
        wrap.appendChild(content);
        return wrap;
    }

    function _renderParamErrorSlot() {
        var p = document.createElement('p');
        p.className = 'preview-contextual-ai-tools__param-error';
        p.style.display = 'none';
        return p;
    }

    // A field's control carries data-param-control, so the cascade (_updateDependentSelects)
    // replaces it whole.
    function _renderParamInput(param) {
        var control = _buildParamInput(param);
        if (control) control.dataset.paramControl = param.id;
        return control;
    }

    function _buildParamInput(param) {
        var type = param.type || 'text';
        var pid = param.id;
        var input;
        if (type === 'checkbox') {
            input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'preview-contextual-ai-tools__param-checkbox';
            input.checked = !!_paramValues[pid];
            input.addEventListener('change', function () {
                _paramValues[pid] = input.checked;
                _onParamChanged(pid);
            });
        } else if (type === 'select') {
            input = document.createElement('select');
            input.className = 'preview-contextual-ai-tools__param-select';
            input.id = _paramControlId(pid);
            var options = Array.isArray(param.options)
                ? param.options
                : _resolveOptionsFromData(param);
            if (!options || options.length === 0) {
                return _renderEmptyOptionsPlaceholder();
            }
            var held = false;
            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                var optEl = document.createElement('option');
                optEl.value = opt.value;
                optEl.textContent = opt.label || opt.value;
                if (String(opt.value) === String(_paramValues[pid])) {
                    optEl.selected = true;
                    held = true;
                }
                input.appendChild(optEl);
            }
            // The value must be one the list offers: one the cascade took out moves to the
            // first option. A select always shows an option selected, so its state alone
            // cannot tell.
            if (!held) {
                input.selectedIndex = 0;
                _paramValues[pid] = options[0].value;
            }
            input.addEventListener('change', function () {
                _paramValues[pid] = input.value;
                _onParamChanged(pid);
            });
            if (param.searchable) {
                // The panel's searchable dropdown over this select, mounted once the field
                // is in the page (_mountParamPicker).
                var holder = document.createElement('div');
                holder.className = 'preview-contextual-ai-tools__param-searchable';
                holder.appendChild(input);
                return holder;
            }
        } else if (type === 'tag-select') {
            var tagOptions = Array.isArray(param.options)
                ? param.options
                : _resolveOptionsFromData(param);
            if (!tagOptions || tagOptions.length === 0) {
                return _renderEmptyOptionsPlaceholder();
            }
            return _renderTagSelect(param, tagOptions);
        } else if (type === 'textarea') {
            input = document.createElement('textarea');
            input.className = 'preview-contextual-ai-tools__param-textarea';
            input.id = _paramControlId(pid);
            input.rows = 3;
            if (param.placeholder) input.placeholder = param.placeholder;
            input.value = _paramValues[pid] != null ? String(_paramValues[pid]) : '';
            input.addEventListener('input', function () {
                _paramValues[pid] = input.value;
                _onParamChanged(pid);
            });
        } else if (type === 'number') {
            input = document.createElement('input');
            input.type = 'number';
            input.className = 'preview-contextual-ai-tools__param-input';
            input.id = _paramControlId(pid);
            if (param.placeholder) input.placeholder = param.placeholder;
            input.value = _paramValues[pid] != null ? String(_paramValues[pid]) : '';
            input.addEventListener('input', function () {
                _paramValues[pid] = input.value;
                _onParamChanged(pid);
            });
        } else if (type === 'selector') {
            return _renderSelectorInput(param);
        } else if (type === 'template') {
            return _renderTemplatePicker(param);
        } else if (type === 'hidden') {
            return null;
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'preview-contextual-ai-tools__param-input';
            input.id = _paramControlId(pid);
            if (param.placeholder) input.placeholder = param.placeholder;
            input.value = _paramValues[pid] != null ? String(_paramValues[pid]) : '';
            input.addEventListener('input', function () {
                _paramValues[pid] = input.value;
                _onParamChanged(pid);
            });
        }
        return input;
    }

    function _renderSelectorInput(param) {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__selector-display';
        wrap.dataset.selectorParamId = param.id;
        _refreshSelectorDisplay(wrap, param.id);
        return wrap;
    }

    function _refreshSelectorDisplay(wrap, paramId) {
        var sel = _readSelectionForSelector();
        _paramValues[paramId] = sel;
        wrap.replaceChildren();
        if (!sel) {
            var empty = document.createElement('span');
            empty.className = 'preview-contextual-ai-tools__selector-empty';
            empty.textContent = _t('aiToolsSelectorEmpty');
            wrap.appendChild(empty);
            return;
        }
        var summary = document.createElement('code');
        summary.className = 'preview-contextual-ai-tools__selector-summary';
        var tagStr = sel.tag || '?';
        var classStr = (sel.classes || []).length > 0 ? '.' + sel.classes.join('.') : '';
        summary.textContent = '<' + tagStr + '>' + classStr;
        wrap.appendChild(summary);
        if (sel.struct) {
            var struct = document.createElement('span');
            struct.className = 'preview-contextual-ai-tools__selector-struct';
            struct.textContent = ' ' + _t('aiToolsSelectorIn') + ' ' + sel.struct;
            wrap.appendChild(struct);
        }
    }

    function _refreshAllSelectorParams() {
        if (!_runnerView || !_activeWorkflowSpec) return;
        var params = _activeWorkflowSpec.parameters || [];
        for (var i = 0; i < params.length; i++) {
            if (params[i].type !== 'selector') continue;
            var wrap = _runnerView.querySelector('[data-selector-param-id="' + params[i].id + '"]');
            if (wrap) _refreshSelectorDisplay(wrap, params[i].id);
        }
    }

    function _renderEmptyOptionsPlaceholder() {
        var ph = document.createElement('div');
        ph.className = 'preview-contextual-ai-tools__param-placeholder';
        ph.textContent = _t('aiToolsParamOptionsEmpty');
        return ph;
    }

    // ──────────────────────────── A picker of templates ─────────────────

    /** The template a picker holds, or null: the choice its parameter lists under the value picked. */
    function _chosenTemplate(param) {
        var list = param && Array.isArray(param.templates) ? param.templates : [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === _paramValues[param.id]) return list[i];
        }
        return null;
    }

    /** Each picker's template by its parameter's id, as a field's condition reads it (chosen.template.dark). */
    function _chosenTemplates() {
        var out = {};
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            if (params[i].type === 'template') out[params[i].id] = _chosenTemplate(params[i]);
        }
        return out;
    }

    /**
     * What a picked template sets in the other fields: a list its languages filter starts with all of them ticked, and
     * a tick box the template does not offer (its condition no longer holds) goes back to unticked, on screen too.
     */
    function _applyTemplateChoice(picker) {
        var template = _chosenTemplate(picker);
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            var of = p.optionsFrom;
            if (p.type === 'tag-select' && of && typeof of === 'object' && [].concat(of.filterFrom || []).indexOf(picker.id) !== -1) {
                _paramValues[p.id] = template && Array.isArray(template.languages) ? template.languages.slice() : [];
            }
            if (p.type === 'checkbox' && p.condition && _paramValues[p.id] && !_isParamVisible(p)) {
                _paramValues[p.id] = false;
                var box = _runnerView ? _runnerView.querySelector('[data-param-control="' + p.id + '"]') : null;
                if (box) box.checked = false;
            }
        }
    }

    /** The address of one of the panel's own assets. */
    function _assetUrl(path) {
        var cfg = window.PreviewConfig || {};
        return (cfg.adminUrl || '') + 'assets/' + path;
    }

    /**
     * The kinds a picker groups its templates under, in the order its parameter lists them (its `options`: a kind's id
     * and its name), each with the templates whose file states it. A template that states no kind, or one the parameter
     * does not list, comes last under the panel's own word. A kind no template states is left out.
     */
    function _templateKinds(param, list) {
        var listed = Array.isArray(param.options) ? param.options : [];
        var kinds = [];
        var byValue = {};
        for (var i = 0; i < listed.length; i++) {
            var o = listed[i];
            if (!o || typeof o.value !== 'string' || o.value === '' || byValue[o.value]) continue;
            byValue[o.value] = { value: o.value, label: o.label || o.value, templates: [] };
            kinds.push(byValue[o.value]);
        }
        if (kinds.length === 0) return [];
        var other = { value: '', label: _t('workflowsTemplateKindOther'), templates: [] };
        for (var t = 0; t < list.length; t++) {
            (byValue[list[t].kind] || other).templates.push(list[t]);
        }
        kinds = kinds.filter(function (k) { return k.templates.length > 0; });
        if (other.templates.length > 0) kinds.push(other);
        return kinds;
    }

    /**
     * A picker of templates: a group of radio cards the field's label names, one per template (its picture, or its
     * icon when it has none, then its name and its line). A template with a built preview has a Preview button.
     * When the templates are of several kinds, the cards come kind by kind, each kind under its name, and a row of
     * buttons above them shows one kind alone.
     */
    function _renderTemplatePicker(param) {
        var list = Array.isArray(param.templates) ? param.templates : [];
        if (list.length === 0) return _renderEmptyOptionsPlaceholder();
        var group = document.createElement('div');
        group.className = 'preview-contextual-ai-tools__template-picker';
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-labelledby', _paramControlId(param.id) + '-label');
        var kinds = _templateKinds(param, list);
        if (kinds.length < 2) {
            for (var i = 0; i < list.length; i++) {
                group.appendChild(_renderTemplateCard(param, list[i], group));
            }
            return group;
        }
        group.classList.add('preview-contextual-ai-tools__template-picker--kinds');
        for (var k = 0; k < kinds.length; k++) {
            group.appendChild(_renderTemplateKind(param, kinds[k], group));
        }
        var pick = document.createElement('div');
        pick.className = 'preview-contextual-ai-tools__template-pick';
        pick.appendChild(_renderTemplateKindButtons(param, kinds, list.length, group));
        pick.appendChild(group);
        _showTemplateKind(param, group);
        return pick;
    }

    /** One kind of a picker: its name, then its templates' cards. */
    function _renderTemplateKind(param, kind, group) {
        var section = document.createElement('div');
        section.className = 'preview-contextual-ai-tools__template-kind';
        section.dataset.kind = kind.value;
        var name = document.createElement('div');
        name.className = 'preview-contextual-ai-tools__template-kind-name';
        name.textContent = kind.label;
        section.appendChild(name);
        var cards = document.createElement('div');
        cards.className = 'preview-contextual-ai-tools__template-kind-cards';
        for (var i = 0; i < kind.templates.length; i++) {
            cards.appendChild(_renderTemplateCard(param, kind.templates[i], group));
        }
        section.appendChild(cards);
        return section;
    }

    /**
     * The buttons above a picker's cards: All, then one per kind, each with the number of its templates. The one
     * pressed is the kind shown; it lasts while the editor's page is open, and nothing of it is stored.
     */
    function _renderTemplateKindButtons(param, kinds, total, group) {
        var row = document.createElement('div');
        row.className = 'preview-contextual-ai-tools__template-kinds';
        row.setAttribute('role', 'group');
        row.setAttribute('aria-label', _t('workflowsTemplateKinds'));
        var add = function (value, label, count) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'preview-contextual-ai-tools__template-kind-btn';
            b.dataset.kind = value === null ? '*' : value;
            b.textContent = _t('workflowsTemplateKindCount', { count: QSPanelWords.number(count), name: label });
            b.addEventListener('click', function () {
                _templateKindShown[param.id] = value;
                _showTemplateKind(param, group);
            });
            row.appendChild(b);
        };
        add(null, _t('workflowsTemplateKindAll'), total);
        for (var i = 0; i < kinds.length; i++) add(kinds[i].value, kinds[i].label, kinds[i].templates.length);
        return row;
    }

    /**
     * Shows the cards of the kind a picker's buttons hold (all of them when none is pressed), and says so on the
     * buttons. The card picked is never hidden, whatever its kind; a kind with no card shown is hidden with its name.
     */
    function _showTemplateKind(param, group) {
        var shown = Object.prototype.hasOwnProperty.call(_templateKindShown, param.id) ? _templateKindShown[param.id] : null;
        var sections = group.querySelectorAll('.preview-contextual-ai-tools__template-kind');
        for (var s = 0; s < sections.length; s++) {
            var cards = sections[s].querySelectorAll('.preview-contextual-ai-tools__template-card');
            var any = false;
            for (var c = 0; c < cards.length; c++) {
                var show = shown === null || sections[s].dataset.kind === shown || cards[c].dataset.template === _paramValues[param.id];
                cards[c].hidden = !show;
                any = any || show;
            }
            sections[s].hidden = !any;
        }
        var row = group.parentNode ? group.parentNode.querySelector('.preview-contextual-ai-tools__template-kinds') : null;
        var buttons = row ? row.querySelectorAll('.preview-contextual-ai-tools__template-kind-btn') : [];
        for (var b = 0; b < buttons.length; b++) {
            buttons[b].setAttribute('aria-pressed', buttons[b].dataset.kind === (shown === null ? '*' : shown) ? 'true' : 'false');
        }
    }

    function _renderTemplateCard(param, template, group) {
        var card = document.createElement('div');
        card.className = 'preview-contextual-ai-tools__template-card';
        card.dataset.template = template.id;
        var picked = _paramValues[param.id] === template.id;
        card.classList.toggle('preview-contextual-ai-tools__template-card--chosen', picked);

        var choice = document.createElement('label');
        choice.className = 'preview-contextual-ai-tools__template-choice';
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.className = 'preview-contextual-ai-tools__template-radio';
        radio.name = _paramControlId(param.id);
        radio.value = template.id;
        radio.checked = picked;
        radio.addEventListener('change', function () {
            if (!radio.checked) return;
            _paramValues[param.id] = template.id;
            var cards = group.querySelectorAll('.preview-contextual-ai-tools__template-card');
            for (var c = 0; c < cards.length; c++) {
                cards[c].classList.toggle('preview-contextual-ai-tools__template-card--chosen', cards[c] === card);
            }
            // The card picked before may be of a kind that is not shown: it leaves with its kind.
            if (group.classList.contains('preview-contextual-ai-tools__template-picker--kinds')) _showTemplateKind(param, group);
            _onParamChanged(param.id);
        });
        choice.appendChild(radio);
        choice.appendChild(_renderTemplatePicture(template));
        var text = document.createElement('span');
        text.className = 'preview-contextual-ai-tools__template-text';
        var name = document.createElement('span');
        name.className = 'preview-contextual-ai-tools__template-name';
        name.textContent = template.title || template.id;
        text.appendChild(name);
        if (template.description) {
            var line = document.createElement('span');
            line.className = 'preview-contextual-ai-tools__template-line';
            line.textContent = template.description;
            text.appendChild(line);
        }
        choice.appendChild(text);
        card.appendChild(choice);
        if (template.preview) card.appendChild(_renderTemplatePreviewButton(param, template));
        return card;
    }

    /** A template's picture; its icon when it has none. Neither is read aloud: the card's name is its words. */
    function _renderTemplatePicture(template) {
        var box = document.createElement('span');
        box.className = 'preview-contextual-ai-tools__template-picture';
        if (template.picture) {
            var img = document.createElement('img');
            img.src = _assetUrl('images/templates/' + template.picture);
            img.alt = '';
            box.appendChild(img);
        } else {
            box.classList.add('preview-contextual-ai-tools__template-picture--icon');
            box.setAttribute('aria-hidden', 'true');
            box.textContent = template.icon || '📋';
        }
        return box;
    }

    function _renderTemplatePreviewButton(param, template) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preview-contextual-ai-tools__template-preview';
        btn.textContent = _t('workflowsTemplatePreview');
        btn.setAttribute('aria-label', _t('workflowsTemplatePreviewOf', { name: template.title || template.id }));
        btn.addEventListener('click', function () { _openTemplatePreview(param, template); });
        return btn;
    }

    /** The address of a page of a template's built preview, among the panel's assets. */
    function _templatePreviewUrl(template, theme, lang, page) {
        return _assetUrl('template-previews/' + encodeURIComponent(template.id) + '/' + encodeURIComponent(theme) + '/'
            + (lang ? encodeURIComponent(lang) + '/' : '') + encodeURIComponent(page) + '.html');
    }

    /**
     * A template's built preview, in the panel's dialog: the pages the engine built for it, in a frame that is given
     * no access to the panel (no same-origin; scripts only when the preview keeps one, the theme switch's). The
     * frame opens on its first theme, in the language the form holds for this template when it is the one picked (the
     * select its languages filter), else in the template's first.
     */
    function _openTemplatePreview(picker, template) {
        var utils = window.QuickSiteUtils;
        var preview = template.preview;
        if (!utils || typeof utils.openModal !== 'function' || !preview) return;
        var name = template.title || template.id;
        var lang = '';
        if (preview.languages.length > 0) {
            lang = preview.languages[0];
            var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
            for (var i = 0; i < params.length; i++) {
                var of = params[i].optionsFrom;
                if (params[i].type !== 'select' || !of || typeof of !== 'object' || [].concat(of.filterFrom || []).indexOf(picker.id) === -1) continue;
                if (_paramValues[picker.id] === template.id && preview.languages.indexOf(_paramValues[params[i].id]) !== -1) {
                    lang = _paramValues[params[i].id];
                }
                break;
            }
        }
        var page = preview.pages.indexOf('home') !== -1 ? 'home' : preview.pages[0];

        var modal = document.createElement('div');
        modal.className = 'admin-modal-dialog preview-contextual-ai-tools__template-dialog';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'ai-tools-template-preview-title');

        var head = document.createElement('div');
        head.className = 'preview-contextual-ai-tools__template-dialog-head';
        var title = document.createElement('h2');
        title.id = 'ai-tools-template-preview-title';
        title.className = 'preview-contextual-ai-tools__template-dialog-title';
        title.textContent = _t('workflowsTemplatePreviewTitle', { name: name });
        head.appendChild(title);

        var frame = document.createElement('iframe');
        frame.className = 'preview-contextual-ai-tools__template-frame';
        frame.setAttribute('sandbox', preview.scripts ? 'allow-scripts' : '');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.title = _t('workflowsTemplatePreviewFrame', { name: name });

        var themeButtons = [];
        var show = function (theme) {
            frame.src = _templatePreviewUrl(template, theme, lang, page);
            themeButtons.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.theme === theme ? 'true' : 'false'); });
        };
        if (preview.themes.length > 1) {
            var themes = document.createElement('div');
            themes.className = 'preview-contextual-ai-tools__template-themes';
            themes.setAttribute('role', 'group');
            themes.setAttribute('aria-label', _t('workflowsTemplateTheme'));
            preview.themes.forEach(function (theme) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'preview-contextual-ai-tools__template-theme';
                b.dataset.theme = theme;
                b.textContent = theme === 'dark' ? _t('workflowsTemplateThemeDark') : _t('workflowsTemplateThemeLight');
                b.addEventListener('click', function () { show(theme); });
                themeButtons.push(b);
                themes.appendChild(b);
            });
            head.appendChild(themes);
        }
        // What the dialog says when a link that leads out of the preview is pressed in the frame: a line of the panel's own
        // words. A preview page tells it so (the previews' tool switches such a link off and has the page say it was pressed).
        // The dialog hears only its own frame: the message's source is this frame's window, which no other frame, tab or
        // page is; its origin reads 'null', as a frame with no same-origin does; and its data is the one shape expected.
        // Nothing of the message is shown, kept or used: anything else changes nothing.
        var outside = document.createElement('p');
        outside.className = 'preview-contextual-ai-tools__template-dialog-outside';
        outside.setAttribute('role', 'status');
        var onMessage = function (e) {
            if (e.source !== frame.contentWindow || e.origin !== 'null' || !_isPreviewOutsideMessage(e.data)) return;
            outside.textContent = _t('workflowsTemplatePreviewOutside');
        };
        window.addEventListener('message', onMessage);
        // The line goes when the frame shows another page (a theme pressed, a page of the preview opened).
        frame.addEventListener('load', function () { outside.textContent = ''; });

        var shown = null;
        var shut = function () {
            window.removeEventListener('message', onMessage);
            if (shown) shown.close();
        };
        var close = window.QSControls.close(null, shut, { className: 'preview-contextual-ai-tools__template-close' });
        head.appendChild(close);
        modal.appendChild(head);

        var notes = document.createElement('div');
        notes.className = 'preview-contextual-ai-tools__template-dialog-notes';
        var note = document.createElement('p');
        note.className = 'preview-contextual-ai-tools__template-dialog-note';
        note.textContent = _t('workflowsTemplatePreviewNote');
        notes.appendChild(note);
        notes.appendChild(outside);
        modal.appendChild(notes);
        modal.appendChild(frame);

        // The frame's page takes the keyboard while the focus is in it; past its last link the focus comes back here.
        var back = document.createElement('span');
        back.tabIndex = 0;
        back.className = 'preview-contextual-ai-tools__template-dialog-end';
        back.addEventListener('focus', function () { close.focus(); });
        modal.appendChild(back);

        show(preview.themes[0]);
        shown = utils.openModal(modal, {
            focusable: function () { return themeButtons.concat([close, frame]); },
            first: close,
            dismiss: shut
        });
    }

    /**
     * Whether a message is the one a template's preview page sends when a link that leads out of the preview is pressed
     * (secure/tools/template-previews.php writes that page's script): an object with the one key and the one value, and
     * nothing more.
     */
    function _isPreviewOutsideMessage(data) {
        if (!data || typeof data !== 'object' || Array.isArray(data)) return false;
        var keys = Object.keys(data);
        return keys.length === 1 && keys[0] === 'qsTemplatePreview' && data.qsTemplatePreview === 'outside-link';
    }

    function _renderTagSelect(param, options) {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__tag-select';
        // A list of checkboxes is a group its field's label names.
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-labelledby', _paramControlId(param.id) + '-label');

        var current = _paramValues[param.id];
        if (!Array.isArray(current)) {
            current = current != null && current !== '' ? [current] : [];
        }
        _paramValues[param.id] = current;

        for (var i = 0; i < options.length; i++) {
            wrap.appendChild(_renderTagSelectOption(param.id, options[i], current));
        }
        return param.searchable ? _renderTagSearch(param, wrap) : wrap;
    }

    /**
     * A searchable tag-select: a search field above its list. A row matching neither the name
     * nor the value typed is hidden, a ticked row never is: a search never hides or drops a pick.
     */
    function _renderTagSearch(param, list) {
        var holder = document.createElement('div');
        holder.className = 'preview-contextual-ai-tools__tag-search';
        var field = document.createElement('input');
        field.type = 'search';
        field.className = 'preview-contextual-ai-tools__param-input';
        field.id = _paramControlId(param.id);
        field.placeholder = QSPanelWords.t('common.search');
        field.autocomplete = 'off';
        var none = document.createElement('p');
        none.className = 'preview-contextual-ai-tools__tag-search-none';
        none.hidden = true;
        field.addEventListener('input', function () {
            _filterTagSelect(list, field.value, none);
        });
        holder.appendChild(field);
        holder.appendChild(list);
        holder.appendChild(none);
        return holder;
    }

    function _filterTagSelect(list, query, none) {
        var q = query.trim().toLowerCase();
        var matched = 0;
        var rows = list.children;
        for (var i = 0; i < rows.length; i++) {
            var box = rows[i].querySelector('input');
            var hit = q === '' || rows[i].textContent.toLowerCase().indexOf(q) !== -1
                || (!!box && box.value.toLowerCase().indexOf(q) !== -1);
            if (hit) matched++;
            rows[i].hidden = !hit && !(box && box.checked);
        }
        none.textContent = QSPanelWords.t('common.noMatchesFor', { query: query.trim() });
        none.hidden = q === '' || matched > 0;
    }

    function _renderTagSelectOption(pid, opt, current) {
        var label = document.createElement('label');
        label.className = 'preview-contextual-ai-tools__tag-select-option';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = String(opt.value);
        cb.checked = current.indexOf(opt.value) !== -1;
        cb.addEventListener('change', function () {
            var arr = _paramValues[pid] || [];
            if (cb.checked) {
                if (arr.indexOf(opt.value) === -1) arr.push(opt.value);
            } else {
                var idx = arr.indexOf(opt.value);
                if (idx !== -1) arr.splice(idx, 1);
            }
            _paramValues[pid] = arr;
            _onParamChanged(pid);
        });
        var span = document.createElement('span');
        span.textContent = opt.label || opt.value;
        label.appendChild(cb);
        label.appendChild(span);
        return label;
    }

    function _renderSectionGeneralPrompt(hasBYOK) {
        var s = _renderCollapsibleSection({
            title: _t('aiToolsSectionGeneralPrompt'),
            defaultOpen: !hasBYOK,
            dataKey: 'general-prompt'
        });

        _generalPromptTextarea = document.createElement('textarea');
        _generalPromptTextarea.className = 'preview-contextual-ai-tools__general-prompt';
        _generalPromptTextarea.placeholder = _t('aiToolsGeneralPromptPlaceholder');
        _generalPromptTextarea.value = _generatedPrompt;
        _generalPromptTextarea.readOnly = true;
        s.body.appendChild(_generalPromptTextarea);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__general-prompt-actions';
        _copyBtn = document.createElement('button');
        _copyBtn.type = 'button';
        _copyBtn.className = 'preview-contextual-ai-tools__small-btn';
        _copyBtn.textContent = _t('aiToolsCopyBtn');
        _copyBtn.addEventListener('click', _onCopyPromptClick);
        actions.appendChild(_copyBtn);
        s.body.appendChild(actions);

        // Contextual hint — only shown when no BYOK (user must copy manually).
        if (!hasBYOK) {
            var hint = document.createElement('p');
            hint.className = 'preview-contextual-ai-tools__hint';
            hint.textContent = _t('aiToolsCopyHint');
            s.body.appendChild(hint);
        }
        // The starred pictures to attach with this prompt (_renderAttach()).
        _attachEl = document.createElement('div');
        _attachEl.className = 'preview-contextual-ai-tools__attach';
        _attachEl.hidden = true;
        s.body.appendChild(_attachEl);
        return s.section;
    }

    function _renderSectionAiResponseTextarea(hasBYOK) {
        var s = _renderCollapsibleSection({
            title: _t('aiToolsSectionAiResponse'),
            defaultOpen: true,
            dataKey: 'ai-response-input'
        });

        _aiResponseTextarea = document.createElement('textarea');
        _aiResponseTextarea.className = 'preview-contextual-ai-tools__ai-response';
        _aiResponseTextarea.placeholder = _t('aiToolsAiResponsePlaceholder');
        _aiResponseTextarea.value = _aiResponseText;
        _aiResponseTextarea.addEventListener('input', _onAiResponseInput);
        s.body.appendChild(_aiResponseTextarea);

        _aiResponseStatusEl = document.createElement('div');
        _aiResponseStatusEl.className = 'preview-contextual-ai-tools__ai-response-status';
        _updateAiResponseStatusUI();
        s.body.appendChild(_aiResponseStatusEl);

        // What the AI phase this exchange serves has cost so far (_renderCost()).
        _phaseCostEl = document.createElement('div');
        _phaseCostEl.className = 'preview-contextual-ai-tools__phase-cost';
        _phaseCostEl.hidden = true;
        s.body.appendChild(_phaseCostEl);

        // 1.5s auto-execute grace hint — shows under the status line when a
        // timer is scheduled, hides when fired or cancelled.
        _autoExecHintEl = document.createElement('p');
        _autoExecHintEl.className = 'preview-contextual-ai-tools__auto-exec-hint';
        _autoExecHintEl.style.display = 'none';
        s.body.appendChild(_autoExecHintEl);

        // Contextual hint — only shown when no BYOK (user must paste manually).
        if (!hasBYOK) {
            var hint = document.createElement('p');
            hint.className = 'preview-contextual-ai-tools__hint';
            hint.textContent = _t('aiToolsPasteHint');
            s.body.appendChild(hint);
        }
        return s.section;
    }

    function _renderSectionBatch(spec) {
        // Title is just "Batch" — the raw spec.steps count was misleading
        // (it doesn't reflect forEach expansion). The real command count
        // lives in the state header and updates as the batch resolves.
        var s = _renderCollapsibleSection({
            title: _t('aiToolsBatchTitle'),
            defaultOpen: false,
            stateHeader: _t('aiToolsStateIdle'),
            dataKey: 'batch'
        });
        _stepsBody = s.body;
        _stepsHeaderState = s.stateEl;
        _stepsSetOpen = s.setOpen;
        return s.section;
    }

    // ──────────────────────────── Toggles ───────────────────────────────

    function _renderToggle(storageKeyName, label) {
        var wrap = document.createElement('label');
        wrap.className = 'preview-contextual-ai-tools__toggle';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = _readToggle(storageKeyName);
        input.addEventListener('change', function () {
            _writeToggle(storageKeyName, input.checked);
            // Auto-execute affects both the primary button label
            // ("Run with AI" vs "Send to AI") and Execute button visibility.
            if (storageKeyName === 'aiAutoExecute') {
                _updateActionStates();
            }
        });
        var span = document.createElement('span');
        span.textContent = label;
        wrap.appendChild(input);
        wrap.appendChild(span);
        return wrap;
    }

    /** A switch's stored state: on only once switched on ("Run without stopping" starts off). */
    function _readToggle(storageKeyName) {
        var key = (window.QuickSiteStorageKeys || {})[storageKeyName];
        if (!key) return false;
        try {
            return localStorage.getItem(key) === 'true';
        } catch (_e) {
            return false;
        }
    }

    function _writeToggle(storageKeyName, value) {
        var key = (window.QuickSiteStorageKeys || {})[storageKeyName];
        if (!key) return;
        try { localStorage.setItem(key, value ? 'true' : 'false'); } catch (_e) { /* storage unavailable: not kept */ }
    }

    /** The primary action: a run, its AI phases sent with the connection's key when there is one. */
    function _onPrimaryActionClick() {
        if (_executing || Object.keys(_paramErrors).length > 0) return;
        // A pending grace timer must not fire on top of the run this click starts.
        _cancelAutoExecute();
        _startRun({ send: !!_getDefaultConnection() });
    }

    /** "Generate for copy": a run whose AI phases show and copy their prompt instead of sending it. */
    function _onSecondaryActionClick() {
        if (_executing || Object.keys(_paramErrors).length > 0) return;
        if (!_chainHasAi()) return;
        _cancelAutoExecute();
        _startRun({ send: false });
    }

    // ──────────────────────────── Auto-focus + auto-copy helpers ────────

    function _focusGeneralPrompt() {
        if (!_runnerView) return;
        var section = _runnerView.querySelector('[data-section="general-prompt"]');
        if (!section) return;
        var body = section.querySelector('.preview-contextual-ai-tools__runner-section-body');
        var header = section.querySelector('.preview-contextual-ai-tools__runner-section-header');
        if (body && body.style.display === 'none' && header) header.click();
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (_generalPromptTextarea) _generalPromptTextarea.focus();
    }

    /** The prompt copied (or the text given), and a toast saying so (okMessage, else "Prompt copied"). */
    function _autoCopyPromptWithToast(given, okMessage) {
        var text = given || _generatedPrompt || (_generalPromptTextarea && _generalPromptTextarea.value) || '';
        if (!text) return;
        var toaster = (window.QuickSiteAdmin && typeof window.QuickSiteAdmin.showToast === 'function')
            ? window.QuickSiteAdmin.showToast.bind(window.QuickSiteAdmin)
            : null;
        var ok = function () {
            if (toaster) toaster(okMessage || _t('aiToolsCopiedToast'), 'success');
        };
        var fail = function () {
            if (toaster) toaster(_t('aiToolsCopyFailedToast'), 'warning');
        };
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok).catch(fail);
            } else if (_generalPromptTextarea) {
                _generalPromptTextarea.select();
                try { document.execCommand('copy'); ok(); } catch (_e) { fail(); }
            }
        } catch (_e) {
            fail();
        }
    }

    /**
     * The AI answer's Execute: a run waiting for an answer takes these commands; with no run under way,
     * a run whose first phase is an AI one starts on this answer (it was pasted before anything ran).
     */
    function _onExecuteClick() {
        if (Object.keys(_paramErrors).length > 0) return;
        if (_aiResponseStatus.type !== 'ok' || !_currentCommands || _currentCommands.length === 0) return;
        _cancelAutoExecute();
        if (_answerWaiter) {
            // An answer put together from several was counted part by part.
            if (_run && _answerParts <= 1) _tallyAnswer(_run, _run.index, _aiResponseText.length);
            var waiter = _answerWaiter;
            _answerWaiter = null;
            waiter.resolve(_currentCommands.slice());
            return;
        }
        if (_executing || !_firstPhaseIsAi()) return;
        _startRun({ send: false, answer: _currentCommands.slice(), answerChars: _aiResponseText.length });
    }

    // ──────────────────────────── Send to AI ────────────────────────────

    /**
     * Send the generated prompt with the connection's key; the answer streams into AI response.
     * continuation ({ partial, request }): the request for the rest of a cut answer, sent after the
     * prompt and the cut answer, which is given as the model's own turn.
     */
    async function _sendToAi(connection, continuation) {
        if (!connection) return;
        if (!window.QSAiCall || !window.QSProviderCatalog) {
            _aiResponseStatus = { type: 'error', message: _t('workflowsAiCallerNotLoaded'), plain: true, cause: 'refused' };
            _updateAiResponseStatusUI();
            _updateActionStates();
            return;
        }
        if (!_generatedPrompt) return;

        var model = connection.defaultModel
            || (connection.models && connection.models[0])
            || (connection.model);
        if (!model) {
            _aiResponseStatus = { type: 'error', message: _t('workflowsConnectionNoModel'), plain: true, cause: 'refused' };
            _updateAiResponseStatusUI();
            _updateActionStates();
            return;
        }

        if (_aiResponseStatusEl) {
            _aiResponseStatusEl.classList.remove(
                'preview-contextual-ai-tools__ai-response-status--ok',
                'preview-contextual-ai-tools__ai-response-status--error'
            );
        }
        if (_aiResponseTextarea) {
            _aiResponseTextarea.value = '';
            _aiResponseText = '';
        }
        _cancelAutoExecute();
        _aiSending = true;
        _morphPrimary(_t('aiToolsSendingToAi', { model: model }));
        _updateActionStates();

        // Elapsed-time ticker so the user has a "still working" cue during
        // the latency before the first chunk arrives (some providers take a
        // noticeable beat to start streaming).
        var startedAt = (window.performance && performance.now)
            ? performance.now() : Date.now();
        var lastChars = 0;
        function refreshSendStatus() {
            if (!_aiResponseStatusEl) return;
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            var sec = QSPanelWords.number((now - startedAt) / 1000, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            _aiResponseStatusEl.textContent = lastChars > 0
                ? QSPanelWords.count('preview.workflowsStreamingWithElapsed', lastChars, { sec: sec, model: model })
                : _t('aiToolsSendingWithElapsed', { sec: sec, model: model });
        }
        refreshSendStatus();
        var statusTickerId = setInterval(refreshSendStatus, 300);

        var messages = continuation
            ? [{ role: 'user', content: _generatedPrompt }, { role: 'assistant', content: continuation.partial },
                { role: 'user', content: continuation.request }]
            : [{ role: 'user', content: _generatedPrompt }];
        // The call's cost: the characters of every message sent, whatever comes back.
        var call = { via: 'key', provider: String(connection.providerType || '') || null, model: String(model),
            sentChars: messages.reduce(function (n, m) { return n + m.content.length; }, 0),
            receivedChars: 0, sentTokens: null, receivedTokens: null, finish: null, failed: false };

        try {
            var result = await window.QSAiCall.call({
                connection: connection,
                model: model,
                messages: messages,
                // The answer may be as long as the connection's output limit (its max_tokens).
                options: { temperature: 0.7 },
                onChunk: function (_deltaText, ctx) {
                    // Stream chunks straight into the textarea so the user
                    // sees progress. Programmatic value sets fire no `input`
                    // event, so _onAiResponseInput does not trip.
                    var liveContent = (ctx && ctx.content) || '';
                    lastChars = liveContent.length;
                    if (_aiResponseTextarea) {
                        _aiResponseTextarea.value = liveContent;
                        _aiResponseTextarea.scrollTop = _aiResponseTextarea.scrollHeight;
                    }
                    refreshSendStatus();
                }
            });
            var usage = result && result.usage;
            call.receivedChars = String((result && result.content) || '').length;
            call.sentTokens = usage && typeof usage.input_tokens === 'number' ? usage.input_tokens : null;
            call.receivedTokens = usage && typeof usage.output_tokens === 'number' ? usage.output_tokens : null;
            call.finish = (result && result.finish) || null;
            _tallyCall(call);
            var content = String((result && result.content) || '').trim();
            // Strip Markdown code fences the AI commonly wraps JSON in.
            if (content.indexOf('```') === 0) {
                content = content.replace(/^```(?:json)?\s*\n?/, '').replace(/\n?```\s*$/, '').trim();
            }
            _aiResponseText = content;
            if (_aiResponseTextarea) _aiResponseTextarea.value = content;
            // An answer the provider cut at the limit or stopped is never run: it is said so, and the
            // run waits for an edit of it or another answer.
            var finish = result && result.finish;
            if (finish === 'length' || finish === 'other') {
                _aiResponseStatus = { type: 'error', plain: true, cause: finish === 'length' ? 'cut' : 'stopped', message: finish === 'length'
                    ? _t('aiToolsAnswerCut', { n: QSPanelWords.number(window.QSProviderCatalog.maxOutputTokens(connection)) })
                    : _t('aiToolsAnswerStopped', { reason: String(result.finishReason) }) };
                _currentCommands = [];
                _answerPhase = null;
                if (_run) _showPhaseInBatch(_run.index); else _renderBatch();
                _updateAiResponseStatusUI();
                _updateActionStates();
                return;
            }
            // The same path as a paste: the answer checked, classified, its Execute or grace timer.
            _onAnswerChanged();
        } catch (err) {
            var msg = err && err.message ? err.message : String(err);
            // An answer that had begun to arrive broke off; else the send itself failed.
            var partial = err && typeof err.partial === 'string' ? err.partial : '';
            call.receivedChars = partial.length;
            call.failed = true;
            _tallyCall(call);
            _aiResponseText = partial;
            _aiResponseStatus = { type: 'error', plain: true, cause: partial ? 'brokeOff' : 'refused', message: partial
                ? QSPanelWords.count('preview.workflowsAnswerBrokeOff', partial.length, { error: msg })
                : _t('aiToolsSendFailed', { error: msg }) };
            _currentCommands = [];
            _answerPhase = null;
            if (_run) _showPhaseInBatch(_run.index); else _renderBatch();
            _updateAiResponseStatusUI();
            _updateActionStates();
        } finally {
            clearInterval(statusTickerId);
            _aiSending = false;
            if (_run) _morphPrimary(_t('aiToolsRunActive'));
            _updateActionStates();
        }
    }

    // ──────────────────────────── A run's cost ──────────────────────────
    //
    // What a run's AI phases cost, shown and never stored. With the connection's key, each call: the
    // characters of the messages sent and of the answer received, and the tokens the provider reports
    // (none is guessed: a provider that sends no counts is said to send none). Pasted, each exchange:
    // the characters of the prompt shown to copy (a Continue's request is one more) and of the answer
    // taken (Execute, Continue, or a continuation's reply joined) — characters, never turned into
    // tokens. The tally is the last run's of each workflow, in memory; _costRecord() builds the one
    // record the display reads.

    function _newCostTally(workflowId) {
        return { workflowId: workflowId, phases: {}, order: [] };
    }

    /** The tally's entry for phase k of the run, made at its first call. */
    function _costPhase(run, k) {
        var tally = _lastCost[run.workflowId];
        if (!tally || !run.plan[k]) return null;
        if (!tally.phases[k]) {
            var p = run.plan[k];
            tally.phases[k] = { index: k, key: p.key || '', workflowId: p.workflowId, title: p.title || '', calls: [] };
            tally.order.push(k);
        }
        return tally.phases[k];
    }

    /** One call with the connection's key, in the phase the run is at. */
    function _tallyCall(call) {
        if (!_run) return;
        var phase = _costPhase(_run, _run.index);
        if (!phase) return;
        phase.calls.push(call);
        _renderCost();
    }

    /** A prompt shown to copy (a pasted exchange starts), in phase k. */
    function _tallyPrompt(run, k, chars) {
        var phase = _costPhase(run, k);
        if (!phase) return;
        phase.calls.push({ via: 'paste', provider: null, model: null, sentChars: chars, receivedChars: null,
            sentTokens: null, receivedTokens: null, finish: null, failed: false });
        _renderCost();
    }

    /**
     * A pasted answer taken in phase k: the characters of the exchange its prompt opened, or an exchange
     * of its own when no prompt is waiting (an answer pasted before the run). A phase whose prompt was
     * sent with the key counts what its calls received, so an answer pasted there adds nothing.
     */
    function _tallyAnswer(run, k, chars) {
        var phase = _costPhase(run, k);
        if (!phase) return;
        if (phase.calls.some(function (c) { return c.via === 'key'; })) return;
        var open = null;
        for (var i = phase.calls.length - 1; i >= 0 && !open; i--) {
            if (phase.calls[i].receivedChars === null) open = phase.calls[i];
        }
        if (open) open.receivedChars = chars || 0;
        else phase.calls.push({ via: 'paste', provider: null, model: null, sentChars: null, receivedChars: chars || 0,
            sentTokens: null, receivedTokens: null, finish: null, failed: false });
        _renderCost();
    }

    /**
     * The run's cost record, from its tally: what the display reads, and what a statistics engine would
     * store unchanged — ids, numbers and the provider's own names, no word of the panel (a phase's title
     * is there for the display only; its identity is its workflow and its key). Per AI phase: how its
     * prompt went ('key' or 'paste'), its calls (each its provider and model, null pasted; the
     * characters sent and received; the tokens reported, null when none were; how its answer ended;
     * whether the call failed), their sums, and how its last answer ended. The run's totals are kept by
     * how the prompts went, never added across.
     */
    function _costRecord(tally) {
        if (!tally || tally.order.length === 0) return null;
        var phases = tally.order.map(function (k) {
            var p = tally.phases[k];
            var calls = p.calls.map(function (c) { return Object.assign({}, c); });
            var unit = calls.length > 0 && calls[0].via === 'key' ? 'key' : 'paste';
            return Object.assign({ index: p.index, key: p.key, workflowId: p.workflowId, title: p.title, unit: unit,
                calls: calls, finish: calls.length > 0 ? calls[calls.length - 1].finish : null }, _costSums(calls));
        });
        var totals = { key: null, paste: null };
        ['key', 'paste'].forEach(function (unit) {
            var calls = [];
            phases.forEach(function (p) { if (p.unit === unit) calls = calls.concat(p.calls); });
            if (calls.length > 0) totals[unit] = _costSums(calls);
        });
        return { workflowId: tally.workflowId, phases: phases, totals: totals };
    }

    /**
     * Calls summed: how many (a pasted exchange counts once its prompt was shown), how many failed (a
     * send refused, an answer broken off), the characters sent and received, and the tokens of the calls
     * the provider gave both counts for (null when none did).
     */
    function _costSums(calls) {
        var sums = { callCount: 0, failedCalls: 0, sentChars: 0, receivedChars: 0, countedCalls: 0, sentTokens: null, receivedTokens: null };
        calls.forEach(function (c) {
            if (c.sentChars !== null) sums.callCount++;
            if (c.failed) sums.failedCalls++;
            sums.sentChars += c.sentChars || 0;
            sums.receivedChars += c.receivedChars || 0;
            if (typeof c.sentTokens === 'number' && typeof c.receivedTokens === 'number') {
                sums.countedCalls++;
                sums.sentTokens = (sums.sentTokens || 0) + c.sentTokens;
                sums.receivedTokens = (sums.receivedTokens || 0) + c.receivedTokens;
            }
        });
        return sums;
    }

    /** A count as the panel's language writes it. */
    function _costNumber(n) {
        return QSPanelWords.number(Number(n || 0));
    }

    /**
     * One cost's lines: with the key its tokens, then its characters; pasted its characters. A call that
     * failed is said to have failed, never to have answered without counts: the calls are followed by
     * what they hold besides counted ones ("4 (1 without counts, 1 failed)"), a cost of failed calls
     * alone says so, and one whose answered calls sent no counts says that.
     */
    function _costLines(sums, unit) {
        var lines = [];
        if (unit === 'key') {
            var failed = sums.failedCalls;
            var missing = sums.callCount - sums.countedCalls - failed;
            var parts = [];
            if (missing > 0 && sums.countedCalls > 0) {
                parts.push(_t('aiToolsCostWithoutCounts', { n: _costNumber(missing) }));
            }
            if (failed > 0 && missing + sums.countedCalls > 0) {
                parts.push(_t('aiToolsCostFailed', { n: _costNumber(failed) }));
            }
            var calls = _costNumber(sums.callCount) + (parts.length > 0 ? ' (' + parts.join(', ') + ')' : '');
            if (sums.countedCalls > 0) {
                lines.push(_t('aiToolsCostTokens', {
                    sent: _costNumber(sums.sentTokens), received: _costNumber(sums.receivedTokens), calls: calls
                }));
            } else if (missing > 0) {
                lines.push(_t('aiToolsCostTokensNone', { calls: calls }));
            } else {
                lines.push(_t('aiToolsCostTokensFailed', { calls: calls }));
            }
            lines.push(_t('aiToolsCostCharsKey', { sent: _costNumber(sums.sentChars), received: _costNumber(sums.receivedChars) }));
        } else {
            lines.push(_t('aiToolsCostCharsPaste', {
                sent: _costNumber(sums.sentChars), received: _costNumber(sums.receivedChars), calls: _costNumber(sums.callCount)
            }));
        }
        return lines;
    }

    /** A phase's provider and models, as its calls name them ("anthropic · model-a, model-b"), or ''. */
    function _costSource(phase) {
        var providers = [];
        var models = [];
        phase.calls.forEach(function (c) {
            if (c.provider && providers.indexOf(c.provider) === -1) providers.push(c.provider);
            if (c.model && models.indexOf(c.model) === -1) models.push(c.model);
        });
        return providers.concat(models.length ? [models.join(', ')] : []).join(' · ');
    }

    /** How a phase's last answer ended, when not as asked ('' otherwise). */
    function _costFinish(phase) {
        if (phase.finish === 'length') return _t('aiToolsCostCut');
        if (phase.finish === 'other') return _t('aiToolsCostStopped');
        return '';
    }

    function _renderCostLine(text, className) {
        var line = document.createElement('p');
        line.className = 'preview-contextual-ai-tools__' + (className || 'cost-line');
        line.textContent = text;
        return line;
    }

    /** A phase's cost: its lines, where its calls came from, and how its answer ended. */
    function _renderCostPhase(phase, named) {
        var item = document.createElement('div');
        if (named) item.appendChild(_renderCostLine(phase.title, 'cost-phase-name'));
        _costLines(phase, phase.unit).forEach(function (text) { item.appendChild(_renderCostLine(text)); });
        var source = _costSource(phase);
        var finish = _costFinish(phase);
        if (source || finish) item.appendChild(_renderCostLine([source, finish].filter(Boolean).join(' · '), 'cost-note'));
        return item;
    }

    /**
     * The cost shown, from the record alone: the AI exchange's for the AI phase it serves, and the Run
     * zone's block — the run's total, then each AI phase when there are several. Both stay after the
     * run, until the workflow runs again.
     */
    function _renderCost() {
        var record = _activeWorkflowSummary ? _costRecord(_lastCost[_activeWorkflowSummary.id]) : null;
        if (_phaseCostEl) {
            _phaseCostEl.replaceChildren();
            var served = null;
            (record ? record.phases : []).forEach(function (p) { if (p.index === _exchangePhase) served = p; });
            _phaseCostEl.hidden = !served;
            if (served) _phaseCostEl.appendChild(_renderCostPhase(served, false));
        }
        if (!_costEl) return;
        _costEl.replaceChildren();
        _costEl.hidden = !record;
        if (!record) return;
        _costEl.appendChild(_renderCostLine(_t('aiToolsCostTitle'), 'cost-title'));
        ['key', 'paste'].forEach(function (unit) {
            if (!record.totals[unit]) return;
            _costLines(record.totals[unit], unit).forEach(function (text) { _costEl.appendChild(_renderCostLine(text)); });
        });
        if (record.phases.length > 1) {
            var list = document.createElement('div');
            list.className = 'preview-contextual-ai-tools__cost-phases';
            record.phases.forEach(function (p) { list.appendChild(_renderCostPhase(p, true)); });
            _costEl.appendChild(list);
        } else {
            var note = [_costSource(record.phases[0]), _costFinish(record.phases[0])].filter(Boolean).join(' · ');
            if (note) _costEl.appendChild(_renderCostLine(note, 'cost-note'));
        }
    }

    // ──────────────────────────── The run, phase by phase ───────────────
    //
    // A run goes through the workflow's phases in order — its pre-workflows, the workflow, its
    // post-workflows: the server's plan (workflow-generate-steps). Each phase is resolved again when
    // the run reaches it, so it reads what the earlier ones changed. With "Run without stopping" off,
    // the run pauses before each phase but the first for a review (Continue / Stop); a review also
    // stands before a first phase that deletes or changes content. What a phase deletes or changes
    // (the server's 'effects') is confirmed before it runs: by its review's Continue with the switch
    // off, by one danger dialog at the start with it on — after which only what that dialog did not
    // list asks again. An AI phase builds its prompt from the project as it then is, sends it with the
    // connection's key or shows it to copy, and waits for an answer whatever the switch; with the
    // switch off its Execute confirms what the answer does. Stop, a failing step marked abortOnFail
    // and the write rate end the run and keep the rest for later (the kept banner at the top).

    /** Whether "Run without stopping" is on. */
    function _autoContinue() {
        return _readToggle('aiAutoExecute');
    }

    /**
     * A run of the open workflow. opts.send: an AI phase's prompt is sent with the connection's key
     * (else shown and copied); opts.answer: the commands of an answer pasted before the run (its first
     * phase an AI one); opts.resume: kept steps run again (_resumeKept()).
     */
    async function _startRun(opts) {
        opts = opts || {};
        if (_executing || !_activeWorkflowSummary) return;
        var toaster = _toaster();
        // Kept steps come first: a new run would plan over a page they were planned for.
        if (!opts.resume && _readKept()) {
            toaster(_t('aiToolsKeptBlocking'), 'warning');
            _renderKept();
            return;
        }
        var run = {
            workflowId: _activeWorkflowSummary.id,
            label: _currentRunLabel(),
            params: JSON.parse(JSON.stringify(_paramValues)),
            send: !!opts.send,
            plan: [],
            index: 0,
            first: opts.resume ? opts.resume.phase : 0,
            confirmed: {},
            started: {},
            activeRequest: null,
            states: [],
            ok: 0,
            fail: 0,
            ran: false,
            last: null,
            // Pictures by link: each link a step downloaded, by the stored path uploadAsset answered (a resumed
            // run keeps its own); every link an uploadAsset step names; the outside picture addresses placed.
            swaps: opts.resume && opts.resume.swaps && typeof opts.resume.swaps === 'object' ? Object.assign({}, opts.resume.swaps) : {},
            links: [],
            outside: []
        };
        _run = run;
        _renderOutside([]);
        _renderAttach(null);
        _lastCost[run.workflowId] = _newCostTally(run.workflowId);
        _exchangePhase = null;
        _renderCost();
        _executing = true;
        _setRunLocked(true);
        _updateActionStates();
        _morphPrimary(_t('aiToolsRunActive'));
        try {
            run.plan = await _fetchPlan(run.params);
            run.index = run.first;
            // A kept rest resumes only where its phase still is: a fresh plan with another phase there
            // (or none) is the plan changed. A rest kept without its phase's key resumes as it was.
            if (opts.resume && typeof opts.resume.phaseKey === 'string'
                    && (!run.plan[run.first] || run.plan[run.first].key !== opts.resume.phaseKey)) {
                throw _planChangedError();
            }
            // Kept steps take the place of their phase's steps: the rest of the phase they stopped in.
            if (opts.resume && opts.resume.steps && run.plan[run.first]) {
                run.plan[run.first] = Object.assign({}, run.plan[run.first],
                    { steps: opts.resume.steps, effects: opts.resume.effects || [], kept: true });
            }
            _plan = run.plan.slice();
            _renderRunLine();
            _renderBatch();
            if (run.first >= run.plan.length) return;
            if (!_rateAllowsStart(run)) return;
            if (_autoContinue() && !(await _confirmRunStart(run))) return;
            for (var k = run.first; k < run.plan.length; k++) {
                run.index = k;
                var outcome = await _runPhase(run, k, k === run.first && opts.answer
                    ? { answer: opts.answer, answerChars: opts.answerChars || 0 } : {});
                if (outcome.stop) {
                    run.states[k] = 'stopped';
                    _renderRunLine();
                    _stopRun(run, k, outcome);
                    return;
                }
                run.states[k] = 'done';
                _renderRunLine();
            }
            if (_stepsHeaderState) {
                _stepsHeaderState.textContent = run.fail === 0
                    ? _t('aiToolsExecutionDone', { ok: QSPanelWords.number(run.ok) })
                    : _t('aiToolsExecutionDoneWithErrors', {
                        ok: QSPanelWords.number(run.ok), total: QSPanelWords.number(run.ok + run.fail), fail: QSPanelWords.number(run.fail)
                    });
            }
            // A run that had no step to run says so: the project was already as asked.
            if (!run.ran) toaster(_t('aiToolsNoChanges'), 'success');
        } catch (err) {
            if (err && err.planChanged) {
                // What ran stays done; nothing is kept: the rest was planned against a plan that no longer holds.
                if (run.plan[run.index]) run.states[run.index] = 'stopped';
                _renderRunLine();
                if (_stepsHeaderState) _stepsHeaderState.textContent = err.message;
                toaster(err.message, 'warning');
            } else {
                console.error('[PreviewAiTools] run failed:', err);
                var failMsg = _t('aiToolsRunFailed', { error: (err && err.message) || String(err) });
                if (_stepsHeaderState) _stepsHeaderState.textContent = failMsg;
                toaster(failMsg, 'error');
            }
        } finally {
            if (_answerWaiter) _answerWaiter = null;
            // The run's summary: every picture its steps placed from outside the site, whatever the reason.
            _renderOutside(run.outside);
            _run = null;
            _continuation = null;
            _answerParts = 0;
            _executing = false;
            _hideReview();
            _setRunLocked(false);
            _renderYourPromptFields();
            _updateActionStates();
            _renderKept();
        }
    }

    /**
     * One phase of the run, resolved now — the plan's own for the first phase it runs, since nothing
     * has run since the plan was made — then run.
     */
    async function _runPhase(run, k, opts) {
        var phase = k === run.first ? run.plan[k] : await _fetchPhase(run.params, k, { key: run.plan[k].key });
        run.plan[k] = phase;
        _plan[k] = phase;
        run.states[k] = 'current';
        _renderRunLine();
        _showPhaseInBatch(k);
        if (!phase.ai) return _runStepsPhase(run, k, phase);
        var outcome = await _runAiPhase(run, k, phase, opts);
        _releaseRequest(run);
        return outcome;
    }

    /** An AI phase is over: a request a later phase still takes opens again for the review before it. */
    function _releaseRequest(run) {
        var key = run.activeRequest;
        run.activeRequest = null;
        if (!key || run.started[key] || !_runnerView) return;
        var fields = _runnerView.querySelectorAll('textarea[data-request-key]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].dataset.requestKey === key) fields[i].disabled = false;
        }
    }

    /**
     * A phase of steps: its review or its confirmation, then its steps (kept steps stay kept on a stop).
     * A later phase with no step passes without a review: there is nothing in it to stop before.
     */
    async function _runStepsPhase(run, k, phase) {
        var kept = !!phase.kept;
        var warned = _warnedEffects(phase.effects);
        if (!_autoContinue()) {
            if ((k > run.first && (phase.steps || []).length > 0) || warned.length > 0) {
                if (!(await _review(run, k, phase))) return { stop: 'user', phase: phase, from: 0, resolved: kept };
                _markConfirmed(run, warned);
            }
        } else {
            var fresh = _unconfirmed(run, warned);
            if (fresh.length > 0) {
                if (!(await _confirmEffects([{ phase: phase, effects: fresh }], []))) {
                    return { stop: 'user', phase: phase, from: 0, resolved: kept };
                }
                _markConfirmed(run, fresh);
            }
        }
        _hideReview();
        return _runSteps(run, k, phase);
    }

    /** An AI phase: its prompt built now and sent or copied, then the answer it waits for, then its commands. */
    async function _runAiPhase(run, k, phase, opts) {
        var commands = opts.answer || (phase.kept ? phase.steps : null);
        // An answer pasted before the run is taken now, with no prompt shown in it.
        if (opts.answer) _tallyAnswer(run, k, opts.answerChars);
        if (!commands) {
            var key = _requestKey(phase, run.plan);
            if (!_autoContinue() && k > run.first) {
                if (!(await _review(run, k, phase))) return { stop: 'user', phase: phase, from: 0, resolved: false };
            }
            // The request is read now: locked while this phase runs, and for good once its last phase starts.
            var takers = _requestPhases(key, run.plan);
            if (takers.length === 0 || takers[takers.length - 1].index <= k) run.started[key] = true;
            run.activeRequest = key;
            _lockRequest(key);
            var built = await _fetchPhase(run.params, k, { prompt: true, key: phase.key });
            var request = (_requests[key] || '').trim();
            _generatedPrompt = String(built.prompt || '') + (request ? '\n\n---\n\n**User Request:**\n' + request : '');
            _exchangePhase = k;
            if (_generalPromptTextarea) _generalPromptTextarea.value = _generatedPrompt;
            _renderAttach(run, phase);
            if (_exchangeHeaderEl) {
                _exchangeHeaderEl.textContent = _aiPhases().length > 1
                    ? _t('aiToolsExchangeFor', { name: phase.title })
                    : _t('aiToolsZoneExchange');
            }
            _aiResponseText = '';
            if (_aiResponseTextarea) _aiResponseTextarea.value = '';
            _aiResponseStatus = { type: 'idle' };
            _currentCommands = [];
            _answerPhase = null;
            _continuation = null;
            _answerParts = 0;
            _updateAiResponseStatusUI();
            var conn = _getDefaultConnection();
            var waiting = _awaitAnswer();
            _showAwaitingAnswer(run, k, phase);
            _updateActionStates();
            if (run.send && conn) {
                _morphPrimary(_t('aiToolsSendingToAi', { model: conn.defaultModel || conn.model || '' }));
                await _sendToAi(conn);
            } else {
                _morphPrimary(_t('aiToolsRunActive'));
                _focusGeneralPrompt();
                _autoCopyPromptWithToast();
                _tallyPrompt(run, k, _generatedPrompt.length);
            }
            // The answer it waits for: whatever the switch says, without a key this is where the run stops.
            commands = await waiting;
            if (commands === null) return { stop: 'user', phase: phase, from: 0, resolved: false };
            if (commands.planChanged) throw _planChangedError();
        }
        var answered = await _fetchPhase(run.params, k, { answer: commands, key: phase.key });
        run.plan[k] = answered;
        _plan[k] = answered;
        _showPhaseInBatch(k);
        // The switch on: a deletion in the answer is confirmed (the changes were announced at the start).
        if (_autoContinue()) {
            var deletes = _unconfirmed(run, _warnedEffects(answered.effects).filter(function (e) { return e.effect === 'delete'; }));
            if (deletes.length > 0 && !(await _confirmEffects([{ phase: answered, effects: deletes }], []))) {
                return { stop: 'user', phase: answered, from: 0, resolved: true };
            }
            _markConfirmed(run, deletes);
        }
        _hideReview();
        return _runSteps(run, k, answered);
    }

    /** A phase's steps, run; the phase's outcome for the run. */
    async function _runSteps(run, k, phase) {
        if (!phase.steps || phase.steps.length === 0) return {};
        _noteUploadLinks(run, phase.steps);
        var res = await _executeSteps(phase.steps, phase.effects || [],
            { body: _phaseBodies[k] || _stepsBody, name: phase.title, swaps: run.swaps });
        _noteOutside(run, phase.steps, res.ran);
        run.ran = true;
        run.ok += res.ok;
        run.fail += res.fail;
        run.last = { name: phase.title, ok: res.ok, fail: res.fail, total: res.ok + res.fail };
        // A phase that saved the project's theme mode: the editor's own theme controls (the toolbar's Theme, the CSS
        // tool's scope) follow what was saved, as they do after the CSS tool's Save Settings.
        var themed = res.fail === 0 && res.stoppedAt < 0 ? phase.steps.filter(function (s) { return s && s.command === 'setThemeMode'; }).pop() : null;
        if (themed && window.PreviewStyleTheme && window.PreviewStyleTheme.themeModeSaved) window.PreviewStyleTheme.themeModeSaved(themed.params || {});
        // The preview shows what the phase changed before the next one is reviewed. The Edit list comes
        // first, so the reload never lands on a route the phase deleted: leaving it has loaded the preview.
        if (res.ok > 0 && window.PreviewManager) {
            try {
                if (!(await window.PreviewManager.refreshEditList())) window.PreviewManager.reload();
            } catch (_e) { /* ignore */ }
        }
        if (res.stoppedAt >= 0) {
            return { stop: res.reason, phase: phase, from: res.stoppedAt, resolved: true, command: res.command };
        }
        return {};
    }

    /**
     * The run ends before its last phase: the rest is kept for later (the steps of this phase not run,
     * when it ran some, and every later phase), and the header and a toast say why.
     */
    function _stopRun(run, k, outcome) {
        var toaster = _toaster();
        var api = window.QuickSiteAPI;
        // The steps of this phase not run, when it ran (or was resolved for running); else the run
        // resumes by resolving this phase again.
        var steps = outcome.resolved ? (outcome.phase.steps || []).slice(outcome.from) : [];
        var resumeAt = steps.length > 0 ? k : (outcome.resolved ? k + 1 : k);
        if (resumeAt < run.plan.length) {
            _writeKept({
                workflowId: run.workflowId,
                label: run.label,
                params: run.params,
                requests: Object.assign({}, _requests),
                phase: resumeAt,
                phaseKey: run.plan[resumeAt].key,
                phaseTitle: run.plan[resumeAt].title,
                steps: steps.length > 0 ? steps : null,
                effects: steps.length > 0 ? (outcome.phase.effects || []).slice(outcome.from) : null,
                later: run.plan.slice(resumeAt + 1).map(function (p) { return p.title; }),
                swaps: run.swaps
            });
        }
        var msg;
        if (outcome.stop === 'rate') {
            var backAt = (api && api.getWriteRate ? api.getWriteRate().windows || [] : []).reduce(function (m, w) {
                return w.remaining <= 0 ? Math.max(m, w.resetAt) : m;
            }, 0);
            msg = QSPanelWords.count('preview.workflowsRateStopped', steps.length,
                { time: backAt && api ? api.clockText(backAt) : '' });
        } else if (outcome.stop === 'parts') {
            msg = QSPanelWords.count('preview.workflowsRateKeptRest', steps.length);
        } else if (outcome.stop === 'abort') {
            msg = _t('aiToolsStoppedByStep', { command: outcome.command || '' });
        } else {
            msg = _t('aiToolsStoppedByUser', { name: outcome.phase.title });
        }
        if (_stepsHeaderState) _stepsHeaderState.textContent = msg;
        toaster(msg, outcome.stop === 'user' || outcome.stop === 'parts' ? 'info' : 'warning');
    }

    /**
     * Whether the run may start against the write rate: the steps the plan knows now (an AI phase's
     * are not known yet) counted as one batch is today. Each phase is counted again when it runs.
     */
    /**
     * Why a run cannot start now: what it needs and what is left, two counted sentences (either count may be 1), then when
     * enough is back.
     */
    function _rateRefusalWords(counted, plan, api) {
        return QSPanelWords.count('preview.workflowsRateRunNeeds', counted) + ' '
            + QSPanelWords.count('preview.workflowsRateLeft', plan.left, {
                time: api.clockText(plan.backAt),
                wait: api.waitText((plan.backAt - Date.now()) / 1000)
            });
    }

    function _rateAllowsStart(run) {
        var api = window.QuickSiteAPI;
        var rate = (api && typeof api.getWriteRate === 'function') ? api.getWriteRate() : { windows: [], waitSeconds: 0 };
        var counted = 0;
        run.plan.slice(run.first).forEach(function (p) { if (!p.ai || p.kept) counted += _countedSteps(p.steps || []); });
        if (rate.windows.length === 0 || counted === 0) return true;
        var plan = _planRun(counted, rate);
        if (plan.start || (plan.reason === 'never' && plan.fitsNow > 0)) return true;
        var waitMsg = _rateRefusalWords(counted, plan, api);
        if (_stepsHeaderState) _stepsHeaderState.textContent = waitMsg;
        _toaster()(waitMsg, 'warning');
        return false;
    }

    // ──────────────────────────── What a phase deletes or changes ───────

    /** The warned steps' effects (the server's, null for a step that is neither). */
    function _warnedEffects(effects) {
        return (effects || []).filter(function (e) { return e && (e.effect === 'delete' || e.effect === 'change'); });
    }

    function _effectKey(e) {
        return e.effect + '|' + e.what + '|' + e.target;
    }

    function _markConfirmed(run, effects) {
        effects.forEach(function (e) { run.confirmed[_effectKey(e)] = true; });
    }

    function _unconfirmed(run, effects) {
        var seen = {};
        return effects.filter(function (e) {
            var key = _effectKey(e);
            if (run.confirmed[key] || seen[key]) return false;
            seen[key] = true;
            return true;
        });
    }

    // What a step deletes or changes, by the server's group of it (an effect's `what`): the word naming it, by the rest
    // of its key's path (_t()). A group with no entry here is named as other commands.
    var EFFECT_WHAT = {
        pages: 'aiToolsWhatPages',
        menu: 'aiToolsWhatMenu',
        footer: 'aiToolsWhatFooter',
        components: 'aiToolsWhatComponents',
        snippets: 'aiToolsWhatSnippets',
        languages: 'aiToolsWhatLanguages',
        defaultLanguage: 'aiToolsWhatDefaultLanguage',
        texts: 'aiToolsWhatTexts',
        translationFiles: 'aiToolsWhatTranslationFiles',
        media: 'aiToolsWhatMedia',
        stylesheet: 'aiToolsWhatStylesheet',
        interactions: 'aiToolsWhatInteractions',
        privacy: 'aiToolsWhatPrivacy',
        other: 'aiToolsWhatOther'
    };
    var EFFECT_LIST_MAX = 8;

    /**
     * What a command flagged destructive does, for one that deletes nothing: a short word for a step and a
     * phase, and a line for a warning, each by the rest of its key's path (_t()). Its step stops a run exactly
     * as a deletion does, so `effect` stays 'delete' and every gate reads that; only what the run says about it
     * comes from here. A flagged command with no entry here reads as a deletion, which is what
     * cleanOrphanTranslations is.
     */
    var EFFECT_DOES = {
        restoreBackup: { word: 'aiToolsEffectRestores', line: 'aiToolsEffectRestoresLine' },
        transferOwnership: { word: 'aiToolsEffectTransfers', line: 'aiToolsEffectTransfersLine' },
        setOAuthCredentials: { word: 'aiToolsEffectSetsKeys', line: 'aiToolsEffectSetsKeysLine' },
        generateConsentLayer: { word: 'aiToolsEffectRegenerates', line: 'aiToolsEffectRegeneratesLine' }
    };

    /** What a warned step does in words of its own, or null when "Deletes" or "Changes" says it. */
    function _effectDoes(e) {
        return (e.effect === 'delete' && EFFECT_DOES[e.command]) || null;
    }

    /**
     * The words a warning takes for these effects: 'delete' when one really deletes, 'act' when the only
     * stopping steps do something else (EFFECT_DOES), 'change', or '' for none.
     */
    function _effectWord(effects) {
        if (effects.some(function (e) { return e.effect === 'delete' && !_effectDoes(e); })) return 'delete';
        if (effects.some(function (e) { return e.effect === 'delete'; })) return 'act';
        return effects.length > 0 ? 'change' : '';
    }

    /**
     * The lines a warning lists: deletions first, then changes, one line per group, its targets named
     * ("Deletes — Pages: about, services and 3 more"). A command that says what it does has a line of its own.
     */
    function _effectLines(effects) {
        var groups = [];
        var byKey = {};
        ['delete', 'change'].forEach(function (kind) {
            effects.forEach(function (e) {
                if (e.effect !== kind) return;
                var does = _effectDoes(e);
                var key = does ? 'does|' + e.command : kind + '|' + e.what;
                if (!byKey[key]) {
                    byKey[key] = { effect: kind, what: e.what, does: does, targets: [] };
                    groups.push(byKey[key]);
                }
                // A step of no named group gives its command as its target: such a line already says it.
                if (e.target !== '' && !(does && e.target === e.command) && byKey[key].targets.indexOf(e.target) === -1) {
                    byKey[key].targets.push(e.target);
                }
            });
        });
        return groups.map(function (g) {
            var verb = g.effect === 'delete' ? _t('aiToolsEffectDeletes') : _t('aiToolsEffectChanges');
            var line = g.does ? _t(g.does.line) : verb + ' — ' + _whatWord(g.what);
            if (g.targets.length > 0) {
                var shown = g.targets.slice(0, EFFECT_LIST_MAX).join(', ');
                if (g.targets.length > EFFECT_LIST_MAX) {
                    shown = _t('aiToolsEffectMore', { n: QSPanelWords.number(g.targets.length - EFFECT_LIST_MAX), items: shown });
                }
                line += ': ' + shown;
            }
            return { effect: g.effect, text: line };
        });
    }

    /**
     * The panel's danger dialog for what is about to be deleted or changed: each phase named with its
     * lines, then each AI phase announced (its commands are not known yet). True on the confirm.
     */
    function _confirmEffects(groups, aiPhases) {
        var utils = window.QuickSiteUtils;
        if (!utils || typeof utils.confirm !== 'function') return Promise.resolve(false);
        var word = _effectWord(groups.reduce(function (all, g) { return all.concat(g.effects); }, []));
        var parts = [];
        groups.forEach(function (g) {
            var lines = _effectLines(g.effects);
            if (lines.length === 0) return;
            parts.push(g.phase.title + ':\n' + lines.map(function (l) { return '• ' + l.text; }).join('\n'));
        });
        aiPhases.forEach(function (p) {
            parts.push(_t('aiToolsConfirmAiAnnounce', { name: p.title }));
        });
        return utils.confirm(parts.join('\n\n'), {
            type: 'danger',
            title: word === 'delete' ? _t('aiToolsConfirmTitleDelete')
                : (word === 'act' ? _t('aiToolsConfirmTitleAct') : _t('aiToolsConfirmTitleChange')),
            confirmText: word === 'delete' ? _t('aiToolsConfirmRunDelete')
                : (word === 'act' ? _t('aiToolsConfirmRunAct') : _t('aiToolsConfirmRunChange'))
        });
    }

    /**
     * "Run without stopping": the one dialog before anything runs, listing what every phase the plan
     * knows deletes and changes; none when nothing is known to. True to go on.
     */
    async function _confirmRunStart(run) {
        var groups = [];
        var aiPhases = [];
        for (var k = run.first; k < run.plan.length; k++) {
            var p = run.plan[k];
            if (p.ai) {
                aiPhases.push(p);
                continue;
            }
            var warned = _unconfirmed(run, _warnedEffects(p.effects));
            if (warned.length > 0) groups.push({ phase: p, effects: warned });
        }
        if (groups.length === 0) return true;
        if (!(await _confirmEffects(groups, aiPhases))) return false;
        groups.forEach(function (g) { _markConfirmed(run, g.effects); });
        return true;
    }

    // ──────────────────────────── The review ────────────────────────────

    /** The pause before phase k: what it does, Continue (its confirmation) or Stop. True on Continue. */
    function _review(run, k, phase) {
        return new Promise(function (resolve) {
            _renderReview(run, k, phase, resolve);
        });
    }

    function _renderReview(run, k, phase, resolve) {
        if (!_reviewEl) {
            resolve(false);
            return;
        }
        _reviewEl.replaceChildren();
        _recoveryEls = null;
        _reviewEl.hidden = false;
        var warned = _warnedEffects(phase.effects);
        var lines = _effectLines(warned);
        var deletes = lines.some(function (l) { return l.effect === 'delete'; });
        _reviewEl.className = 'preview-contextual-ai-tools__review'
            + (deletes ? ' preview-contextual-ai-tools__review--delete' : (lines.length ? ' preview-contextual-ai-tools__review--change' : ''));

        var title = document.createElement('p');
        title.className = 'preview-contextual-ai-tools__review-title';
        title.textContent = _t('aiToolsReviewNext', {
            current: QSPanelWords.number(k + 1), total: QSPanelWords.number(run.plan.length), name: phase.title
        });
        _reviewEl.appendChild(title);

        var what = document.createElement('p');
        what.className = 'preview-contextual-ai-tools__review-what';
        what.textContent = phase.ai
            ? _t('aiToolsReviewAi')
            : QSPanelWords.count('preview.workflowsReviewSteps', (phase.steps || []).length);
        _reviewEl.appendChild(what);

        if (run.last) {
            var last = document.createElement('p');
            last.className = 'preview-contextual-ai-tools__review-last';
            var lastWords = {
                ok: QSPanelWords.number(run.last.ok), total: QSPanelWords.number(run.last.total),
                fail: QSPanelWords.number(run.last.fail), name: run.last.name
            };
            last.textContent = run.last.fail > 0 ? _t('aiToolsReviewLastFailed', lastWords) : _t('aiToolsReviewLast', lastWords);
            _reviewEl.appendChild(last);
        }

        if (lines.length > 0) _reviewEl.appendChild(_renderEffectList(lines));

        if (phase.ai) {
            _reviewEl.appendChild(_renderRequestField(phase, true, '-review'));
        }

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__review-actions';
        var go = document.createElement('button');
        go.type = 'button';
        go.className = 'admin-btn ' + (deletes ? 'admin-btn--danger' : 'admin-btn--primary');
        var word = _effectWord(warned);
        go.textContent = word === 'delete' ? _t('aiToolsReviewContinueDelete')
            : (word === 'act' ? _t('aiToolsReviewContinueAct')
                : (lines.length ? _t('aiToolsReviewContinueChange') : _t('aiToolsReviewContinue')));
        var stop = document.createElement('button');
        stop.type = 'button';
        stop.className = 'admin-btn';
        stop.textContent = _t('aiToolsReviewStop');
        go.addEventListener('click', function () { resolve(true); });
        stop.addEventListener('click', function () { resolve(false); });
        actions.appendChild(go);
        actions.appendChild(stop);
        _reviewEl.appendChild(actions);
        if (_stepsSetOpen && !phase.ai) _stepsSetOpen(true);
        if (_stepsHeaderState) {
            _stepsHeaderState.textContent = QSPanelWords.count('preview.workflowsStepsReady', (phase.steps || []).length);
        }
        go.focus();
    }

    /**
     * A run waiting for an AI answer: the bar says so, and Stop ends the run there. An answer that
     * cannot run is recovered from it: Continue the answer, Send again (_refreshRecovery()).
     */
    function _showAwaitingAnswer(run, k, phase) {
        if (!_reviewEl) return;
        _reviewEl.replaceChildren();
        _reviewEl.hidden = false;
        _reviewEl.className = 'preview-contextual-ai-tools__review';
        var title = document.createElement('p');
        title.className = 'preview-contextual-ai-tools__review-title';
        title.textContent = _t('aiToolsReviewAwaiting', {
            current: QSPanelWords.number(k + 1), total: QSPanelWords.number(run.plan.length), name: phase.title
        });
        _reviewEl.appendChild(title);
        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__review-actions';
        _recoveryEls = {
            continueBtn: _renderRecoveryButton('continue', 'admin-btn--primary', _t('aiToolsContinueAnswer'),
                _onContinueAnswerClick),
            sendAgainBtn: _renderRecoveryButton('send-again', '', _t('aiToolsSendAgain'),
                _onSendAgainClick)
        };
        _recoveryEls.sendAgainBtn.title = _t('aiToolsSendAgainTitle');
        actions.appendChild(_recoveryEls.continueBtn);
        actions.appendChild(_recoveryEls.sendAgainBtn);
        var stop = document.createElement('button');
        stop.type = 'button';
        stop.className = 'admin-btn';
        stop.textContent = _t('aiToolsReviewStop');
        stop.addEventListener('click', function () {
            if (!_answerWaiter) return;
            var waiter = _answerWaiter;
            _answerWaiter = null;
            _cancelAutoExecute();
            waiter.resolve(null);
        });
        actions.appendChild(stop);
        _reviewEl.appendChild(actions);
        _refreshRecovery();
    }

    function _hideReview() {
        if (!_reviewEl) return;
        _reviewEl.replaceChildren();
        _reviewEl.hidden = true;
        _recoveryEls = null;
    }

    /** A warning's lines, as a list: red for a deletion, amber for a change. */
    function _renderEffectList(lines) {
        var list = document.createElement('ul');
        list.className = 'preview-contextual-ai-tools__effects';
        lines.forEach(function (l) {
            var item = document.createElement('li');
            item.className = 'preview-contextual-ai-tools__effect--' + l.effect;
            item.textContent = l.text;
            list.appendChild(item);
        });
        return list;
    }

    /** A promise of the AI answer's commands (null when the run is stopped while it waits). */
    function _awaitAnswer() {
        return new Promise(function (resolve) {
            _answerWaiter = { resolve: resolve };
        });
    }

    /** While a run is under way: its parameters, its requests and ← Back locked; the plan's parameters are the run's. */
    function _setRunLocked(locked) {
        if (!_runnerView) return;
        var section = _runnerView.querySelector('[data-section="parameters"]');
        var fields = section ? section.querySelectorAll('input, select, textarea') : [];
        for (var i = 0; i < fields.length; i++) fields[i].disabled = locked;
        _paramPickers.forEach(function (p) { if (typeof p.refresh === 'function') p.refresh(); });
        var requests = _yourPromptBody ? _yourPromptBody.querySelectorAll('textarea') : [];
        for (var r = 0; r < requests.length; r++) requests[r].disabled = false;
        var back = _runnerView.querySelector('.preview-contextual-ai-tools__runner-back');
        if (back) back.disabled = locked;
    }

    /** A request, read when a phase that takes it starts: its fields lock (until _releaseRequest(), unless it was the last). */
    function _lockRequest(key) {
        var fields = _runnerView ? _runnerView.querySelectorAll('textarea[data-request-key]') : [];
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].dataset.requestKey === key) fields[i].disabled = true;
        }
    }

    /** The run's phases in a line, each with its state; only for a run of more than one phase. */
    function _renderRunLine() {
        if (!_runLineEl) return;
        _runLineEl.replaceChildren();
        var phases = _run ? _run.plan : _plan;
        _runLineEl.hidden = phases.length < 2;
        if (phases.length < 2) return;
        var marks = { done: '✅', current: '▶', stopped: '⏹' };
        phases.forEach(function (p, i) {
            if (i > 0) _runLineEl.appendChild(document.createTextNode(' → '));
            var item = document.createElement('span');
            var state = _run ? (_run.states[i] || 'pending') : 'pending';
            item.className = 'preview-contextual-ai-tools__run-phase--' + state;
            item.textContent = (marks[state] ? marks[state] + ' ' : '') + p.title + (p.ai ? ' (' + _t('aiToolsKindAi') + ')' : '');
            _runLineEl.appendChild(item);
        });
    }

    function _toaster() {
        return (window.QuickSiteAdmin && typeof window.QuickSiteAdmin.showToast === 'function')
            ? window.QuickSiteAdmin.showToast.bind(window.QuickSiteAdmin)
            : function () {};
    }

    // ──────────────────────────── Execute a phase's steps ───────────────
    //
    // Every command but a read counts against the account's write rate (the
    // server's own list: QuickSiteAPI.countsAsWrite). Before a phase runs its counted steps are
    // set against what is left of each window (_planRun): a phase that would have to wait longer than
    // the runner's wait limit does not run, and one that can never fit in one go is offered in parts.
    // During a phase, a refusal whose wait fits the limit is waited out and the same step sent again; a
    // longer one stops the run. A step's own options: retryOn sends it again on those statuses (up to
    // maxRetries times, retryDelayMs apart: the schema's defaults when left out), and abortOnFail: true
    // stops the run when it fails; a step that says nothing carries on after a failure.

    /** Commands of a list that count against the write rate. */
    function _countedSteps(commands) {
        var api = window.QuickSiteAPI;
        var n = 0;
        for (var i = 0; i < commands.length; i++) {
            var name = commands[i].command || commands[i].name;
            if (!api || typeof api.countsAsWrite !== 'function' || api.countsAsWrite(name)) n++;
        }
        return n;
    }

    /**
     * Whether a run of `counted` write steps can start against the windows as
     * they stand. A window shorter than the wait limit never stops a run: each
     * of its waits is short. A longer one lets the run start when it has room,
     * or when the one wait the run needs from it (until its reset) fits the
     * limit; otherwise the run waits for its reset ('wait') — unless even a full
     * window cannot hold the run ('never'), which is offered in parts: `fitsNow`
     * write steps can run at once.
     *
     * @returns {{start: boolean, reason?: string, needs: number, left: number, backAt?: number,
     *            fitsNow?: number, window?: object}}
     */
    function _planRun(counted, rate) {
        var limitMs = Math.max(0, Number(rate.waitSeconds) || 0) * 1000;
        var now = Date.now();
        var plan = { start: true, needs: counted, left: Infinity, fitsNow: Infinity };
        var waitUntil = 0;
        var never = null;
        for (var i = 0; i < rate.windows.length; i++) {
            var w = rate.windows[i];
            plan.left = Math.min(plan.left, w.remaining);
            var periodMs = w.period * 1000;
            var firstWait = Math.max(0, w.resetAt - now);
            var shortWindow = periodMs <= limitMs;
            var capacity = shortWindow ? Infinity : (firstWait <= limitMs ? w.remaining + w.max : w.remaining);
            plan.fitsNow = Math.min(plan.fitsNow, capacity);
            if (counted <= w.remaining || shortWindow) continue;
            if (firstWait <= limitMs && counted <= w.remaining + w.max) continue;
            if (counted <= w.max) {
                waitUntil = Math.max(waitUntil, w.resetAt);
            } else if (!never) {
                never = w;
            }
        }
        if (plan.left === Infinity) plan.left = counted;
        if (never) {
            plan.start = false;
            plan.reason = 'never';
            plan.window = never;
            plan.backAt = waitUntil || never.resetAt;
        } else if (waitUntil > 0) {
            plan.start = false;
            plan.reason = 'wait';
            plan.backAt = waitUntil;
        }
        return plan;
    }

    /** The first steps of a list holding at most `writes` counted ones. */
    function _firstPart(commands, writes) {
        var api = window.QuickSiteAPI;
        var taken = 0;
        var i = 0;
        for (; i < commands.length; i++) {
            var name = commands[i].command || commands[i].name;
            var counts = !api || typeof api.countsAsWrite !== 'function' || api.countsAsWrite(name);
            if (counts && taken >= writes) break;
            if (counts) taken++;
        }
        return i;
    }

    /**
     * Run one phase's steps into opts.body (its rows drawn there, each with what it deletes or changes),
     * one at a time. Returns {ok, fail, stoppedAt, reason, command}: stoppedAt is the first step not
     * done (-1 when every step ran), reason 'rate' (the allowance), 'parts' (a run in parts), 'abort' (a
     * failing step marked abortOnFail) or 'user' (the parts offer declined).
     */
    async function _executeSteps(steps, effects, opts) {
        opts = opts || {};
        var toaster = _toaster();
        var api = window.QuickSiteAPI;
        var out = { ok: 0, fail: 0, stoppedAt: -1, reason: '', command: '', ran: [] };
        var swaps = opts.swaps || null;
        // A link the run has downloaded already stands for its stored file in these steps too.
        if (swaps) _applySwaps(steps, 0, swaps);

        // ── Before starting: the counted steps against what is left ──
        var rate = (api && typeof api.getWriteRate === 'function') ? api.getWriteRate() : { windows: [], waitSeconds: 0 };
        var counted = _countedSteps(steps);
        var keepAfter = null;
        if (rate.windows.length > 0 && counted > 0) {
            var plan = _planRun(counted, rate);
            if (!plan.start && (plan.reason === 'wait' || plan.fitsNow <= 0)) {
                var waitMsg = _rateRefusalWords(counted, plan, api);
                if (_stepsHeaderState) _stepsHeaderState.textContent = waitMsg;
                toaster(waitMsg, 'warning');
                out.stoppedAt = 0;
                out.reason = 'rate';
                return out;
            }
            if (!plan.start) {
                // More than a whole window: never in one go. Offered in parts.
                var fits = _firstPart(steps, plan.fitsNow);
                var neverMsg = _t('aiToolsRateNeverOneGo', {
                    needs: QSPanelWords.number(counted),
                    max: QSPanelWords.number(plan.window.max),
                    period: api.periodText(plan.window.period)
                });
                var partsMsg = QSPanelWords.count('preview.workflowsRateRunInParts', fits,
                    { rest: QSPanelWords.number(steps.length - fits) });
                var go = window.QuickSiteUtils && typeof window.QuickSiteUtils.confirm === 'function'
                    ? await window.QuickSiteUtils.confirm(neverMsg + ' ' + partsMsg, { confirmText: _t('aiToolsRateRunPart') })
                    : false;
                if (!go) {
                    if (_stepsHeaderState) _stepsHeaderState.textContent = neverMsg;
                    out.stoppedAt = 0;
                    out.reason = 'user';
                    return out;
                }
                keepAfter = fits;
            }
        }

        var body = opts.body || _stepsBody;
        if (_stepsSetOpen) _stepsSetOpen(true);
        if (body) body.replaceChildren();
        var rows = [];
        for (var i = 0; i < steps.length; i++) {
            var row = _renderStepRow(steps[i], effects[i] || null);
            rows.push(row);
            if (body) body.appendChild(row.el);
        }

        var last = keepAfter !== null ? keepAfter : steps.length;
        for (var j = 0; j < last; j++) {
            if (_stepsHeaderState) {
                _stepsHeaderState.textContent = _t('aiToolsExecutionRunning', {
                    current: QSPanelWords.number(j + 1), total: QSPanelWords.number(steps.length)
                }) + (opts.name ? ' · ' + opts.name : '');
            }
            _updateStepRow(rows[j], 'running');
            var result;
            try {
                result = await _executeWithRetries(steps[j], rows[j]);
                // The write rate refused this step: wait it out when the wait fits
                // the limit (then the same step again), else stop here.
                while (result.rateLimited && result.retryAfter <= rate.waitSeconds) {
                    await _waitWithCountdown(result.retryAfter);
                    _updateStepRow(rows[j], 'running');
                    result = await _executeWithRetries(steps[j], rows[j]);
                }
            } catch (e) {
                result = { ok: false, error: (e && e.message) || String(e) };
            }
            if (result.rateLimited) {
                out.stoppedAt = j;
                out.reason = 'rate';
                _updateStepRow(rows[j], 'error', result.error || '');
                break;
            }
            if (result.ok) {
                out.ok++;
                out.ran[j] = true;
                _updateStepRow(rows[j], 'ok', result.message || '');
                if (swaps) _swapAfterDownload(steps, effects, rows, j, result, swaps);
                continue;
            }
            out.fail++;
            _updateStepRow(rows[j], 'error', result.error || '');
            if (steps[j].abortOnFail === true) {
                out.stoppedAt = j;
                out.reason = 'abort';
                out.command = steps[j].command || steps[j].name || '';
                break;
            }
        }
        if (out.stoppedAt < 0 && keepAfter !== null && keepAfter < steps.length) {
            out.stoppedAt = keepAfter;
            out.reason = 'parts';
        }
        return out;
    }

    /** A whole number of a step's option, kept to the schema's range, its default when absent or out of it. */
    function _stepOption(value, min, max, fallback) {
        var n = Number(value);
        return Number.isInteger(n) && n >= min && n <= max ? n : fallback;
    }

    /**
     * One step, sent again while its answer's status is one its retryOn lists: up to maxRetries times
     * (default 3, at most 10), retryDelayMs apart (default 1000, 100 to 30000) — the row says so.
     */
    async function _executeWithRetries(step, row) {
        var result = await _executeOneCommand(step);
        var retryOn = Array.isArray(step.retryOn) ? step.retryOn.map(Number) : [];
        if (retryOn.length === 0) return result;
        var max = _stepOption(step.maxRetries, 1, 10, 3);
        var delay = _stepOption(step.retryDelayMs, 100, 30000, 1000);
        var attempt = 0;
        while (!result.ok && !result.rateLimited && attempt < max && retryOn.indexOf(result.status) !== -1) {
            attempt++;
            _updateStepRow(row, 'retry', _t('aiToolsStepRetry', {
                attempt: QSPanelWords.number(attempt), max: QSPanelWords.number(max),
                seconds: QSPanelWords.number(delay / 1000), error: result.error || ''
            }));
            await new Promise(function (resolve) { setTimeout(resolve, delay); });
            _updateStepRow(row, 'running');
            result = await _executeOneCommand(step);
        }
        if (!result.ok && !result.rateLimited && attempt > 0) {
            result.error = QSPanelWords.count('preview.workflowsStepFailedAfterRetries', attempt, { error: result.error || '' });
        }
        return result;
    }

    /** Wait `seconds`, counting down in the batch's header. */
    function _waitWithCountdown(seconds) {
        var api = window.QuickSiteAPI;
        var end = Date.now() + Math.max(0, seconds) * 1000;
        return new Promise(function (resolve) {
            var tick = function () {
                var left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
                if (_stepsHeaderState) {
                    _stepsHeaderState.textContent = _t('aiToolsRateWaiting',
                        { wait: api ? api.waitText(left) : QSPanelWords.number(left) + ' s' });
                }
                if (left <= 0) {
                    resolve();
                    return;
                }
                setTimeout(tick, 1000);
            };
            tick();
        });
    }

    /**
     * One step, through the panel's API client (the project marker, the write
     * allowance kept current). A refusal by the write rate is reported as such,
     * with the server's wait, so the run can wait it out or stop; every answer
     * carries its HTTP status, which a step's retryOn reads.
     */
    async function _executeOneCommand(cmd) {
        var commandName = cmd.command || cmd.name;
        if (!commandName) return { ok: false, status: 0, error: _t('workflowsStepMissingCommand') };
        var method = String(cmd.method || 'POST').toUpperCase();
        var res = await window.QuickSiteAdmin.apiRequest(commandName, method, method !== 'GET' ? (cmd.params || null) : null);
        var data = res.data || {};
        if (res.status === 429 && data.code === 'quota.rate_limited') {
            return {
                ok: false,
                status: res.status,
                rateLimited: true,
                retryAfter: Number(data.data && data.data.retry_after) || 0,
                error: data.message || ('HTTP ' + res.status)
            };
        }
        var isSuccess = res.ok && ((data.status >= 200 && data.status < 300) || data.success === true);
        if (!isSuccess) {
            return { ok: false, status: res.status, error: data.error || data.message || ('HTTP ' + res.status) };
        }
        return { ok: true, status: res.status, message: data.message || '', data: data };
    }

    // ──────────────────────────── Pictures by link ──────────────────────
    //
    // A step that brings a picture in by its link (uploadAsset with url) cannot know the name the picture
    // gets: uploadAsset names the stored file itself (a name taken, a redirect) and answers its path. Once
    // the download succeeds, that link, wherever it stands as a whole address in a step still to run,
    // becomes the stored path. A link left (a download that failed, a use before its upload, a link with no
    // upload) is an address outside the site: the run's summary lists every one its steps placed.

    /** The link an uploadAsset step downloads, or ''. */
    function _downloadedLink(step) {
        var p = step && step.params;
        return step && step.command === 'uploadAsset' && p && typeof p.url === 'string' ? p.url : '';
    }

    /** Whether text[at] is where an address may start (the text's start, a space, a quote, `(` or `,`). */
    function _addressStartsAt(text, at) {
        return at === 0 || /[\s"'(,]/.test(text.charAt(at - 1));
    }

    /**
     * Whether an address ending at text[end] ends there: the text's end, a space, a quote, `)`, or a comma
     * that a space or the text's end follows (a comma inside an address goes on).
     */
    function _addressEndsAt(text, end) {
        if (end >= text.length) return true;
        var c = text.charAt(end);
        if (/[\s"')]/.test(c)) return true;
        return c === ',' && (end + 1 >= text.length || /\s/.test(text.charAt(end + 1)));
    }

    /** The text with every whole occurrence of link replaced by path (a longer address that merely starts with it is left). */
    function _swapLinkIn(text, link, path) {
        if (!link) return text;
        var out = '';
        var from = 0;
        var at = text.indexOf(link);
        while (at !== -1) {
            var end = at + link.length;
            if (_addressStartsAt(text, at) && _addressEndsAt(text, end)) {
                out += text.slice(from, at) + path;
                from = end;
                at = text.indexOf(link, end);
            } else {
                at = text.indexOf(link, at + 1);
            }
        }
        return out + text.slice(from);
    }

    /** Whether the text holds the link as a whole address (_swapLinkIn()'s reading). */
    function _hasLink(text, link) {
        var at = link ? text.indexOf(link) : -1;
        while (at !== -1) {
            if (_addressStartsAt(text, at) && _addressEndsAt(text, at + link.length)) return true;
            at = text.indexOf(link, at + 1);
        }
        return false;
    }

    /** A step's value with every swap made; the same value when none applies. */
    function _swapValue(value, swaps, links) {
        if (typeof value === 'string') {
            var text = value;
            links.forEach(function (link) { text = _swapLinkIn(text, link, swaps[link]); });
            return text;
        }
        if (Array.isArray(value)) return value.map(function (v) { return _swapValue(v, swaps, links); });
        if (value && typeof value === 'object') {
            var copy = {};
            Object.keys(value).forEach(function (key) { copy[key] = _swapValue(value[key], swaps, links); });
            return copy;
        }
        return value;
    }

    /**
     * Every swap made in steps[from..]: each changed step replaced in place by its swapped copy (an uploadAsset
     * step's own url stays: it is what that step downloads). The changed indexes.
     */
    function _applySwaps(steps, from, swaps) {
        var links = Object.keys(swaps).filter(function (l) { return l && typeof swaps[l] === 'string'; })
            .sort(function (a, b) { return b.length - a.length; });
        var changed = [];
        if (links.length === 0) return changed;
        for (var i = from; i < steps.length; i++) {
            var step = steps[i];
            if (!step || !step.params || typeof step.params !== 'object') continue;
            var keepUrl = _downloadedLink(step);
            var params = _swapValue(step.params, swaps, links);
            if (keepUrl) params.url = keepUrl;
            if (JSON.stringify(params) === JSON.stringify(step.params)) continue;
            steps[i] = Object.assign({}, step, { params: params });
            changed.push(i);
        }
        return changed;
    }

    /** After steps[j] ran: a picture it downloaded by link takes its stored path in the steps still to run, rows redrawn. */
    function _swapAfterDownload(steps, effects, rows, j, result, swaps) {
        var link = _downloadedLink(steps[j]);
        var answer = result && result.data && result.data.data;
        var path = answer && typeof answer.path === 'string' ? answer.path : '';
        if (!link || !path) return;
        swaps[link] = path;
        _applySwaps(steps, j + 1, swaps).forEach(function (i) {
            var fresh = _renderStepRow(steps[i], effects[i] || null);
            if (rows[i] && rows[i].el && rows[i].el.parentNode) rows[i].el.replaceWith(fresh.el);
            rows[i] = fresh;
        });
    }

    /** Every link an uploadAsset step of these steps names, noted on the run once each. */
    function _noteUploadLinks(run, steps) {
        (steps || []).forEach(function (s) {
            var link = _downloadedLink(s);
            if (link && run.links.indexOf(link) === -1) run.links.push(link);
        });
    }

    /** Whether an address leads outside the site: a full one (`https://…`, `http://…`) or one that names its host (`//…`). */
    function _isOutside(address) {
        return /^(https?:)?\/\//i.test(String(address).trim());
    }

    /** The files of a srcset (their descriptors left out), split as the engine splits one: QSSrcset, core/srcset.js. */
    function _srcsetFiles(value) {
        return QSSrcset.candidates(value).map(function (c) { return c.address; });
    }

    /** The addresses of a stylesheet text's url(…). */
    function _cssUrls(text) {
        var out = [];
        var re = /url\(\s*(['"]?)([^'")]*)\1\s*\)/gi;
        var m;
        while ((m = re.exec(String(text))) !== null) out.push(m[2].trim());
        return out;
    }

    // The commands whose strings are stylesheet text, where a url(…) is a picture.
    var CSS_COMMANDS = ['editStyles', 'setRootVariables', 'setStyleRule', 'injectSnippetCss'];

    /**
     * The outside picture addresses one step places: an img's or a source's src and srcset, a video's poster, a
     * url(…) in a style attribute or a stylesheet command, and any link an uploadAsset of the run names (a
     * failed download, a use before its upload) wherever it stands, the uploading step's own url aside.
     */
    function _outsideOf(step, links) {
        var found = [];
        var add = function (address) {
            if (_isOutside(address) && found.indexOf(address) === -1) found.push(address);
        };
        var css = CSS_COMMANDS.indexOf(step.command) !== -1;
        var own = _downloadedLink(step);
        var walk = function (value, key, parent) {
            if (typeof value === 'string') {
                if (css) _cssUrls(value).forEach(add);
                if (!(own && parent === step.params && key === 'url')) {
                    links.forEach(function (link) { if (_hasLink(value, link)) add(link); });
                }
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (v, i) { walk(v, i, value); });
                return;
            }
            if (!value || typeof value !== 'object') return;
            var tag = typeof value.tag === 'string' ? value.tag.toLowerCase() : '';
            var p = value.params && typeof value.params === 'object' ? value.params : null;
            if (tag && p) {
                if ((tag === 'img' || tag === 'source') && typeof p.src === 'string') add(p.src);
                if ((tag === 'img' || tag === 'source') && typeof p.srcset === 'string') _srcsetFiles(p.srcset).forEach(add);
                if (tag === 'video' && typeof p.poster === 'string') add(p.poster);
                if (typeof p.style === 'string') _cssUrls(p.style).forEach(add);
            }
            Object.keys(value).forEach(function (k) { walk(value[k], k, value); });
        };
        walk(step.params, null, null);
        return found;
    }

    /** The outside pictures the steps that ran placed, noted on the run once each. */
    function _noteOutside(run, steps, ran) {
        (steps || []).forEach(function (s, j) {
            if (!ran || !ran[j] || !s || !s.params) return;
            _outsideOf(s, run.links).forEach(function (address) {
                if (run.outside.indexOf(address) === -1) run.outside.push(address);
            });
        });
    }

    var _outsideEl = null;

    /** The run's outside pictures under the run's state: hidden when there are none. */
    function _renderOutside(addresses) {
        if (!_outsideEl) return;
        _outsideEl.replaceChildren();
        _outsideEl.hidden = !addresses || addresses.length === 0;
        if (_outsideEl.hidden) return;
        var title = document.createElement('p');
        title.className = 'preview-contextual-ai-tools__outside-title';
        title.textContent = _t('aiToolsOutsideTitle');
        _outsideEl.appendChild(title);
        var line = document.createElement('p');
        line.className = 'preview-contextual-ai-tools__outside-line';
        line.textContent = _t('aiToolsOutsideLine');
        _outsideEl.appendChild(line);
        var list = document.createElement('ul');
        list.className = 'preview-contextual-ai-tools__outside-list';
        addresses.forEach(function (address) {
            var item = document.createElement('li');
            item.className = 'preview-contextual-ai-tools__outside-address';
            item.textContent = address;
            list.appendChild(item);
        });
        _outsideEl.appendChild(list);
    }

    // ──────────────────────────── Starred pictures to attach ────────────
    //
    // A checkbox the spec marks attachStarred (Create Website's Include assets) sends the starred assets'
    // descriptions in the prompt. Ticked, the exchange lists the starred pictures beside the prompt for the
    // person to attach to their AI chat; a prompt sent through a connection attaches none, and says so.

    var _attachEl = null;

    /** The run's own workflow's parameter marked attachStarred, when the run ticked it; else null. */
    function _attachParam(run) {
        var spec = _activeWorkflowSpec;
        if (!run || !spec) return null;
        var params = Array.isArray(spec.parameters) ? spec.parameters : [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            if (p && p.attachStarred === true && run.params && run.params[p.id] === true) return p;
        }
        return null;
    }

    /** The address a starred picture is served at to the project's members (the preview's own base). */
    function _starredUrl(asset) {
        var base = String((window.PreviewConfig || {}).previewBase || '').replace(/\/+$/, '');
        return base + '/assets/' + encodeURIComponent(asset.category || 'images') + '/' + encodeURIComponent(asset.filename);
    }

    /** One starred picture to attach: its thumbnail, its name and its download. */
    function _renderAttachItem(asset) {
        var url = _starredUrl(asset);
        var item = document.createElement('li');
        item.className = 'preview-contextual-ai-tools__attach-item';
        var thumb = document.createElement('img');
        thumb.className = 'preview-contextual-ai-tools__attach-thumb';
        thumb.src = url;
        thumb.alt = '';
        thumb.loading = 'lazy';
        item.appendChild(thumb);
        var name = document.createElement('span');
        name.className = 'preview-contextual-ai-tools__attach-name';
        name.textContent = asset.filename;
        item.appendChild(name);
        var download = document.createElement('a');
        download.className = 'preview-contextual-ai-tools__attach-download';
        download.href = url;
        download.setAttribute('download', asset.filename);
        download.textContent = _t('aiToolsAttachDownload');
        item.appendChild(download);
        return item;
    }

    /**
     * The attach list for the AI phase whose prompt was just built (null clears it): only the run's own
     * workflow's phase, with its marked box ticked. Through a connection: the line that nothing is attached.
     */
    async function _renderAttach(run, phase) {
        if (!_attachEl) return;
        _attachEl.replaceChildren();
        _attachEl.hidden = true;
        if (!run || !phase || !_activeWorkflowSpec || phase.workflowId !== _activeWorkflowSpec.id || !_attachParam(run)) return;
        if (run.send && _getDefaultConnection()) {
            var line = document.createElement('p');
            line.className = 'preview-contextual-ai-tools__attach-line';
            line.textContent = _t('aiToolsAttachConnection');
            _attachEl.appendChild(line);
            _attachEl.hidden = false;
            return;
        }
        var pictures = [];
        try {
            var res = await window.QuickSiteAdmin.apiRequest('listAssets', 'GET', null, ['starred']);
            var assets = res && res.data && res.data.data && res.data.data.assets;
            pictures = assets && Array.isArray(assets.images) ? assets.images : [];
        } catch (_e) {
            pictures = [];
        }
        if (_run !== run || pictures.length === 0) return;
        var title = document.createElement('p');
        title.className = 'preview-contextual-ai-tools__attach-title';
        title.textContent = _t('aiToolsAttachTitle');
        _attachEl.appendChild(title);
        var hint = document.createElement('p');
        hint.className = 'preview-contextual-ai-tools__hint';
        hint.textContent = _t('aiToolsAttachHint');
        _attachEl.appendChild(hint);
        var list = document.createElement('ul');
        list.className = 'preview-contextual-ai-tools__attach-list';
        pictures.forEach(function (asset) {
            if (asset && typeof asset.filename === 'string') list.appendChild(_renderAttachItem(Object.assign({ category: 'images' }, asset)));
        });
        _attachEl.appendChild(list);
        _attachEl.hidden = false;
    }

    // ──────────────────────────── Kept steps ────────────────────────────
    //
    // What a stopped run did not reach, kept in the browser per account and project: the steps of
    // the phase it stopped in that did not run, and its later phases — as references, resolved when
    // the kept run reaches them, as in a run. Run them reopens the workflow with the run's parameters.

    /** Where this account's kept steps for this project live in the browser. */
    function _keptKey() {
        var base = (window.QuickSiteStorageKeys || {}).aiKeptSteps;
        var api = window.QuickSiteAPI;
        var account = (api && typeof api.getWriteRate === 'function') ? api.getWriteRate().account : '';
        var project = (window.PreviewConfig && window.PreviewConfig.currentProject) || '';
        return base && account && project ? base + ':' + account + ':' + project : null;
    }

    /**
     * The kept rest ({workflowId, label, params, requests, phase, phaseTitle, steps, effects, later,
     * swaps, savedAt}), or null.
     */
    function _readKept() {
        var key = _keptKey();
        if (!key) return null;
        try {
            var kept = JSON.parse(localStorage.getItem(key) || 'null');
            return kept && typeof kept.workflowId === 'string' && typeof kept.phase === 'number' ? kept : null;
        } catch (_e) {
            return null;
        }
    }

    function _writeKept(kept) {
        var key = _keptKey();
        if (!key) return;
        try {
            localStorage.setItem(key, JSON.stringify(Object.assign({ savedAt: Date.now() }, kept)));
        } catch (_e) { /* storage unavailable: the rest is not kept */ }
    }

    function _clearKept() {
        var key = _keptKey();
        if (!key) return;
        try { localStorage.removeItem(key); } catch (_e) { /* ignore */ }
    }

    /** The name of the run being started, for the kept steps' banner. */
    function _currentRunLabel() {
        return (_activeWorkflowSummary && (_activeWorkflowSummary.title || _activeWorkflowSummary.id)) || '';
    }

    var _keptEl = null;
    var _keptTimer = null;

    /**
     * The kept rest's banner, at the top of the panel: from which run and when, what is kept, the
     * warning that the page may have changed, and Run them (only once the allowance covers the kept
     * steps) / Discard. Redrawn when the allowance moves.
     */
    function _renderKept() {
        if (!_section) return;
        if (!_keptEl) {
            _keptEl = document.createElement('div');
            _keptEl.className = 'preview-contextual-ai-tools__kept';
            _section.insertBefore(_keptEl, _section.firstChild);
        }
        clearTimeout(_keptTimer);
        var kept = _readKept();
        _keptEl.replaceChildren();
        _keptEl.hidden = !kept;
        if (!kept) return;

        var api = window.QuickSiteAPI;
        var steps = Array.isArray(kept.steps) ? kept.steps : [];
        var plan = api && typeof api.getWriteRate === 'function'
            ? _planRun(_countedSteps(steps), api.getWriteRate())
            : { start: true };

        var title = document.createElement('p');
        title.className = 'preview-contextual-ai-tools__kept-title';
        var keptWords = { time: api ? api.clockText(kept.savedAt) : '', label: kept.label || '—' };
        title.textContent = steps.length > 0
            ? QSPanelWords.count('preview.workflowsKeptTitle', steps.length, keptWords)
            : _t('aiToolsKeptBefore', Object.assign({ name: kept.phaseTitle || '' }, keptWords));
        _keptEl.appendChild(title);
        var later = Array.isArray(kept.later) ? kept.later : [];
        if (later.length > 0) {
            var then = document.createElement('p');
            then.className = 'preview-contextual-ai-tools__kept-then';
            then.textContent = _t('aiToolsKeptThen', { names: later.join(', ') });
            _keptEl.appendChild(then);
        }
        var warn = document.createElement('p');
        warn.className = 'preview-contextual-ai-tools__kept-warning';
        warn.textContent = _t('aiToolsKeptWarning');
        _keptEl.appendChild(warn);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__kept-actions';
        var run = document.createElement('button');
        run.type = 'button';
        run.className = 'admin-btn admin-btn--primary';
        run.textContent = _t('aiToolsKeptRun');
        var canRun = plan.start || (plan.reason === 'never' && plan.fitsNow > 0);
        var notYetText = canRun ? '' : _t('aiToolsKeptNotYet', { time: api.clockText(plan.backAt) });
        // Not yet: the button looks unavailable but still answers a click with
        // when it will be (a disabled button would swallow the click silently).
        run.disabled = _executing;
        if (!canRun) {
            run.setAttribute('aria-disabled', 'true');
            run.title = notYetText;
        }
        run.addEventListener('click', function () {
            if (!canRun) {
                _toaster()(notYetText, 'info');
                return;
            }
            _resumeKept();
        });
        var discard = document.createElement('button');
        discard.type = 'button';
        discard.className = 'admin-btn';
        discard.textContent = _t('aiToolsKeptDiscard');
        discard.addEventListener('click', function () {
            _clearKept();
            _renderKept();
        });
        actions.appendChild(run);
        actions.appendChild(discard);

        if (!canRun) {
            var notYet = document.createElement('p');
            notYet.className = 'preview-contextual-ai-tools__kept-wait';
            notYet.textContent = notYetText;
            _keptEl.appendChild(notYet);
            _keptTimer = setTimeout(_renderKept, Math.max(1000, plan.backAt - Date.now() + 500));
        }
        _keptEl.appendChild(actions);
    }

    /**
     * Run the kept rest (the user asked): its workflow reopened with the run's parameters and requests,
     * then a run from the phase it stopped in. The rest stays kept until that run starts.
     */
    async function _resumeKept() {
        if (_executing) return;
        var kept = _readKept();
        if (!kept) return;
        var workflow = null;
        for (var i = 0; i < _allWorkflows.length; i++) {
            if (_allWorkflows[i].id === kept.workflowId) workflow = _allWorkflows[i];
        }
        if (!workflow) {
            _toaster()(_t('aiToolsKeptMissing', { label: kept.label || kept.workflowId }), 'warning');
            return;
        }
        await _onCardClick(workflow, { restore: { params: kept.params || {}, requests: kept.requests || {} } });
        if (!_activeWorkflowSpec || _activeWorkflowSummary !== workflow) return;
        _clearKept();
        _renderKept();
        await _startRun({ send: !!_getDefaultConnection(), resume: kept });
    }

    // ──────────────────────────── The plan ──────────────────────────────

    /** The workflow endpoint (workflow-generate-steps): a POST of the request, its data on success. */
    function _workflowRequest(body) {
        var cfg = window.PreviewConfig || {};
        var url = (cfg.adminUrl || '') + 'api/' + _helperPath('workflow-generate-steps');
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Accept-Language': QSPanelWords.lang };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        var id = _activeWorkflowSummary && _activeWorkflowSummary.id;
        return fetch(url, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(Object.assign({ workflowId: id }, body))
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (json) {
                if (json && json.planChanged === true) throw _planChangedError();
                if (!res.ok || !json || !json.success) {
                    throw new Error((json && json.error) || ('HTTP ' + res.status));
                }
                return json.data || {};
            });
        });
    }

    /** The run's phases, every one resolved against the project as it is now. */
    function _fetchPlan(params) {
        return _workflowRequest({ params: params }).then(function (data) {
            return Array.isArray(data.phases) ? data.phases : [];
        });
    }

    /**
     * Phase k resolved now. extra: {prompt: true} an AI phase's prompt, {answer: [...]} its AI's commands,
     * and in a run {key} the phase's key as the run's plan has it: the server refuses the request when
     * it now plans another phase there (_planChangedError()). The plan is never sent: the server
     * resolves every phase itself.
     */
    function _fetchPhase(params, k, extra) {
        return _workflowRequest(Object.assign({ params: params, phase: k }, extra || {})).then(function (data) {
            if (!data.phase) throw new Error(_t('workflowsNoSuchPhase'));
            return data.phase;
        });
    }

    /** The run's plan changed under it (the server's 409, or a kept rest whose phase moved): the run stops there. */
    function _planChangedError() {
        var err = new Error(_t('aiToolsPlanChanged'));
        err.planChanged = true;
        return err;
    }

    /** The plan again (the preview before a run): the Batch, the run line, the requests and the AI exchange follow it. */
    async function _refreshPlan() {
        if (!_activeWorkflowSummary || _run) return;
        var seq = ++_planSeq;
        var plan = null;
        try {
            plan = await _fetchPlan(_paramValues);
        } catch (err) {
            console.warn('[PreviewAiTools] plan failed:', err);
        }
        if (seq !== _planSeq || _run) return;
        _plan = plan || [];
        if (_chainHasAi() !== _renderedWithAi) {
            // An AI phase came or went: the runner is drawn again, with or without its AI exchange.
            _renderRunner();
        } else {
            _renderYourPromptFields();
        }
        _renderRunLine();
        _renderBatch();
        _updateActionStates();
    }

    // ──────────────────────────── The Batch ─────────────────────────────

    var _phaseBodies = [];               // each phase's rows, by index (the Batch's groups)
    var _phaseGroups = [];               // each phase's group ({el, body}); empty for a run of one phase

    /** A phase's rows: its steps, or for an AI phase its answer's commands once classified. */
    function _phaseRows(phase) {
        var steps = phase.steps || [];
        var effects = phase.effects || [];
        if (phase.ai && _answerPhase && _answerPhase.index === phase.index && steps.length === 0) {
            steps = _answerPhase.steps || [];
            effects = _answerPhase.effects || [];
        }
        return { steps: steps, effects: effects };
    }

    /** A phase's rows drawn into its body (an AI phase with none yet says its commands come with its answer). */
    function _fillPhaseRows(body, phase, rows) {
        if (rows.steps.length === 0 && phase.ai) {
            var pending = document.createElement('p');
            pending.className = 'preview-contextual-ai-tools__batch-pending';
            pending.textContent = _t('aiToolsBatchAiPending');
            body.appendChild(pending);
        }
        for (var s = 0; s < rows.steps.length; s++) {
            body.appendChild(_renderStepRow(rows.steps[s], rows.effects[s] || null).el);
        }
    }

    /**
     * The Batch: the plan's phases, each a group of its steps with what they delete or change (an AI
     * phase's commands once its answer is parsed); one phase is its rows alone. `open`: the group shown
     * open (the phase being reviewed or run).
     */
    function _renderBatch(open) {
        if (!_stepsBody) return;
        _stepsBody.replaceChildren();
        _phaseBodies = [];
        _phaseGroups = [];
        var phases = _run ? _run.plan : _plan;
        var total = 0;
        for (var i = 0; i < phases.length; i++) {
            var rows = _phaseRows(phases[i]);
            total += rows.steps.length;
            var body = _stepsBody;
            if (phases.length > 1) {
                var group = _renderPhaseGroup(phases[i], rows.steps, rows.effects, open === i);
                _stepsBody.appendChild(group.el);
                _phaseGroups[i] = group;
                body = group.body;
            }
            _phaseBodies[i] = body;
            _fillPhaseRows(body, phases[i], rows);
        }
        if (_stepsHeaderState && !_run) {
            _stepsHeaderState.textContent = total > 0
                ? QSPanelWords.count('preview.workflowsStepsReady', total)
                : _t('aiToolsStateIdle');
        }
    }

    /**
     * During a run: phase k's group drawn again from the phase as now resolved (or its AI answer) and
     * opened, the others closed but left as they are — the rows of a phase that ran keep their results.
     */
    function _showPhaseInBatch(k) {
        var phases = _run ? _run.plan : _plan;
        if (!_stepsBody || !phases[k]) return;
        if (phases.length > 1 && _phaseGroups.length !== phases.length) {
            _renderBatch(k);
            return;
        }
        var rows = _phaseRows(phases[k]);
        if (phases.length > 1) {
            var fresh = _renderPhaseGroup(phases[k], rows.steps, rows.effects, true);
            _phaseGroups[k].el.replaceWith(fresh.el);
            _phaseGroups[k] = fresh;
            _phaseBodies[k] = fresh.body;
            _phaseGroups.forEach(function (g, i) { if (i !== k) g.body.hidden = true; });
        } else {
            _stepsBody.replaceChildren();
            _phaseBodies[k] = _stepsBody;
        }
        _fillPhaseRows(_phaseBodies[k], phases[k], rows);
    }
    /** One phase's group in the Batch: its name, where it runs and how many steps, then its rows. */
    function _renderPhaseGroup(phase, steps, effects, open) {
        var el = document.createElement('div');
        el.className = 'preview-contextual-ai-tools__phase';
        var header = document.createElement('button');
        header.type = 'button';
        header.className = 'preview-contextual-ai-tools__phase-header';
        var name = document.createElement('span');
        name.className = 'preview-contextual-ai-tools__phase-name';
        name.textContent = (phase.icon ? phase.icon + ' ' : '') + phase.title;
        header.appendChild(name);
        var meta = document.createElement('span');
        meta.className = 'preview-contextual-ai-tools__phase-meta';
        var kind = phase.kind === 'pre' ? _t('aiToolsKindBefore') : (phase.kind === 'post' ? _t('aiToolsKindAfter') : '');
        var parts = [];
        if (kind) parts.push(kind);
        if (phase.ai) parts.push(_t('aiToolsKindAi'));
        parts.push(QSPanelWords.count('preview.workflowsReviewSteps', steps.length));
        var warned = _warnedEffects(effects);
        var warnWord = _effectWord(warned);
        if (warnWord === 'delete') parts.push(_t('aiToolsEffectDeletes'));
        else if (warnWord === 'act') {
            var seen = {};
            warned.forEach(function (e) {
                var does = _effectDoes(e);
                if (does && !seen[e.command]) parts.push(_t(does.word));
                if (does) seen[e.command] = true;
            });
        } else if (warnWord === 'change') parts.push(_t('aiToolsEffectChanges'));
        meta.textContent = parts.join(' · ');
        header.appendChild(meta);
        var body = document.createElement('div');
        body.className = 'preview-contextual-ai-tools__phase-body';
        body.hidden = !open;
        header.addEventListener('click', function () { body.hidden = !body.hidden; });
        el.appendChild(header);
        el.appendChild(body);
        return { el: el, body: body };
    }

    // ──────────────────────────── Step row UI ───────────────────────────

    /** A step's row; `effect` (the server's, or null) marks what it deletes or changes. */
    function _renderStepRow(cmd, effect) {
        var commandName = cmd.command || cmd.name || _t('workflowsStepUnnamed');
        var hasParams = cmd.params && typeof cmd.params === 'object' && Object.keys(cmd.params).length > 0;

        var el = document.createElement('div');
        el.className = 'preview-contextual-ai-tools__step';

        var icon = document.createElement('span');
        icon.className = 'preview-contextual-ai-tools__step-icon';
        icon.textContent = '⚪';

        var body = document.createElement('div');
        body.className = 'preview-contextual-ai-tools__step-body';

        var name = document.createElement('span');
        name.className = 'preview-contextual-ai-tools__step-name';
        name.textContent = commandName;
        body.appendChild(name);

        if (effect && (effect.effect === 'delete' || effect.effect === 'change')) {
            var tag = document.createElement('span');
            tag.className = 'preview-contextual-ai-tools__step-effect preview-contextual-ai-tools__step-effect--' + effect.effect;
            var stepDoes = _effectDoes(effect);
            tag.textContent = stepDoes ? _t(stepDoes.word)
                : (effect.effect === 'delete' ? _t('aiToolsEffectDeletes') : _t('aiToolsEffectChanges'));
            body.appendChild(tag);
        }

        if (hasParams) {
            var summary = document.createElement('span');
            summary.className = 'preview-contextual-ai-tools__step-params-summary';
            summary.textContent = _summarizeParams(cmd.params);
            body.appendChild(summary);
        }

        var status = document.createElement('span');
        status.className = 'preview-contextual-ai-tools__step-status';
        status.textContent = _t('aiToolsStepPending');
        body.appendChild(status);

        var paramsFull = null;
        if (hasParams) {
            paramsFull = document.createElement('pre');
            paramsFull.className = 'preview-contextual-ai-tools__step-params-full';
            paramsFull.textContent = JSON.stringify(cmd.params, null, 2);
            paramsFull.style.display = 'none';
            body.appendChild(paramsFull);
        }

        el.appendChild(icon);
        el.appendChild(body);

        if (hasParams) {
            el.addEventListener('click', function () {
                paramsFull.style.display = paramsFull.style.display === 'none' ? '' : 'none';
            });
        }
        return { el: el, icon: icon, status: status };
    }

    function _summarizeParams(params) {
        if (!params || typeof params !== 'object') return '';
        var keys = Object.keys(params);
        if (keys.length === 0) return '';
        var parts = [];
        for (var i = 0; i < keys.length; i++) {
            var v = params[keys[i]];
            var val;
            if (typeof v === 'string') {
                val = '"' + (v.length > 30 ? v.substring(0, 30) + '…' : v) + '"';
            } else if (typeof v === 'object' && v !== null) {
                val = Array.isArray(v) ? '[' + v.length + ']' : '{…}';
            } else {
                val = String(v);
            }
            parts.push(keys[i] + ': ' + val);
        }
        return parts.join(' • ');
    }

    function _updateStepRow(row, state, message) {
        if (!row) return;
        row.el.classList.remove(
            'preview-contextual-ai-tools__step--ok',
            'preview-contextual-ai-tools__step--error',
            'preview-contextual-ai-tools__step--running'
        );
        if (state === 'running') {
            row.icon.textContent = '⏳';
            row.status.textContent = _t('aiToolsStepRunning');
            row.el.classList.add('preview-contextual-ai-tools__step--running');
        } else if (state === 'retry') {
            // A step sent again (its retryOn): the row says which attempt and when.
            row.icon.textContent = '⏳';
            row.status.textContent = message || '';
            row.el.classList.add('preview-contextual-ai-tools__step--running');
        } else if (state === 'ok') {
            row.icon.textContent = '✅';
            row.status.textContent = message || '';
            row.el.classList.add('preview-contextual-ai-tools__step--ok');
        } else if (state === 'error') {
            row.icon.textContent = '❌';
            // The step's error after the word; a failure that gave none is said in the one word.
            row.status.textContent = message ? _t('aiToolsStepError', { error: message }) : _t('workflowsStepFailed');
            row.el.classList.add('preview-contextual-ai-tools__step--error');
        }
    }

    // ──────────────────────────── Validation ────────────────────────────

    function _validateAllParams() {
        var errors = {};
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            if (!_isParamVisible(p)) continue;
            var msgs = _validateParam(p, _paramValues[p.id]);
            if (msgs.length > 0) errors[p.id] = msgs;
        }
        return errors;
    }

    function _validateParam(param, value) {
        var msgs = [];
        var v = param.validation || {};

        if (param.required) {
            var isEmpty = value == null
                || value === ''
                || (Array.isArray(value) && value.length === 0);
            if (isEmpty) {
                msgs.push(_t('aiToolsValidationRequired'));
                return msgs;
            }
        }

        // Skip remaining checks on empty values (the `required` check above
        // is the only one that fails on empty; other rules don't apply).
        if (value == null || value === '' || (Array.isArray(value) && value.length === 0)) {
            return msgs;
        }

        if (Array.isArray(value)) {
            if (v.minItems != null && value.length < v.minItems) {
                msgs.push(_t('aiToolsValidationMinItems', { n: QSPanelWords.number(Number(v.minItems)) }));
            }
            if (v.maxItems != null && value.length > v.maxItems) {
                msgs.push(_t('aiToolsValidationMaxItems', { n: QSPanelWords.number(Number(v.maxItems)) }));
            }
        }

        if (typeof value === 'string') {
            if (v.minLength != null && value.length < v.minLength) {
                msgs.push(QSPanelWords.count('preview.workflowsValidationMinLength', Number(v.minLength)));
            }
            if (v.maxLength != null && value.length > v.maxLength) {
                msgs.push(QSPanelWords.count('preview.workflowsValidationMaxLength', Number(v.maxLength)));
            }
            if (v.pattern) {
                try {
                    var re = new RegExp('^(?:' + v.pattern + ')$');
                    if (!re.test(value)) {
                        msgs.push(_t('aiToolsValidationPattern'));
                    }
                } catch (e) {
                    console.warn('[PreviewAiTools] invalid pattern in workflow:', v.pattern, e);
                }
            }
        }

        if (typeof value === 'number'
            || (typeof value === 'string' && value !== '' && !isNaN(Number(value)))) {
            var num = Number(value);
            // A bound is shown whole, every decimal the workflow's file gives it.
            if (v.min != null && num < v.min) {
                msgs.push(_t('aiToolsValidationMin', { n: QSPanelWords.number(Number(v.min), { maximumFractionDigits: 20 }) }));
            }
            if (v.max != null && num > v.max) {
                msgs.push(_t('aiToolsValidationMax', { n: QSPanelWords.number(Number(v.max), { maximumFractionDigits: 20 }) }));
            }
        }

        return msgs;
    }

    function _renderParamErrors() {
        if (!_runnerView) return;
        _paramErrors = _validateAllParams();
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            var wrap = _runnerView.querySelector('[data-param-id="' + p.id + '"]');
            if (!wrap) continue;
            var errorEl = wrap.querySelector('.preview-contextual-ai-tools__param-error');
            var msgs = _paramErrors[p.id];
            if (msgs && msgs.length > 0) {
                wrap.classList.add('preview-contextual-ai-tools__param--invalid');
                if (errorEl) {
                    errorEl.textContent = msgs.join(' · ');
                    errorEl.style.display = '';
                }
            } else {
                wrap.classList.remove('preview-contextual-ai-tools__param--invalid');
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.style.display = 'none';
                }
            }
        }
        _updateActionStates();
    }

    function _updateActionStates() {
        var hasParamErrors = Object.keys(_paramErrors).length > 0;

        if (_primaryActionBtn) {
            if (_executing) {
                _primaryActionBtn.disabled = true;
                _primaryActionBtn.title = '';
            } else if (hasParamErrors) {
                _primaryActionBtn.disabled = true;
                _primaryActionBtn.title = _t('aiToolsRunBlockedTooltip');
                _primaryActionBtn.textContent = _computePrimaryLabel();
            } else {
                _primaryActionBtn.disabled = false;
                _primaryActionBtn.title = '';
                _primaryActionBtn.textContent = _computePrimaryLabel();
            }
        }
        if (_secondaryActionBtn) {
            _secondaryActionBtn.disabled = _executing || hasParamErrors;
        }
        var target = _answerTargetIndex();
        if (_executeBtnEl) {
            // The answer's Execute: while an answer that parses is ready for a phase that can take it and
            // the switch is off (on, the grace timer runs it). Its label says what the answer does.
            var ready = target !== null && _aiResponseStatus.type === 'ok'
                && _currentCommands && _currentCommands.length > 0;
            // An answer put together from several waits for its Execute, whatever the switch.
            _executeBtnEl.style.display = ready && (!_autoContinue() || _answerParts > 1) ? '' : 'none';
            var warned = _answerPhase ? _warnedEffects(_answerPhase.effects) : [];
            var deletes = warned.some(function (e) { return e.effect === 'delete'; });
            var word = _effectWord(warned);
            _executeBtnEl.textContent = word === 'delete' ? _t('aiToolsConfirmRunDelete')
                : (word === 'act' ? _t('aiToolsConfirmRunAct')
                    : (word === 'change' ? _t('aiToolsConfirmRunChange') : _t('aiToolsActionExecute')));
            _executeBtnEl.className = deletes
                ? 'preview-contextual-ai-tools__action-btn--primary preview-contextual-ai-tools__action-btn--danger'
                : 'preview-contextual-ai-tools__action-btn--primary';
            // Not before the answer is classified: the label must say what it does. Not when nothing in it can run.
            _executeBtnEl.disabled = hasParamErrors || !_answerPhase || (_answerPhase.steps || []).length === 0
                || (_executing && !_answerWaiter);
        }
        if (_aiResponseTextarea) {
            // An answer is pasted only when a phase can take it: before the run when its first phase is
            // the AI one, else once the run reaches that phase.
            var open = target !== null || (_run === null && !_executing && _firstPhaseIsAi());
            _aiResponseTextarea.readOnly = !open;
            _aiResponseTextarea.placeholder = !open
                ? _t('aiToolsAnswerAfterRun')
                : (_continuation
                    ? _t('aiToolsContinueWaiting', { n: QSPanelWords.number(_continuation.kept.length) })
                    : _t('aiToolsAiResponsePlaceholder'));
        }
        _refreshRecovery();
    }

    /**
     * The primary button's label: Run for a run with no AI phase, or one whose AI phase comes after
     * others (they run first); for a run that starts with its AI phase, Generate prompt with no key, else
     * Run with AI / Send to AI by the switch.
     */
    function _computePrimaryLabel() {
        if (!_chainHasAi()) return _t('aiToolsActionRun');
        var conn = _getDefaultConnection();
        if (!conn) return _firstPhaseIsAi() ? _t('aiToolsActionGeneratePrompt') : _t('aiToolsActionRun');
        return _autoContinue() ? _t('aiToolsActionRunWithAi') : _t('aiToolsActionSendToAi');
    }

    function _morphPrimary(label) {
        if (!_primaryActionBtn) return;
        _primaryActionBtn.textContent = label;
    }

    // ──────────────────────────── Copy ──────────────────────────────────

    function _onCopyPromptClick() {
        var text = _generatedPrompt || (_generalPromptTextarea && _generalPromptTextarea.value) || '';
        if (!text) return;
        var done = function () {
            if (!_copyBtn) return;
            var orig = _copyBtn.textContent;
            _copyBtn.textContent = _t('aiToolsCopied');
            setTimeout(function () {
                if (_copyBtn) _copyBtn.textContent = orig;
            }, 1200);
        };
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
            } else if (_generalPromptTextarea) {
                _generalPromptTextarea.select();
                document.execCommand('copy');
                done();
            }
        } catch (e) {
            console.warn('[PreviewAiTools] copy failed:', e);
        }
    }

    // ──────────────────────────── A cut answer: Continue, Send again ─────
    //
    // An answer that cannot run (cut at the output limit, broken off, not whole JSON) may still hold
    // commands that arrived whole. Continue the answer keeps them and asks the AI only for the rest,
    // from the command that was cut: sent with the connection's key, after the prompt and the cut
    // answer given as the model's own turn, or copied to paste to an AI assistant. The reply is its own
    // JSON, joined to the kept commands; an answer put together so never runs on its own, even with
    // Run without stopping on. Send again sends the same prompt once more, for a new answer.

    /** One of the waiting bar's ways to recover an answer, drawn hidden until it applies. */
    function _renderRecoveryButton(name, kind, label, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = kind ? 'admin-btn ' + kind : 'admin-btn';
        btn.dataset.recovery = name;
        btn.textContent = label;
        btn.style.display = 'none';
        btn.addEventListener('click', onClick);
        return btn;
    }

    /** The waiting bar's recovery buttons shown as they apply (never while a send is under way). */
    function _refreshRecovery() {
        if (!_recoveryEls) return;
        var plan = _continuePlan();
        _recoveryEls.continueBtn.style.display = plan ? '' : 'none';
        _recoveryEls.continueBtn.title = plan
            ? _t('aiToolsContinueAnswerTitle', { n: QSPanelWords.number(plan.kept.length) })
            : '';
        _recoveryEls.sendAgainBtn.style.display = _canSendAgain() ? '' : 'none';
    }

    /** Send again applies while the run waits for this answer and sends with a key, no send under way. */
    function _canSendAgain() {
        return !!(_answerWaiter && !_aiSending && _run && _run.send && _getDefaultConnection());
    }

    /**
     * What Continue the answer would ask for: the commands kept so far (an earlier part's, then the
     * whole ones of the answer in AI response) and the cut one's text. null while nothing is kept or a
     * send is under way, for an answer that is ready or whole, and for one the provider stopped itself.
     */
    function _continuePlan() {
        if (!_answerWaiter || _aiSending) return null;
        if (_aiResponseStatus.type !== 'error' || _aiResponseStatus.empty) return null;
        if (_aiResponseStatus.cause === 'stopped' || _aiResponseStatus.cause === 'readBack') return null;
        var got = _salvageAnswer(_aiResponseText) || { whole: [], cut: '' };
        var kept = _continuation ? _joinCommands(_continuation.kept, got.whole) : got.whole;
        if (kept.length === 0) return null;
        var cut = got.whole.length > 0 || got.cut ? got.cut : (_continuation ? _continuation.cut : '');
        return { kept: kept, cut: cut };
    }

    /** Continue the answer: the commands that arrived whole kept, the AI asked for the rest (sent with the key, else copied). */
    function _onContinueAnswerClick() {
        var plan = _continuePlan();
        if (!plan) return;
        var partial = '{"commands": [' + plan.kept.map(function (c) { return JSON.stringify(c); }).join(', ')
            + (plan.cut ? ', ' + plan.cut : '');
        var request = _continuationRequest(plan);
        if (_run) _tallyAnswer(_run, _run.index, _aiResponseText.length);
        _continuation = { kept: plan.kept, cut: plan.cut, parts: (_continuation ? _continuation.parts : 1) + 1 };
        _cancelAutoExecute();
        _aiResponseText = '';
        if (_aiResponseTextarea) _aiResponseTextarea.value = '';
        _aiResponseStatus = { type: 'idle' };
        _currentCommands = [];
        _answerPhase = null;
        _updateAiResponseStatusUI();
        var conn = _getDefaultConnection();
        if (_run && _run.send && conn) {
            _sendToAi(conn, { partial: partial, request: request });
        } else {
            if (_generalPromptTextarea) _generalPromptTextarea.value = request;
            _focusGeneralPrompt();
            _autoCopyPromptWithToast(request, _t('aiToolsContinueCopied'));
            if (_run) _tallyPrompt(_run, _run.index, request.length);
        }
        if (_run) _showPhaseInBatch(_run.index);
        _updateActionStates();
    }

    /** Send again: the same prompt sent for a new answer; whatever was kept of the last one is dropped. */
    function _onSendAgainClick() {
        if (!_canSendAgain()) return;
        _continuation = null;
        _answerParts = 0;
        if (_generalPromptTextarea) _generalPromptTextarea.value = _generatedPrompt;
        _currentCommands = [];
        _answerPhase = null;
        _sendToAi(_getDefaultConnection());
    }

    /**
     * What Continue asks the AI: the commands still missing, from the one that was cut, as one JSON object. The
     * count is written as digits, as the rest of a prompt is; the cut command's text, the AI's own, is filled last.
     */
    function _continuationRequest(plan) {
        var n = String(plan.kept.length);
        if (!plan.cut) {
            return _t('aiToolsContinueRequestBoundary', { n: n });
        }
        var cut = plan.cut.length > 240 ? plan.cut.slice(0, 240) + '…' : plan.cut;
        return _t('aiToolsContinueRequest', { n: n, cut: cut });
    }

    /** Where an answer's list of commands begins ({"commands": [ … ] or a bare [ … ]), or -1. */
    function _commandsListStart(text) {
        var lead = text.search(/\S/);
        if (lead !== -1 && text.charAt(lead) === '[') return lead;
        var m = /"commands"\s*:\s*\[/.exec(text);
        return m ? m.index + m[0].length - 1 : -1;
    }

    /**
     * What a cut answer still holds: the commands that arrived whole, in order, and the text of the one
     * that was cut ('' when the cut fell between two). Brackets inside strings and escaped quotes are
     * not taken for the list's own. null when no list of commands begins.
     */
    function _salvageAnswer(text) {
        var s = String(text || '');
        var start = _commandsListStart(s);
        if (start === -1) return null;
        var whole = [];
        var depth = 0;
        var inString = false;
        var escaped = false;
        var from = start + 1;
        for (var i = from; i < s.length; i++) {
            var ch = s.charAt(i);
            if (inString) {
                if (escaped) escaped = false;
                else if (ch === '\\') escaped = true;
                else if (ch === '"') inString = false;
                continue;
            }
            if (ch === '"') {
                inString = true;
            } else if (ch === '{' || ch === '[') {
                depth++;
            } else if ((ch === '}' || ch === ']') && depth > 0) {
                depth--;
            } else if (depth === 0 && (ch === ',' || ch === ']')) {
                var piece = s.slice(from, i);
                if (ch === ']' && !piece.trim()) return { whole: whole, cut: '' };
                var command = _wholeCommand(piece);
                if (!command) break;
                whole.push(command);
                from = i + 1;
                if (ch === ']') return { whole: whole, cut: '' };
            }
        }
        // The last command may have arrived whole, only the list's end missing.
        var rest = s.slice(from).trim();
        var last = rest ? _wholeCommand(rest) : null;
        if (last) {
            whole.push(last);
            rest = '';
        }
        return { whole: whole, cut: rest };
    }

    /** A command that arrived whole: its text parses to an object with a command name; else null. */
    function _wholeCommand(piece) {
        try {
            var c = JSON.parse(piece);
            return c && typeof c === 'object' && !Array.isArray(c) && typeof c.command === 'string' ? c : null;
        } catch (_e) {
            return null;
        }
    }

    /** The commands of a reply that parses ({"commands": [...]} or a bare list), even none; else null. */
    function _replyCommands(text) {
        try {
            var parsed = JSON.parse(String(text || '').trim());
            if (Array.isArray(parsed)) return parsed;
            return parsed && Array.isArray(parsed.commands) ? parsed.commands : null;
        } catch (_e) {
            return null;
        }
    }

    /** The kept commands and the rest joined: the rest's first commands that repeat the kept ones' last are dropped. */
    function _joinCommands(kept, more) {
        var a = kept.map(function (c) { return JSON.stringify(c); });
        var b = more.map(function (c) { return JSON.stringify(c); });
        for (var k = Math.min(a.length, b.length); k > 0; k--) {
            var same = true;
            for (var i = 0; i < k && same; i++) same = a[a.length - k + i] === b[i];
            if (same) return kept.concat(more.slice(k));
        }
        return kept.concat(more);
    }

    // ──────────────────────────── AI response parse ─────────────────────

    function _onAiResponseInput() {
        _aiResponseText = _aiResponseTextarea ? _aiResponseTextarea.value : '';
        _onAnswerChanged();
    }

    /**
     * An answer changed — pasted, edited, or sent by the AI: checked, its commands read, then
     * classified by the server (what they delete or change), which wakes its Execute or its grace timer.
     */
    function _onAnswerChanged() {
        // The rest of a cut answer: joined to the commands kept, the whole answer shown in its place.
        var more = _continuation ? _replyCommands(_aiResponseText) : null;
        if (more) {
            if (_run) _tallyAnswer(_run, _run.index, _aiResponseText.length);
            _answerParts = _continuation.parts;
            _aiResponseText = JSON.stringify({ commands: _joinCommands(_continuation.kept, more) }, null, 2);
            _continuation = null;
            if (_aiResponseTextarea) _aiResponseTextarea.value = _aiResponseText;
            if (_generalPromptTextarea) _generalPromptTextarea.value = _generatedPrompt;
        }
        _aiResponseStatus = _validateAiResponse(_aiResponseText);
        _syncCommandsFromAiResponse();
        _answerPhase = null;
        _answerSeq++;
        _cancelAutoExecute();
        _updateAiResponseStatusUI();
        var target = _answerTargetIndex();
        if (_aiResponseStatus.type === 'ok' && target !== null) _classifyAnswer();
        if (target !== null) {
            if (_run) _showPhaseInBatch(target); else _renderBatch(target);
        }
        _updateActionStates();
    }

    /**
     * The phase an answer would run for: the AI phase a run is waiting on, or, with no run under way,
     * a first phase that is an AI one; null when no phase can take an answer now.
     */
    function _answerTargetIndex() {
        if (_run) return _answerWaiter ? _run.index : null;
        if (_executing || !_firstPhaseIsAi()) return null;
        return 0;
    }

    /** The server says what the answer's commands delete or change (an older answer's reply is dropped). */
    function _classifyAnswer() {
        var index = _answerTargetIndex();
        if (index === null) return;
        var seq = ++_answerSeq;
        var params = _run ? _run.params : _paramValues;
        var extra = { answer: _currentCommands.slice() };
        if (_run && _run.plan[index]) extra.key = _run.plan[index].key;
        _fetchPhase(params, index, extra).then(function (phase) {
            if (seq !== _answerSeq) return;
            _answerPhase = phase;
            _updateAiResponseStatusUI();
            if (_run) _showPhaseInBatch(index); else _renderBatch(index);
            _updateActionStates();
            _scheduleAutoExecute();
        }).catch(function (err) {
            if (seq !== _answerSeq) return;
            _answerPhase = null;
            // The run's plan changed while it waited for this answer: the run ends there.
            if (err && err.planChanged && _answerWaiter) {
                var waiter = _answerWaiter;
                _answerWaiter = null;
                _cancelAutoExecute();
                waiter.resolve({ planChanged: true });
                return;
            }
            _aiResponseStatus = { type: 'error', message: (err && err.message) || String(err), plain: true, cause: 'readBack' };
            _updateAiResponseStatusUI();
            _updateActionStates();
        });
    }

    function _syncCommandsFromAiResponse() {
        if (_aiResponseStatus.type === 'ok' && _aiResponseStatus.parsed) {
            var parsed = _aiResponseStatus.parsed;
            _currentCommands = (parsed && Array.isArray(parsed.commands))
                ? parsed.commands
                : (Array.isArray(parsed) ? parsed : []);
        } else {
            _currentCommands = [];
        }
    }

    function _cancelAutoExecute() {
        if (_pasteAutoExecTimer) {
            clearTimeout(_pasteAutoExecTimer);
            _pasteAutoExecTimer = null;
        }
        if (_autoExecHintEl) _autoExecHintEl.style.display = 'none';
    }

    /**
     * "Run without stopping": a 1.5 s grace before an answer runs on its own — a pasted one and one the
     * AI sent alike — once the server has said what it does. Any edit of the answer cancels it; the
     * edited answer, once classified, starts it again.
     */
    function _scheduleAutoExecute() {
        _cancelAutoExecute();
        if (!_autoContinue()) return;
        if (_answerParts > 1) return;
        if (_answerTargetIndex() === null || !_answerPhase) return;
        if (_aiResponseStatus.type !== 'ok') return;
        if (!_currentCommands || _currentCommands.length === 0) return;
        if ((_answerPhase.steps || []).length === 0) return;

        if (_autoExecHintEl) {
            _autoExecHintEl.textContent = _t('aiToolsAutoExecHint');
            _autoExecHintEl.style.display = '';
        }
        _pasteAutoExecTimer = setTimeout(function () {
            _pasteAutoExecTimer = null;
            if (_autoExecHintEl) _autoExecHintEl.style.display = 'none';
            if (_aiResponseStatus.type !== 'ok' || !_answerPhase) return;
            _onExecuteClick();
        }, 1500);
    }

    function _validateAiResponse(text) {
        var trimmed = String(text || '').trim();
        if (!trimmed) return { type: 'idle' };
        var parsed;
        try {
            parsed = JSON.parse(trimmed);
        } catch (e) {
            return { type: 'error', message: e && e.message ? e.message : String(e) };
        }
        var commands = null;
        if (Array.isArray(parsed)) {
            commands = parsed;
        } else if (parsed && Array.isArray(parsed.commands)) {
            commands = parsed.commands;
        }
        if (!commands || commands.length === 0) {
            return { type: 'error', message: _t('aiToolsAiResponseEmpty'), empty: true };
        }
        return { type: 'ok', commandCount: commands.length, parsed: parsed };
    }

    function _updateAiResponseStatusUI() {
        if (!_aiResponseStatusEl) return;
        _aiResponseStatusEl.classList.remove(
            'preview-contextual-ai-tools__ai-response-status--ok',
            'preview-contextual-ai-tools__ai-response-status--error'
        );
        if (_aiResponseStatus.type === 'idle') {
            _aiResponseStatusEl.textContent = '';
        } else if (_aiResponseStatus.type === 'ok') {
            // Once the server has read the answer, what runs is what it kept.
            var runnable = _answerPhase ? (_answerPhase.steps || []).length : _aiResponseStatus.commandCount;
            var refused = (_answerPhase && _answerPhase.refused) || [];
            _aiResponseStatusEl.textContent = runnable === 0 && refused.length > 0
                ? _t('aiToolsAnswerNothingRuns')
                : (_answerParts > 1
                    ? QSPanelWords.count('preview.workflowsAssembledReady', runnable, { parts: QSPanelWords.number(_answerParts) })
                    : QSPanelWords.count('preview.workflowsAiResponseValid', runnable));
            _aiResponseStatusEl.classList.add(runnable === 0 && refused.length > 0
                ? 'preview-contextual-ai-tools__ai-response-status--error'
                : 'preview-contextual-ai-tools__ai-response-status--ok');
            if (refused.length > 0) _aiResponseStatusEl.appendChild(_renderAnswerLeftOut(refused));
        } else {
            // An answer that does not parse says so; a failure to get one (a send, a reading) is said as it is.
            _aiResponseStatusEl.textContent = _aiResponseStatus.empty || _aiResponseStatus.plain
                ? _aiResponseStatus.message
                : _t('aiToolsAiResponseInvalid', { error: _aiResponseStatus.message });
            _aiResponseStatusEl.classList.add('preview-contextual-ai-tools__ai-response-status--error');
        }
    }

    /** What the server left out of an answer, one line per reason (its word by the rest of its key's path), naming what it refused. */
    function _renderAnswerLeftOut(refused) {
        var words = {
            command: 'aiToolsAnswerLeftOutCommand',
            selector: 'aiToolsAnswerLeftOutSelector',
            property: 'aiToolsAnswerLeftOutProperty'
        };
        var box = document.createElement('div');
        box.className = 'preview-contextual-ai-tools__ai-response-left-out';
        Object.keys(words).forEach(function (reason) {
            var targets = refused.filter(function (r) { return r.reason === reason; })
                .map(function (r) { return r.target; })
                .filter(function (target, i, all) { return all.indexOf(target) === i; });
            if (targets.length === 0) return;
            var line = document.createElement('div');
            line.textContent = _t(words[reason], { list: targets.join(', ') });
            box.appendChild(line);
        });
        return box;
    }

    // ──────────────────────────── BYOK detection ────────────────────────

    function _getDefaultConnection() {
        try {
            var store = window.QSConnectionsStore;
            if (!store || typeof store.getActive !== 'function') return null;
            return store.getActive() || null;
        } catch (e) {
            return null;
        }
    }

    // ──────────────────────────── Boot ──────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        enter: enter,
        leave: leave,
    };
})();
