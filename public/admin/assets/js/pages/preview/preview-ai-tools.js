/**
 * preview-ai-tools.js — AI tools panel.
 *
 * In-editor AI tools panel:
 *   list view  → workflow picker (search + tag filter + show-more)
 *   runner view → spec runner (3 zones: INPUTS / AI EXCHANGE / EXECUTION)
 *                  + footer line (Model + Touches)
 *
 * Activation: sidebar "AI tools" → setMode('ai-tools') in preview.js
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

    var PAGE_SIZE = 10;
    var SEARCH_DEBOUNCE_MS = 180;
    var TAG_SHOW_LIMIT = 6;

    // ──────────────────────────── State ─────────────────────────────────

    var _entered = false;

    // List view state
    var _allWorkflows = [];
    var _filteredWorkflows = [];
    var _visibleCount = PAGE_SIZE;
    var _activeTags = new Set();
    var _tagMatchMode = 'any';
    var _tagsExpanded = false;
    var _searchTerm = '';
    var _searchDebounceId = null;

    // Runner view state
    var _activeWorkflowSummary = null;
    var _activeWorkflowSpec = null;
    var _activeTemplate = null;
    var _fetchedData = {};
    var _paramValues = {};
    var _requests = {};                      // "Your prompt" of each AI phase, by its key (_requestKey())
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
    var _tagModeRow = null;
    var _tagModeAnyBtn = null;
    var _tagModeAllBtn = null;
    var _tagsContainer = null;
    var _allList = null;
    var _loadingEl = null;
    var _showMoreBtn = null;
    var _emptyEl = null;
    var _backupBtnEl = null;

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

    // ──────────────────────────── i18n ──────────────────────────────────

    function _t(key, fallback) {
        var panel = (window.PreviewConfig
                     && window.PreviewConfig.i18nPanels
                     && window.PreviewConfig.i18nPanels.aiTools) || {};
        return panel[key] || fallback;
    }

    // One of the panel-wide words layout.php ships (QuickSiteUtils.tCommon): an unset one shows its key.
    function _commonWord(name) {
        var utils = window.QuickSiteUtils;
        return utils && typeof utils.tCommon === 'function' ? utils.tCommon(name) : 'common.' + name;
    }

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
        _tagModeRow = document.getElementById('ai-tools-tag-mode-row');
        _tagModeAnyBtn = document.getElementById('ai-tools-tag-mode-any');
        _tagModeAllBtn = document.getElementById('ai-tools-tag-mode-all');
        _tagsContainer = document.getElementById('ai-tools-tags');
        _allList = document.getElementById('ai-tools-all-list');
        _loadingEl = document.getElementById('ai-tools-loading');
        _showMoreBtn = document.getElementById('ai-tools-show-more');
        _emptyEl = document.getElementById('ai-tools-empty');
        _backupBtnEl = document.getElementById('ai-tools-backup-btn');

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
        if (_showMoreBtn) {
            _showMoreBtn.addEventListener('click', _onShowMore);
        }
        if (_tagModeAnyBtn) {
            _tagModeAnyBtn.addEventListener('click', function () { _setTagMatchMode('any'); });
        }
        if (_tagModeAllBtn) {
            _tagModeAllBtn.addEventListener('click', function () { _setTagMatchMode('all'); });
        }
        if (_backupBtnEl) {
            _backupBtnEl.addEventListener('click', _onBackupClick);
        }
    }

    function _onBackupClick() {
        if (!_backupBtnEl) return;
        var origLabel = _backupBtnEl.textContent;
        _backupBtnEl.disabled = true;
        _backupBtnEl.textContent = _t('backupCreating', 'Creating backup…');
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
                toaster(_t('backupSuccess', 'Backup created ({path})').replace('{path}', path || '✓'), 'success');
            }
        }).catch(function (err) {
            if (toaster) {
                toaster(_t('backupFailed', 'Backup failed: {error}').replace('{error}', (err && err.message) || String(err)), 'error');
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
        _renderTags();
        _applyFilters();
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
        var raw = (window.PreviewConfig && window.PreviewConfig.aiToolsWorkflows) || [];
        _allWorkflows = Array.isArray(raw) ? raw.slice() : [];
    }

    function _uniqueTags() {
        var counts = Object.create(null);
        for (var i = 0; i < _allWorkflows.length; i++) {
            var tags = _allWorkflows[i].tags || [];
            for (var j = 0; j < tags.length; j++) {
                var t = String(tags[j]);
                counts[t] = (counts[t] || 0) + 1;
            }
        }
        return Object.keys(counts).sort(function (a, b) {
            if (counts[b] !== counts[a]) return counts[b] - counts[a];
            return a.localeCompare(b);
        });
    }

    // ──────────────────────────── Filters ───────────────────────────────

    function _applyFilters() {
        var term = _searchTerm;
        var mode = _tagMatchMode;
        var tagFilter = _activeTags;
        _filteredWorkflows = _allWorkflows.filter(function (w) {
            if (term) {
                var hay = (w.title + ' ' + w.description + ' ' + (w.tags || []).join(' ')).toLowerCase();
                if (hay.indexOf(term) === -1) return false;
            }
            if (tagFilter.size > 0) {
                var wTags = w.tags || [];
                if (mode === 'all') {
                    var iter = tagFilter.values();
                    var step = iter.next();
                    while (!step.done) {
                        if (wTags.indexOf(step.value) === -1) return false;
                        step = iter.next();
                    }
                    return true;
                }
                for (var i = 0; i < wTags.length; i++) {
                    if (tagFilter.has(wTags[i])) return true;
                }
                return false;
            }
            return true;
        });
        _visibleCount = PAGE_SIZE;
        _renderCards();
    }

    function _onSearchInput(e) {
        var raw = String(e.target.value || '').trim().toLowerCase();
        if (_searchDebounceId) clearTimeout(_searchDebounceId);
        _searchDebounceId = setTimeout(function () {
            _searchDebounceId = null;
            _searchTerm = raw;
            _applyFilters();
        }, SEARCH_DEBOUNCE_MS);
    }

    function _onTagClick(tag) {
        if (_activeTags.has(tag)) {
            _activeTags.delete(tag);
        } else {
            _activeTags.add(tag);
        }
        _renderTagModeRow();
        _renderTags();
        _applyFilters();
    }

    function _onShowMore() {
        _visibleCount += PAGE_SIZE;
        _renderCards();
    }

    function _setTagMatchMode(mode) {
        if (mode !== 'any' && mode !== 'all') return;
        if (_tagMatchMode === mode) return;
        _tagMatchMode = mode;
        _renderTagModeRow();
        _applyFilters();
    }

    function _toggleTagsExpanded() {
        _tagsExpanded = !_tagsExpanded;
        _renderTags();
    }

    // ──────────────────────────── Render: tag-mode row ──────────────────

    function _renderTagModeRow() {
        if (!_tagModeRow) return;
        _tagModeRow.style.display = _activeTags.size >= 2 ? '' : 'none';
        if (_tagModeAnyBtn) {
            _tagModeAnyBtn.classList.toggle('preview-contextual-ai-tools__tag-mode--active', _tagMatchMode === 'any');
        }
        if (_tagModeAllBtn) {
            _tagModeAllBtn.classList.toggle('preview-contextual-ai-tools__tag-mode--active', _tagMatchMode === 'all');
        }
    }

    // ──────────────────────────── Render: tags ──────────────────────────

    function _renderTags() {
        if (!_tagsContainer) return;
        _tagsContainer.replaceChildren();
        var tags = _uniqueTags();
        if (tags.length === 0) return;

        var visible;
        if (_tagsExpanded || tags.length <= TAG_SHOW_LIMIT) {
            visible = tags;
        } else {
            var top = tags.slice(0, TAG_SHOW_LIMIT);
            var topSet = new Set(top);
            visible = top.slice();
            _activeTags.forEach(function (t) {
                if (!topSet.has(t)) visible.push(t);
            });
        }
        for (var i = 0; i < visible.length; i++) {
            _tagsContainer.appendChild(_renderTagChip(visible[i]));
        }
        if (tags.length > TAG_SHOW_LIMIT) {
            _tagsContainer.appendChild(_renderTagOverflowToggle(tags.length, visible.length));
        }
    }

    function _renderTagChip(tag) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preview-contextual-ai-tools__tag';
        if (_activeTags.has(tag)) {
            btn.classList.add('preview-contextual-ai-tools__tag--active');
        }
        btn.textContent = tag;
        btn.addEventListener('click', function () { _onTagClick(tag); });
        return btn;
    }

    function _renderTagOverflowToggle(total, currentlyVisible) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'preview-contextual-ai-tools__tag-toggle';
        if (_tagsExpanded) {
            btn.textContent = _t('tagShowLess', 'Show less');
        } else {
            var hidden = total - currentlyVisible;
            var template = _t('tagShowMore', '+{n} more');
            btn.textContent = template.replace('{n}', String(hidden));
        }
        btn.addEventListener('click', _toggleTagsExpanded);
        return btn;
    }

    // ──────────────────────────── Render: cards ─────────────────────────

    function _renderCards() {
        if (!_allList) return;
        _allList.replaceChildren();
        if (_loadingEl) _loadingEl.style.display = 'none';

        if (_filteredWorkflows.length === 0) {
            if (_emptyEl) _emptyEl.style.display = '';
            if (_showMoreBtn) _showMoreBtn.style.display = 'none';
            return;
        }
        if (_emptyEl) _emptyEl.style.display = 'none';

        var slice = _filteredWorkflows.slice(0, _visibleCount);
        var lastCategory = null;
        var currentList = null;
        for (var i = 0; i < slice.length; i++) {
            var w = slice[i];
            if (w.category !== lastCategory) {
                var group = document.createElement('div');
                group.className = 'preview-contextual-ai-tools__category-group';
                group.dataset.category = w.category;

                var header = document.createElement('h4');
                header.className = 'preview-contextual-ai-tools__category-header';
                header.textContent = w.categoryLabel || w.category;
                group.appendChild(header);

                currentList = document.createElement('div');
                currentList.className = 'preview-contextual-ai-tools__tool-list';
                group.appendChild(currentList);

                _allList.appendChild(group);
                lastCategory = w.category;
            }
            currentList.appendChild(_renderCard(w));
        }

        var remaining = _filteredWorkflows.length - slice.length;
        if (remaining > 0 && _showMoreBtn) {
            var step = Math.min(PAGE_SIZE, remaining);
            var template = _t('showMoreCount', 'Show {n} more');
            _showMoreBtn.textContent = template.replace('{n}', String(step));
            _showMoreBtn.style.display = '';
        } else if (_showMoreBtn) {
            _showMoreBtn.style.display = 'none';
        }
    }

    function _renderCard(workflow) {
        var card = document.createElement('button');
        card.type = 'button';
        card.className = 'preview-contextual-ai-tools__tool-card';
        card.dataset.id = workflow.id;
        card.addEventListener('click', function () { _onCardClick(workflow); });

        var header = document.createElement('div');
        header.className = 'preview-contextual-ai-tools__tool-card-header';
        var iconEl = document.createElement('span');
        iconEl.className = 'preview-contextual-ai-tools__tool-card-icon';
        iconEl.textContent = workflow.icon || '📋';
        var titleEl = document.createElement('span');
        titleEl.className = 'preview-contextual-ai-tools__tool-card-title';
        titleEl.textContent = workflow.title || workflow.id;
        var badgeEl = document.createElement('span');
        badgeEl.className = 'preview-contextual-ai-tools__tool-card-badge';
        if (workflow.isAI) {
            badgeEl.textContent = '🤖';
            badgeEl.title = _t('aiBadgeTooltip', 'AI workflow');
        } else if (workflow.isManual) {
            badgeEl.textContent = '📦';
            badgeEl.title = _t('manualBadgeTooltip', 'Steps-only workflow');
        } else {
            badgeEl.textContent = '';
        }
        header.appendChild(iconEl);
        header.appendChild(titleEl);
        if (badgeEl.textContent) header.appendChild(badgeEl);
        card.appendChild(header);

        if (workflow.description) {
            var desc = document.createElement('p');
            desc.className = 'preview-contextual-ai-tools__tool-card-desc';
            desc.textContent = workflow.description;
            card.appendChild(desc);
        }

        var meta = document.createElement('div');
        meta.className = 'preview-contextual-ai-tools__tool-card-meta';
        if (workflow.difficultyLabel) {
            var diff = _renderMetaChip(workflow.difficultyLabel);
            diff.classList.add('preview-contextual-ai-tools__tool-card-meta-chip--difficulty-' + workflow.difficulty);
            meta.appendChild(diff);
        }
        if (meta.childNodes.length > 0) card.appendChild(meta);

        return card;
    }

    function _renderMetaChip(text) {
        var chip = document.createElement('span');
        chip.className = 'preview-contextual-ai-tools__tool-card-meta-chip';
        chip.textContent = text;
        return chip;
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
        var headers = { 'Accept': 'application/json' };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        return fetch(url, { headers: headers }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            if (!json || !json.success) throw new Error((json && json.error) || 'Unknown error');
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
            var args = keys.concat(['param', 'return (' + expr + ');']);
            var fn = Function.apply(null, args);
            return !!fn.apply(null, vals.concat([paramValues]));
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
            var keep = of.filterFrom ? _refValues(of.filterFrom) : null;
            if (keep) options = options.filter(function (o) { return keep.indexOf(o.value) !== -1; });
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
        loading.textContent = _t('runnerLoading', 'Loading workflow…');
        _runnerView.appendChild(loading);
    }

    function _renderRunnerError(message) {
        if (!_runnerView) return;
        _destroyParamPickers();
        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(_activeWorkflowSummary || {}));
        var err = document.createElement('div');
        err.className = 'preview-contextual-ai-tools__runner-error';
        var tpl = _t('runnerError', 'Failed to load workflow: {error}');
        err.textContent = tpl.replace('{error}', message);
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
        backBtn.textContent = _t('backToList', '← Back');
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
        var z = _renderZone('inputs', _t('zoneInputs', 'Inputs'));
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
            _secondaryActionBtn.textContent = _t('actionGenerateForCopy', 'Generate for copy');
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
        label.textContent = _t('modelLabel', 'Model:');
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
        var z = _renderZone('exchange', _t('zoneExchange', 'AI exchange'));
        _exchangeHeaderEl = z.section.querySelector('.preview-contextual-ai-tools__zone-header');
        z.body.appendChild(_renderSectionGeneralPrompt(hasBYOK));
        z.body.appendChild(_renderSectionAiResponseTextarea(hasBYOK));
        return z.section;
    }

    function _renderExecutionZone(spec) {
        var z = _renderZone('execution', _t('zoneExecution', 'Run'));
        z.body.appendChild(_renderExecuteRow());
        _runLineEl = document.createElement('p');
        _runLineEl.className = 'preview-contextual-ai-tools__run-line';
        _runLineEl.hidden = true;
        z.body.appendChild(_runLineEl);
        _reviewEl = document.createElement('div');
        _reviewEl.className = 'preview-contextual-ai-tools__review';
        _reviewEl.hidden = true;
        z.body.appendChild(_reviewEl);
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
        var toggle = _renderToggle('aiAutoExecute', _t('runWithoutStoppingLabel', 'Run without stopping'));
        toggle.title = _t('runWithoutStoppingHint', "Executes the AI's commands once its answer is parsed, and goes from one part of a workflow to the next without a review. A part that deletes content is still confirmed.");
        toggles.appendChild(toggle);
        wrap.appendChild(toggles);

        // The AI answer's "Execute" — shown while an answer is ready and the switch is off; its label
        // says what the answer deletes or changes (_updateActionStates()).
        _executeBtnEl = document.createElement('button');
        _executeBtnEl.type = 'button';
        _executeBtnEl.className = 'preview-contextual-ai-tools__action-btn--primary';
        _executeBtnEl.textContent = _t('actionExecute', 'Execute commands');
        _executeBtnEl.style.display = 'none';
        _executeBtnEl.addEventListener('click', _onExecuteClick);
        wrap.appendChild(_executeBtnEl);
        return wrap;
    }

    function _renderFooter(spec, conn, isAI) {
        var footer = document.createElement('div');
        footer.className = 'preview-contextual-ai-tools__footer';
        if (Array.isArray(spec.relatedCommands) && spec.relatedCommands.length > 0) {
            footer.appendChild(_renderFooterItem(_t('footerTouches', 'Touches'), _renderFooterCommands(spec.relatedCommands)));
        }
        return footer;
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

    function _renderFooterModel(connection) {
        var span = document.createElement('span');
        span.className = 'preview-contextual-ai-tools__footer-value';
        if (connection) {
            var name = connection.name || connection.providerType || '';
            var model = connection.model || '';
            span.textContent = model ? (name + ' · ' + model) : name;
        } else {
            span.textContent = _t('sectionModelNone', 'Not configured');
        }
        return span;
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
            title: _t('sectionYourPrompt', 'Your prompt'),
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
        ta.placeholder = _t('yourPromptPlaceholder', 'Describe what you want.');
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
                ? _t('requestShared', "Your prompt — shared by this run's AI phases")
                : _t('requestFor', 'For {name}').replace('{name}', phase.title || phase.workflowId);
            wrap.appendChild(label);
        }
        wrap.appendChild(ta);
        return wrap;
    }

    function _renderSectionParameters(params) {
        var s = _renderCollapsibleSection({
            title: _t('sectionParameters', 'Parameters'),
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
            empty.textContent = _t('selectorEmpty', 'No element selected — click one in the iframe.');
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
            struct.textContent = ' ' + _t('selectorIn', 'in') + ' ' + sel.struct;
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
        ph.textContent = _t('paramOptionsEmpty', 'No options available.');
        return ph;
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
        field.className = 'preview-contextual-ai-tools__param-input preview-contextual-ai-tools__tag-search-field';
        field.id = _paramControlId(param.id);
        field.placeholder = _commonWord('search');
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
        none.textContent = _commonWord('noMatchesFor').replace(':query', query.trim());
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
            title: _t('sectionGeneralPrompt', 'General prompt'),
            defaultOpen: !hasBYOK,
            dataKey: 'general-prompt'
        });

        _generalPromptTextarea = document.createElement('textarea');
        _generalPromptTextarea.className = 'preview-contextual-ai-tools__general-prompt';
        _generalPromptTextarea.placeholder = _t('generalPromptPlaceholder', 'Click the action button to assemble the final prompt.');
        _generalPromptTextarea.value = _generatedPrompt;
        _generalPromptTextarea.readOnly = true;
        s.body.appendChild(_generalPromptTextarea);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__general-prompt-actions';
        _copyBtn = document.createElement('button');
        _copyBtn.type = 'button';
        _copyBtn.className = 'preview-contextual-ai-tools__small-btn';
        _copyBtn.textContent = _t('copyBtn', 'Copy');
        _copyBtn.addEventListener('click', _onCopyPromptClick);
        actions.appendChild(_copyBtn);
        s.body.appendChild(actions);

        // Contextual hint — only shown when no BYOK (user must copy manually).
        if (!hasBYOK) {
            var hint = document.createElement('p');
            hint.className = 'preview-contextual-ai-tools__hint';
            hint.textContent = _t('copyHint', 'Copy this to your AI assistant, then paste the reply below.');
            s.body.appendChild(hint);
        }
        return s.section;
    }

    function _renderSectionAiResponseTextarea(hasBYOK) {
        var s = _renderCollapsibleSection({
            title: _t('sectionAiResponse', 'AI response'),
            defaultOpen: true,
            dataKey: 'ai-response-input'
        });

        _aiResponseTextarea = document.createElement('textarea');
        _aiResponseTextarea.className = 'preview-contextual-ai-tools__ai-response';
        _aiResponseTextarea.placeholder = _t('aiResponsePlaceholder', '{"commands": [...]}');
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
            hint.textContent = _t('pasteHint', 'Paste the JSON reply here.');
            s.body.appendChild(hint);
        }
        return s.section;
    }

    function _renderSectionBatch(spec) {
        // Title is just "Batch" — the raw spec.steps count was misleading
        // (it doesn't reflect forEach expansion). The real command count
        // lives in the state header and updates as the batch resolves.
        var s = _renderCollapsibleSection({
            title: _t('batchTitle', 'Batch'),
            defaultOpen: false,
            stateHeader: _t('stateIdle', 'Idle'),
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
            if (toaster) toaster(okMessage || _t('copiedToast', 'Prompt copied to clipboard'), 'success');
        };
        var fail = function () {
            if (toaster) toaster(_t('copyFailedToast', 'Could not auto-copy — use the Copy button or Ctrl+C'), 'warning');
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
            _aiResponseStatus = { type: 'error', message: 'AI caller not loaded.', plain: true, cause: 'refused' };
            _updateAiResponseStatusUI();
            _updateActionStates();
            return;
        }
        if (!_generatedPrompt) return;

        var model = connection.defaultModel
            || (connection.models && connection.models[0])
            || (connection.model);
        if (!model) {
            _aiResponseStatus = { type: 'error', message: 'No model configured on the active connection.', plain: true, cause: 'refused' };
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
        _morphPrimary(_t('sendingToAi', 'Sending to {model}…').replace('{model}', model));
        _updateActionStates();

        // Elapsed-time ticker so the user has a "still working" cue during
        // the latency before the first chunk arrives (some providers take a
        // noticeable beat to start streaming).
        var startedAt = (window.performance && performance.now)
            ? performance.now() : Date.now();
        var lastChars = 0;
        var sendingTpl = _t('sendingWithElapsed', 'Sending to {model}… ({sec}s)');
        var streamTpl = _t('streamingWithElapsed', 'Receiving from {model}… ({chars} chars, {sec}s)');
        function refreshSendStatus() {
            if (!_aiResponseStatusEl) return;
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            var sec = ((now - startedAt) / 1000).toFixed(1);
            if (lastChars > 0) {
                _aiResponseStatusEl.textContent = streamTpl
                    .replace('{model}', model)
                    .replace('{chars}', String(lastChars))
                    .replace('{sec}', sec);
            } else {
                _aiResponseStatusEl.textContent = sendingTpl
                    .replace('{model}', model)
                    .replace('{sec}', sec);
            }
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
                    ? _t('answerCut', "The answer was cut at the connection's output limit ({n} tokens), so none of it runs. Raise the limit in AI connections, or ask for less.")
                        .replace('{n}', String(window.QSProviderCatalog.maxOutputTokens(connection)))
                    : _t('answerStopped', 'The AI stopped its answer early ({reason}), so none of it runs.')
                        .replace('{reason}', String(result.finishReason)) };
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
                ? _t('answerBrokeOff', 'The answer broke off after {chars} characters: {error}')
                    .replace('{chars}', String(partial.length)).replace('{error}', msg)
                : _t('sendFailed', 'Send failed: {error}').replace('{error}', msg) };
            _currentCommands = [];
            _answerPhase = null;
            if (_run) _showPhaseInBatch(_run.index); else _renderBatch();
            _updateAiResponseStatusUI();
            _updateActionStates();
        } finally {
            clearInterval(statusTickerId);
            _aiSending = false;
            if (_run) _morphPrimary(_t('runActive', 'Running…'));
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

    /** A count as the viewer's locale writes it. */
    function _costNumber(n) {
        return Number(n || 0).toLocaleString();
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
                parts.push(_t('costWithoutCounts', '{n} without counts').replace('{n}', _costNumber(missing)));
            }
            if (failed > 0 && missing + sums.countedCalls > 0) {
                parts.push(_t('costFailed', '{n} failed').replace('{n}', _costNumber(failed)));
            }
            var calls = _costNumber(sums.callCount) + (parts.length > 0 ? ' (' + parts.join(', ') + ')' : '');
            if (sums.countedCalls > 0) {
                lines.push(_t('costTokens', 'Tokens — sent: {sent} · received: {received} · calls: {calls}')
                    .replace('{sent}', _costNumber(sums.sentTokens)).replace('{received}', _costNumber(sums.receivedTokens))
                    .replace('{calls}', calls));
            } else if (missing > 0) {
                lines.push(_t('costTokensNone', 'Tokens — calls: {calls}; the provider sent no counts').replace('{calls}', calls));
            } else {
                lines.push(_t('costTokensFailed', 'Tokens — calls: {calls}; failed, no counts').replace('{calls}', calls));
            }
            lines.push(_t('costCharsKey', 'Characters — sent: {sent} · received: {received}')
                .replace('{sent}', _costNumber(sums.sentChars)).replace('{received}', _costNumber(sums.receivedChars)));
        } else {
            lines.push(_t('costCharsPaste', 'Characters — copied: {sent} · pasted: {received} · prompts: {calls}')
                .replace('{sent}', _costNumber(sums.sentChars)).replace('{received}', _costNumber(sums.receivedChars))
                .replace('{calls}', _costNumber(sums.callCount)));
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
        if (phase.finish === 'length') return _t('costCut', 'its last answer was cut at the output limit');
        if (phase.finish === 'other') return _t('costStopped', 'its last answer was stopped by the AI');
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
        item.className = 'preview-contextual-ai-tools__cost-phase';
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
        _costEl.appendChild(_renderCostLine(_t('costTitle', "This run's AI cost"), 'cost-title'));
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
            toaster(_t('keptBlocking', 'Run or discard the kept steps first.'), 'warning');
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
            last: null
        };
        _run = run;
        _lastCost[run.workflowId] = _newCostTally(run.workflowId);
        _exchangePhase = null;
        _renderCost();
        _executing = true;
        _setRunLocked(true);
        _updateActionStates();
        _morphPrimary(_t('runActive', 'Running…'));
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
                    ? _t('executionDone', 'Done ({ok} succeeded)').replace('{ok}', String(run.ok))
                    : _t('executionDoneWithErrors', 'Done ({ok}/{total} succeeded, {fail} failed)')
                        .replace('{ok}', String(run.ok)).replace('{total}', String(run.ok + run.fail)).replace('{fail}', String(run.fail));
            }
            // A run that had no step to run says so: the project was already as asked.
            if (!run.ran) toaster(_t('noChanges', 'No changes were needed here.'), 'success');
        } catch (err) {
            if (err && err.planChanged) {
                // What ran stays done; nothing is kept: the rest was planned against a plan that no longer holds.
                if (run.plan[run.index]) run.states[run.index] = 'stopped';
                _renderRunLine();
                if (_stepsHeaderState) _stepsHeaderState.textContent = err.message;
                toaster(err.message, 'warning');
            } else {
                console.error('[PreviewAiTools] run failed:', err);
                var failMsg = _t('runFailed', 'The run stopped: {error}').replace('{error}', (err && err.message) || String(err));
                if (_stepsHeaderState) _stepsHeaderState.textContent = failMsg;
                toaster(failMsg, 'error');
            }
        } finally {
            if (_answerWaiter) _answerWaiter = null;
            _run = null;
            _continuation = null;
            _answerParts = 0;
            _executing = false;
            _hideReview();
            _setRunLocked(false);
            _renderYourPromptFields();
            _updateActionStates();
            _renderKept();
            // Notify the rest of the editor that a workflow ran (the miniplayer and others refresh).
            if (run.ran) {
                try {
                    window.dispatchEvent(new CustomEvent('quicksite:workflow-complete'));
                } catch (_e) { /* ignore */ }
            }
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
            if (_exchangeHeaderEl) {
                _exchangeHeaderEl.textContent = _aiPhases().length > 1
                    ? _t('exchangeFor', 'AI exchange — {name}').replace('{name}', phase.title)
                    : _t('zoneExchange', 'AI exchange');
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
                _morphPrimary(_t('sendingToAi', 'Sending to {model}…').replace('{model}', conn.defaultModel || conn.model || ''));
                await _sendToAi(conn);
            } else {
                _morphPrimary(_t('runActive', 'Running…'));
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
        var res = await _executeSteps(phase.steps, phase.effects || [], { body: _phaseBodies[k] || _stepsBody, name: phase.title });
        run.ran = true;
        run.ok += res.ok;
        run.fail += res.fail;
        run.last = { name: phase.title, ok: res.ok, fail: res.fail, total: res.ok + res.fail };
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
                later: run.plan.slice(resumeAt + 1).map(function (p) { return p.title; })
            });
        }
        var msg;
        if (outcome.stop === 'rate') {
            var backAt = (api && api.getWriteRate ? api.getWriteRate().windows || [] : []).reduce(function (m, w) {
                return w.remaining <= 0 ? Math.max(m, w.resetAt) : m;
            }, 0);
            msg = _t('rateStopped', 'Stopped: the change allowance is used up until {time}. The {n} steps not run are kept; run them from here once it is back.')
                .replace('{time}', backAt && api ? api.clockText(backAt) : '')
                .replace('{n}', String(steps.length));
        } else if (outcome.stop === 'parts') {
            msg = _t('rateKeptRest', 'The other {n} steps are kept; run them from here once the allowance is back.')
                .replace('{n}', String(steps.length));
        } else if (outcome.stop === 'abort') {
            msg = _t('stoppedByStep', 'Stopped: {command} failed, and its step stops the run when it fails. The rest is kept: run it from the banner at the top once the cause is fixed.')
                .replace('{command}', outcome.command || '');
        } else {
            msg = _t('stoppedByUser', 'Stopped before {name}. The rest of the run is kept: run it from the banner at the top.')
                .replace('{name}', outcome.phase.title);
        }
        if (_stepsHeaderState) _stepsHeaderState.textContent = msg;
        toaster(msg, outcome.stop === 'user' || outcome.stop === 'parts' ? 'info' : 'warning');
    }

    /**
     * Whether the run may start against the write rate: the steps the plan knows now (an AI phase's
     * are not known yet) counted as one batch is today. Each phase is counted again when it runs.
     */
    function _rateAllowsStart(run) {
        var api = window.QuickSiteAPI;
        var rate = (api && typeof api.getWriteRate === 'function') ? api.getWriteRate() : { windows: [], waitSeconds: 0 };
        var counted = 0;
        run.plan.slice(run.first).forEach(function (p) { if (!p.ai || p.kept) counted += _countedSteps(p.steps || []); });
        if (rate.windows.length === 0 || counted === 0) return true;
        var plan = _planRun(counted, rate);
        if (plan.start || (plan.reason === 'never' && plan.fitsNow > 0)) return true;
        var waitMsg = _t('rateRefusedToStart', 'This run needs {needs} changes and {left} are left on this server. Enough is back at {time} (in {wait}).')
            .replace('{needs}', String(counted))
            .replace('{left}', String(plan.left))
            .replace('{time}', api.clockText(plan.backAt))
            .replace('{wait}', api.waitText((plan.backAt - Date.now()) / 1000));
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

    var EFFECT_WHAT = {
        pages: ['whatPages', 'Pages'],
        menu: ['whatMenu', 'The menu'],
        footer: ['whatFooter', 'The footer'],
        components: ['whatComponents', 'Components'],
        snippets: ['whatSnippets', 'Snippets'],
        languages: ['whatLanguages', 'Languages'],
        defaultLanguage: ['whatDefaultLanguage', 'The default language'],
        multilingual: ['whatMultilingual', 'Multilingual mode'],
        texts: ['whatTexts', 'Texts'],
        translationFiles: ['whatTranslationFiles', 'Translation files of languages the project no longer has'],
        media: ['whatMedia', 'Media files'],
        stylesheet: ['whatStylesheet', 'The stylesheet'],
        interactions: ['whatInteractions', 'Interactions'],
        privacy: ['whatPrivacy', 'Privacy pages and settings'],
        other: ['whatOther', 'Other commands']
    };
    var EFFECT_LIST_MAX = 8;

    /**
     * What a command flagged destructive does, for one that deletes nothing: a short word for a step and a
     * phase, and a line for a warning. Its step stops a run exactly as a deletion does, so `effect` stays
     * 'delete' and every gate reads that; only what the run says about it comes from here. A flagged command
     * with no entry here reads as a deletion, which is what cleanOrphanTranslations is.
     */
    var EFFECT_DOES = {
        restoreBackup: {
            word: ['effectRestores', 'Restores'],
            line: ['effectRestoresLine', "Puts a backup back, over the project's current state"]
        },
        transferOwnership: {
            word: ['effectTransfers', 'Transfers'],
            line: ['effectTransfersLine', 'Hands the project to another member, who becomes its owner']
        },
        setOAuthCredentials: {
            word: ['effectSetsKeys', 'Sets keys'],
            line: ['effectSetsKeysLine', "Sets or clears the project's keys for a sign-in provider"]
        },
        generateConsentLayer: {
            word: ['effectRegenerates', 'Regenerates'],
            line: ['effectRegeneratesLine', 'Writes the cookie banner and popup again, over what is there']
        }
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
            var what = EFFECT_WHAT[g.what] || EFFECT_WHAT.other;
            var verb = g.effect === 'delete' ? _t('effectDeletes', 'Deletes') : _t('effectChanges', 'Changes');
            var line = g.does ? _t(g.does.line[0], g.does.line[1]) : verb + ' — ' + _t(what[0], what[1]);
            if (g.targets.length > 0) {
                var shown = g.targets.slice(0, EFFECT_LIST_MAX).join(', ');
                if (g.targets.length > EFFECT_LIST_MAX) {
                    shown = _t('effectMore', '{items} and {n} more').replace('{items}', shown)
                        .replace('{n}', String(g.targets.length - EFFECT_LIST_MAX));
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
            parts.push(_t('confirmAiAnnounce', "{name} (AI): the commands its AI answers change the site; a deletion among them is asked when the answer arrives.")
                .replace('{name}', p.title));
        });
        return utils.confirm(parts.join('\n\n'), {
            type: 'danger',
            title: word === 'delete' ? _t('confirmTitleDelete', 'Delete content?')
                : (word === 'act' ? _t('confirmTitleAct', 'Run these commands?') : _t('confirmTitleChange', 'Change content?')),
            confirmText: word === 'delete' ? _t('confirmRunDelete', 'Delete and run')
                : (word === 'act' ? _t('confirmRunAct', 'Confirm and run') : _t('confirmRunChange', 'Change and run'))
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
        title.textContent = _t('reviewNext', 'Next — {current} of {total}: {name}')
            .replace('{current}', String(k + 1)).replace('{total}', String(run.plan.length)).replace('{name}', phase.title);
        _reviewEl.appendChild(title);

        var what = document.createElement('p');
        what.className = 'preview-contextual-ai-tools__review-what';
        what.textContent = phase.ai
            ? _t('reviewAi', 'Its prompt is built from the project as it now is.')
            : _t('reviewSteps', '{n} steps').replace('{n}', String((phase.steps || []).length));
        _reviewEl.appendChild(what);

        if (run.last) {
            var last = document.createElement('p');
            last.className = 'preview-contextual-ai-tools__review-last';
            last.textContent = (run.last.fail > 0
                ? _t('reviewLastFailed', '{name}: {ok} of {total} succeeded, {fail} failed')
                : _t('reviewLast', '{name}: {ok} of {total} succeeded'))
                .replace('{name}', run.last.name).replace('{ok}', String(run.last.ok))
                .replace('{total}', String(run.last.total)).replace('{fail}', String(run.last.fail));
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
        go.className = 'admin-btn admin-btn--small ' + (deletes ? 'admin-btn--danger' : 'admin-btn--primary');
        var word = _effectWord(warned);
        go.textContent = word === 'delete' ? _t('reviewContinueDelete', 'Delete and continue')
            : (word === 'act' ? _t('reviewContinueAct', 'Confirm and continue')
                : (lines.length ? _t('reviewContinueChange', 'Change and continue') : _t('reviewContinue', 'Continue')));
        var stop = document.createElement('button');
        stop.type = 'button';
        stop.className = 'admin-btn admin-btn--secondary admin-btn--small';
        stop.textContent = _t('reviewStop', 'Stop');
        go.addEventListener('click', function () { resolve(true); });
        stop.addEventListener('click', function () { resolve(false); });
        actions.appendChild(go);
        actions.appendChild(stop);
        _reviewEl.appendChild(actions);
        if (_stepsSetOpen && !phase.ai) _stepsSetOpen(true);
        if (_stepsHeaderState) {
            _stepsHeaderState.textContent = _t('stepsReady', '{n} commands ready').replace('{n}', String((phase.steps || []).length));
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
        title.textContent = _t('reviewAwaiting', "{current} of {total}: waiting for {name}'s answer — it runs from AI response.")
            .replace('{current}', String(k + 1)).replace('{total}', String(run.plan.length)).replace('{name}', phase.title);
        _reviewEl.appendChild(title);
        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__review-actions';
        _recoveryEls = {
            continueBtn: _renderRecoveryButton('continue', 'admin-btn--primary', _t('continueAnswer', 'Continue the answer'),
                _onContinueAnswerClick),
            sendAgainBtn: _renderRecoveryButton('send-again', 'admin-btn--secondary', _t('sendAgain', 'Send again'),
                _onSendAgainClick)
        };
        _recoveryEls.sendAgainBtn.title = _t('sendAgainTitle', 'Sends the same prompt again, for a new answer.');
        actions.appendChild(_recoveryEls.continueBtn);
        actions.appendChild(_recoveryEls.sendAgainBtn);
        var stop = document.createElement('button');
        stop.type = 'button';
        stop.className = 'admin-btn admin-btn--secondary admin-btn--small';
        stop.textContent = _t('reviewStop', 'Stop');
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
            item.className = 'preview-contextual-ai-tools__effect preview-contextual-ai-tools__effect--' + l.effect;
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
            item.className = 'preview-contextual-ai-tools__run-phase preview-contextual-ai-tools__run-phase--' + state;
            item.textContent = (marks[state] ? marks[state] + ' ' : '') + p.title + (p.ai ? ' (' + _t('kindAi', 'AI') + ')' : '');
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
        var out = { ok: 0, fail: 0, stoppedAt: -1, reason: '', command: '' };

        // ── Before starting: the counted steps against what is left ──
        var rate = (api && typeof api.getWriteRate === 'function') ? api.getWriteRate() : { windows: [], waitSeconds: 0 };
        var counted = _countedSteps(steps);
        var keepAfter = null;
        if (rate.windows.length > 0 && counted > 0) {
            var plan = _planRun(counted, rate);
            if (!plan.start && (plan.reason === 'wait' || plan.fitsNow <= 0)) {
                var waitMsg = _t('rateRefusedToStart', 'This run needs {needs} changes and {left} are left on this server. Enough is back at {time} (in {wait}).')
                    .replace('{needs}', String(counted))
                    .replace('{left}', String(plan.left))
                    .replace('{time}', api.clockText(plan.backAt))
                    .replace('{wait}', api.waitText((plan.backAt - Date.now()) / 1000));
                if (_stepsHeaderState) _stepsHeaderState.textContent = waitMsg;
                toaster(waitMsg, 'warning');
                out.stoppedAt = 0;
                out.reason = 'rate';
                return out;
            }
            if (!plan.start) {
                // More than a whole window: never in one go. Offered in parts.
                var fits = _firstPart(steps, plan.fitsNow);
                var neverMsg = _t('rateNeverOneGo', 'This run needs {needs} changes; this server allows {max} per {period}, so it cannot run in one go.')
                    .replace('{needs}', String(counted))
                    .replace('{max}', String(plan.window.max))
                    .replace('{period}', api.periodText(plan.window.period));
                var partsMsg = _t('rateRunInParts', 'Run the first {fits} steps now and keep the other {rest} for later?')
                    .replace('{fits}', String(fits))
                    .replace('{rest}', String(steps.length - fits));
                var go = window.QuickSiteUtils && typeof window.QuickSiteUtils.confirm === 'function'
                    ? await window.QuickSiteUtils.confirm(neverMsg + ' ' + partsMsg, { confirmText: _t('rateRunPart', 'Run the first part') })
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

        var runTpl = _t('executionRunning', 'Running {current}/{total}');
        var last = keepAfter !== null ? keepAfter : steps.length;
        for (var j = 0; j < last; j++) {
            if (_stepsHeaderState) {
                _stepsHeaderState.textContent = runTpl
                    .replace('{current}', String(j + 1))
                    .replace('{total}', String(steps.length)) + (opts.name ? ' · ' + opts.name : '');
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
                _updateStepRow(rows[j], 'ok', result.message || '');
                continue;
            }
            out.fail++;
            _updateStepRow(rows[j], 'error', result.error || 'Failed');
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
            _updateStepRow(row, 'retry', _t('stepRetry', 'Retrying ({attempt} of {max}) in {seconds} s — {error}')
                .replace('{attempt}', String(attempt)).replace('{max}', String(max))
                .replace('{seconds}', String(delay / 1000)).replace('{error}', result.error || ''));
            await new Promise(function (resolve) { setTimeout(resolve, delay); });
            _updateStepRow(row, 'running');
            result = await _executeOneCommand(step);
        }
        if (!result.ok && !result.rateLimited && attempt > 0) {
            result.error = _t('stepFailedAfterRetries', '{error} (after {n} retries)')
                .replace('{n}', String(attempt)).replace('{error}', result.error || '');
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
                    _stepsHeaderState.textContent = _t('rateWaiting', 'Waiting for the change allowance… {wait}')
                        .replace('{wait}', api ? api.waitText(left) : left + ' s');
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
        if (!commandName) return { ok: false, status: 0, error: 'Missing command name' };
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
     * savedAt}), or null.
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
        title.textContent = (steps.length > 0
            ? _t('keptTitle', '{n} steps kept from a stopped run ({label}, planned at {time})').replace('{n}', String(steps.length))
            : _t('keptBefore', '{label}: stopped before {name} (planned at {time})').replace('{name}', kept.phaseTitle || ''))
            .replace('{label}', kept.label || '—')
            .replace('{time}', api ? api.clockText(kept.savedAt) : '');
        _keptEl.appendChild(title);
        var later = Array.isArray(kept.later) ? kept.later : [];
        if (later.length > 0) {
            var then = document.createElement('p');
            then.className = 'preview-contextual-ai-tools__kept-then';
            then.textContent = _t('keptThen', 'Then: {names}').replace('{names}', later.join(', '));
            _keptEl.appendChild(then);
        }
        var warn = document.createElement('p');
        warn.className = 'preview-contextual-ai-tools__kept-warning';
        warn.textContent = _t('keptWarning', 'The page may have changed since they were planned: node positions can shift.');
        _keptEl.appendChild(warn);

        var actions = document.createElement('div');
        actions.className = 'preview-contextual-ai-tools__kept-actions';
        var run = document.createElement('button');
        run.type = 'button';
        run.className = 'admin-btn admin-btn--primary admin-btn--small';
        run.textContent = _t('keptRun', 'Run them');
        var canRun = plan.start || (plan.reason === 'never' && plan.fitsNow > 0);
        var notYetText = canRun ? '' : _t('keptNotYet', 'Enough is back at {time}.').replace('{time}', api.clockText(plan.backAt));
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
        discard.className = 'admin-btn admin-btn--secondary admin-btn--small';
        discard.textContent = _t('keptDiscard', 'Discard');
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
            _toaster()(_t('keptMissing', '{label} is not in the list any more: discard what was kept.').replace('{label}', kept.label || kept.workflowId), 'warning');
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
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
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
            if (!data.phase) throw new Error('No such phase');
            return data.phase;
        });
    }

    /** The run's plan changed under it (the server's 409, or a kept rest whose phase moved): the run stops there. */
    function _planChangedError() {
        var err = new Error(_t('planChanged', "The run's plan changed: start the run again."));
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
            pending.textContent = _t('batchAiPending', 'Its commands appear when the AI answers.');
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
                ? _t('stepsReady', '{n} commands ready').replace('{n}', String(total))
                : _t('stateIdle', 'Idle');
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
        var kind = phase.kind === 'pre' ? _t('kindBefore', 'before') : (phase.kind === 'post' ? _t('kindAfter', 'after') : '');
        var parts = [];
        if (kind) parts.push(kind);
        if (phase.ai) parts.push(_t('kindAi', 'AI'));
        parts.push(_t('reviewSteps', '{n} steps').replace('{n}', String(steps.length)));
        var warned = _warnedEffects(effects);
        var warnWord = _effectWord(warned);
        if (warnWord === 'delete') parts.push(_t('effectDeletes', 'Deletes'));
        else if (warnWord === 'act') {
            var seen = {};
            warned.forEach(function (e) {
                var does = _effectDoes(e);
                if (does && !seen[e.command]) parts.push(_t(does.word[0], does.word[1]));
                if (does) seen[e.command] = true;
            });
        } else if (warnWord === 'change') parts.push(_t('effectChanges', 'Changes'));
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
        var commandName = cmd.command || cmd.name || '(unnamed)';
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
            tag.textContent = stepDoes ? _t(stepDoes.word[0], stepDoes.word[1])
                : (effect.effect === 'delete' ? _t('effectDeletes', 'Deletes') : _t('effectChanges', 'Changes'));
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
        status.textContent = _t('stepPending', 'Pending');
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
            row.status.textContent = _t('stepRunning', 'Running…');
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
            var tpl = _t('stepError', 'Failed: {error}');
            row.status.textContent = tpl.replace('{error}', message || 'Failed');
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
                msgs.push(_t('validationRequired', 'Required'));
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
                var tplMin = _t('validationMinItems', 'Pick at least {n}');
                msgs.push(tplMin.replace('{n}', String(v.minItems)));
            }
            if (v.maxItems != null && value.length > v.maxItems) {
                var tplMax = _t('validationMaxItems', 'Pick at most {n}');
                msgs.push(tplMax.replace('{n}', String(v.maxItems)));
            }
        }

        if (typeof value === 'string') {
            if (v.minLength != null && value.length < v.minLength) {
                var tplL = _t('validationMinLength', 'At least {n} characters');
                msgs.push(tplL.replace('{n}', String(v.minLength)));
            }
            if (v.maxLength != null && value.length > v.maxLength) {
                var tplLM = _t('validationMaxLength', 'At most {n} characters');
                msgs.push(tplLM.replace('{n}', String(v.maxLength)));
            }
            if (v.pattern) {
                try {
                    var re = new RegExp('^(?:' + v.pattern + ')$');
                    if (!re.test(value)) {
                        msgs.push(_t('validationPattern', "Doesn't match the required pattern"));
                    }
                } catch (e) {
                    console.warn('[PreviewAiTools] invalid pattern in workflow:', v.pattern, e);
                }
            }
        }

        if (typeof value === 'number'
            || (typeof value === 'string' && value !== '' && !isNaN(Number(value)))) {
            var num = Number(value);
            if (v.min != null && num < v.min) {
                var tplNm = _t('validationMin', 'Minimum {n}');
                msgs.push(tplNm.replace('{n}', String(v.min)));
            }
            if (v.max != null && num > v.max) {
                var tplNx = _t('validationMax', 'Maximum {n}');
                msgs.push(tplNx.replace('{n}', String(v.max)));
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
                _primaryActionBtn.title = _t('runBlockedTooltip', 'Fix the highlighted parameters before running.');
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
            _executeBtnEl.textContent = word === 'delete' ? _t('confirmRunDelete', 'Delete and run')
                : (word === 'act' ? _t('confirmRunAct', 'Confirm and run')
                    : (word === 'change' ? _t('confirmRunChange', 'Change and run') : _t('actionExecute', 'Execute commands')));
            _executeBtnEl.className = deletes
                ? 'preview-contextual-ai-tools__action-btn--primary preview-contextual-ai-tools__action-btn--danger'
                : 'preview-contextual-ai-tools__action-btn--primary';
            // Not before the answer is classified: the label must say what it does.
            _executeBtnEl.disabled = hasParamErrors || !_answerPhase || (_executing && !_answerWaiter);
        }
        if (_aiResponseTextarea) {
            // An answer is pasted only when a phase can take it: before the run when its first phase is
            // the AI one, else once the run reaches that phase.
            var open = target !== null || (_run === null && !_executing && _firstPhaseIsAi());
            _aiResponseTextarea.readOnly = !open;
            _aiResponseTextarea.placeholder = !open
                ? _t('answerAfterRun', 'Run first: the parts before it prepare the project, then its prompt is built.')
                : (_continuation
                    ? _t('continueWaiting', 'The rest of the answer goes here; the commands that arrived whole ({n}) are kept.').replace('{n}', String(_continuation.kept.length))
                    : _t('aiResponsePlaceholder', '{"commands": [...]}'));
        }
        _refreshRecovery();
    }

    /**
     * The primary button's label: Run for a run with no AI phase, or one whose AI phase comes after
     * others (they run first); for a run that starts with its AI phase, Generate prompt with no key, else
     * Run with AI / Send to AI by the switch.
     */
    function _computePrimaryLabel() {
        if (!_chainHasAi()) return _t('actionRun', 'Run');
        var conn = _getDefaultConnection();
        if (!conn) return _firstPhaseIsAi() ? _t('actionGeneratePrompt', 'Generate prompt') : _t('actionRun', 'Run');
        return _autoContinue() ? _t('actionRunWithAi', 'Run with AI') : _t('actionSendToAi', 'Send to AI');
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
            _copyBtn.textContent = _t('copied', 'Copied!');
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
        btn.className = 'admin-btn ' + kind + ' admin-btn--small';
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
            ? _t('continueAnswerTitle', 'Keeps the commands that arrived whole ({n}) and asks the AI only for the rest, from the command that was cut. The answer put together runs only when you click Execute.').replace('{n}', String(plan.kept.length))
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
            _autoCopyPromptWithToast(request, _t('continueCopied', 'Copied: a request for the rest of the answer. Paste it to your AI assistant, then paste its reply in AI response.'));
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

    /** What Continue asks the AI: the commands still missing, from the one that was cut, as one JSON object. */
    function _continuationRequest(plan) {
        var n = String(plan.kept.length);
        if (!plan.cut) {
            return _t('continueRequestBoundary', 'Your answer above was cut off before it ended, after the commands that arrived whole ({n} of them). Reply with only the commands still missing, as one JSON object {"commands": [...]}, with no text before or after it and none of the commands already given.').split('{n}').join(n);
        }
        var cut = plan.cut.length > 240 ? plan.cut.slice(0, 240) + '…' : plan.cut;
        return _t('continueRequest', 'Your answer above was cut off before it ended. The commands before the cut arrived whole ({n} of them); the command after them was cut, and it began:\n\n{cut}\n\nReply with only the commands still missing, starting again from that cut command, as one JSON object {"commands": [...]}, with no text before or after it and none of the commands that arrived whole.')
            .split('{n}').join(n).replace('{cut}', function () { return cut; });
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

        if (_autoExecHintEl) {
            _autoExecHintEl.textContent = _t('autoExecHint', 'Auto-executing in 1.5s — edit response to cancel');
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
            return { type: 'error', message: _t('aiResponseEmpty', 'No commands found in response.'), empty: true };
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
            var tpl = _answerParts > 1
                ? _t('assembledReady', '{n} commands ready, put together from {parts} answers: they run only when you click Execute.')
                : _t('aiResponseValid', '{n} commands ready');
            _aiResponseStatusEl.textContent = tpl.replace('{n}', String(_aiResponseStatus.commandCount))
                .replace('{parts}', String(_answerParts));
            _aiResponseStatusEl.classList.add('preview-contextual-ai-tools__ai-response-status--ok');
        } else {
            var errTpl = _t('aiResponseInvalid', 'Invalid JSON: {error}');
            // An answer that does not parse says so; a failure to get one (a send, a reading) is said as it is.
            _aiResponseStatusEl.textContent = _aiResponseStatus.empty || _aiResponseStatus.plain
                ? _aiResponseStatus.message
                : errTpl.replace('{error}', _aiResponseStatus.message);
            _aiResponseStatusEl.classList.add('preview-contextual-ai-tools__ai-response-status--error');
        }
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
