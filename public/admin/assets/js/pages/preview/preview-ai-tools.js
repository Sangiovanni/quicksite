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
 * Workflow runner flow (matches /admin/workflows/{spec}):
 *   1. INPUTS — Your prompt (free-form) + Parameters + [Generate]
 *   2. AI EXCHANGE — Generated prompt (copy target) + AI response (paste target)
 *   3. EXECUTION — Toggles + [Run] + Steps (with state header)
 *   Footer — Model + Touches (inline, discreet)
 *
 * Deterministic workflows (no promptTemplate) skip AI EXCHANGE entirely.
 *
 * See NOTES/planning/BETA9_AI_TOOLS_INTEGRATION.md for the phased plan.
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
    var _userPrompt = '';
    var _generatedPrompt = '';
    var _promptGenerating = false;
    var _promptGenerated = false;            // true after first successful Generate
    var _aiResponseText = '';
    var _aiResponseStatus = { type: 'idle' };  // 'idle'|'ok'|'error', + message + commandCount

    // DOM refs — bound on init().

    // C8 8.X — the /admin/api helper endpoint is project-scoped: it authorizes each
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
    var _currentCommands = [];           // parsed AI commands OR resolved det commands; basis for Steps preview
    var _pasteAutoExecTimer = null;      // unified 1.5s grace timer for auto-execute
    var _autoExecHintEl = null;          // DOM ref for "Auto-executing in 1.5s…" hint

    // ──────────────────────────── i18n ──────────────────────────────────

    function _t(key, fallback) {
        var panel = (window.PreviewConfig
                     && window.PreviewConfig.i18nPanels
                     && window.PreviewConfig.i18nPanels.aiTools) || {};
        return panel[key] || fallback;
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
        var cfg = window.PreviewConfig || {};
        var url = (cfg.managementUrl || '') + 'backupProject';
        var headers = { 'Accept': 'application/json' };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        var toaster = (window.QuickSiteAdmin && typeof window.QuickSiteAdmin.showToast === 'function')
            ? window.QuickSiteAdmin.showToast.bind(window.QuickSiteAdmin)
            : null;
        fetch(url, { method: 'GET', headers: headers }).then(function (res) {
            return res.json().then(function (json) { return { ok: res.ok, json: json }; });
        }).then(function (result) {
            if (!result.ok || !result.json || (!result.json.success && result.json.status >= 300)) {
                var errMsg = (result.json && (result.json.error || result.json.message)) || 'HTTP error';
                throw new Error(errMsg);
            }
            var path = (result.json.data && (result.json.data.path || result.json.data.backupPath)) || '';
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
        _showListView();
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

    function _onCardClick(workflow) {
        _activeWorkflowSummary = workflow;
        _activeWorkflowSpec = null;
        _activeTemplate = null;
        _fetchedData = {};
        _paramValues = {};
        _userPrompt = '';
        _generatedPrompt = '';
        _promptGenerating = false;
        _promptGenerated = false;
        _aiResponseText = '';
        _aiResponseStatus = { type: 'idle' };
        _showRunnerView();
        _renderRunnerLoading(workflow);
        _fetchWorkflowDetail(workflow.id).then(function (detail) {
            _activeWorkflowSpec = detail.spec || null;
            _activeTemplate = detail.template || '';
            _fetchedData = detail.fetchedData || {};
            _initParamValues();
            _renderRunner();
            // Auto-preview for deterministic workflows: resolve commands
            // server-side and populate Steps preview so the user sees
            // what would run before clicking the action button.
            if (!_isAiWorkflow()) {
                _resolveAndPreviewDeterministic();
            }
        }).catch(function (err) {
            _renderRunnerError(err && err.message ? err.message : String(err));
        });
    }

    function _onBackToList() {
        _activeWorkflowSummary = null;
        _activeWorkflowSpec = null;
        _activeTemplate = null;
        _fetchedData = {};
        _paramValues = {};
        _userPrompt = '';
        _generatedPrompt = '';
        _promptGenerated = false;
        _aiResponseText = '';
        _aiResponseStatus = { type: 'idle' };
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

    function _isAiWorkflow() {
        if (!_activeWorkflowSpec) return false;
        return !!(_activeWorkflowSpec.promptTemplate || _activeTemplate);
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
    function _schedulePreviewRefresh() {
        if (_previewRefreshTimer) { clearTimeout(_previewRefreshTimer); _previewRefreshTimer = null; }
        if (_isAiWorkflow()) {
            // AI workflows: prompt + Steps preview both depend on params.
            // The prompt is re-generated lazily on action click; the Steps
            // preview clears here so the user knows the stale list is gone.
            _currentCommands = [];
            _renderStepsPreview();
            _updateActionStates();
            return;
        }
        // Deterministic: re-resolve after a short debounce so the Steps
        // preview reflects the new params.
        _previewRefreshTimer = setTimeout(function () {
            _previewRefreshTimer = null;
            if (_isAiWorkflow()) return;
            _resolveAndPreviewDeterministic();
        }, 400);
    }

    // ──────────────────────────── optionsFrom (incl. filterFrom) ────────

    function _resolveOptionsFromData(param) {
        var of = param.optionsFrom;
        if (!of) return null;
        var dataKey = typeof of === 'string' ? of : of.data;
        if (!dataKey) return null;
        var fetched = _fetchedData ? _fetchedData[dataKey] : null;
        if (!Array.isArray(fetched)) return null;
        var valueField = (typeof of === 'object') ? of.value : null;
        var labelField = (typeof of === 'object') ? of.label : null;
        var prepend = (typeof of === 'object' && Array.isArray(of.prepend)) ? of.prepend : [];
        var options = prepend.slice();
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

        // filterFrom: cascade from another param's value (e.g. defaultLanguage
        // restricted to whatever's in the languages array). Only applies when
        // the referenced param is visible AND has a non-empty value.
        if (typeof of === 'object' && of.filterFrom) {
            var refParam = _findParam(of.filterFrom);
            if (refParam && _isParamVisible(refParam)) {
                var refVal = _paramValues[of.filterFrom];
                var refList = Array.isArray(refVal)
                    ? refVal
                    : (refVal != null && refVal !== '' ? [refVal] : []);
                if (refList.length > 0) {
                    options = options.filter(function (o) {
                        return refList.indexOf(o.value) !== -1;
                    });
                }
            }
        }

        return options;
    }

    function _findParam(id) {
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            if (params[i].id === id) return params[i];
        }
        return null;
    }

    function _updateDependentSelects(_changedParamId) {
        // Rebuild ALL filterFrom-having selects on any param change.
        // Why not restrict to selects whose filterFrom === changedParamId?
        // Because the dependency can be transitive: defaultLanguage filters
        // from `languages`, but `languages` becomes hidden when multilingual
        // changes — so defaultLanguage's filter outcome can shift without
        // languages itself being the immediate trigger. Rebuilding all
        // filterFrom selects on any change is cheap (typically 0–2 per
        // workflow) and side-steps cascade tracking.
        if (!_runnerView) return;
        var params = (_activeWorkflowSpec && _activeWorkflowSpec.parameters) || [];
        for (var i = 0; i < params.length; i++) {
            var p = params[i];
            var of = p.optionsFrom;
            if (!of || typeof of !== 'object' || !of.filterFrom) continue;
            var wrap = _runnerView.querySelector('[data-param-id="' + p.id + '"]');
            if (!wrap) continue;
            var oldInput = wrap.querySelector('select, .preview-contextual-ai-tools__tag-select, .preview-contextual-ai-tools__param-placeholder, input');
            if (!oldInput) continue;
            var newInput = _renderParamInput(p);
            if (newInput) oldInput.replaceWith(newInput);
        }
    }

    // ──────────────────────────── Runner: render shells ─────────────────

    function _renderRunnerLoading(workflow) {
        if (!_runnerView) return;
        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(workflow));
        var loading = document.createElement('div');
        loading.className = 'preview-contextual-ai-tools__runner-loading';
        loading.textContent = _t('runnerLoading', 'Loading workflow…');
        _runnerView.appendChild(loading);
    }

    function _renderRunnerError(message) {
        if (!_runnerView) return;
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
        var isAI = _isAiWorkflow();
        var conn = _getDefaultConnection();

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
        if (_pasteAutoExecTimer) { clearTimeout(_pasteAutoExecTimer); _pasteAutoExecTimer = null; }

        _runnerView.replaceChildren();
        _runnerView.appendChild(_renderRunnerHeader(workflow));
        if (workflow.description) {
            _runnerView.appendChild(_renderRunnerDescription(workflow.description));
        }

        _runnerView.appendChild(_renderInputsZone(spec, isAI));
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
        z.body.appendChild(_renderSectionGeneralPrompt(hasBYOK));
        z.body.appendChild(_renderSectionAiResponseTextarea(hasBYOK));
        return z.section;
    }

    function _renderExecutionZone(spec) {
        var z = _renderZone('execution', _t('zoneExecution', 'Run'));
        z.body.appendChild(_renderExecuteRow(_isAiWorkflow()));
        z.body.appendChild(_renderSectionBatch(spec));
        return z.section;
    }

    function _renderExecuteRow(isAI) {
        var wrap = document.createElement('div');
        wrap.className = 'preview-contextual-ai-tools__execute-row';

        // Auto-execute toggle — AI workflows only (deterministic always runs end-to-end)
        if (isAI) {
            var toggles = document.createElement('div');
            toggles.className = 'preview-contextual-ai-tools__toggles';
            toggles.appendChild(_renderToggle('aiAutoExecute', _t('autoExecuteLabel', 'Auto-execute')));
            wrap.appendChild(toggles);
        }

        // Explicit "Execute commands" button — only visible when commands
        // are ready AND auto-execute is OFF. Visibility set by
        // _updateActionStates after every relevant state change.
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
        var ta = document.createElement('textarea');
        ta.className = 'preview-contextual-ai-tools__user-prompt';
        ta.placeholder = _t('yourPromptPlaceholder', 'Describe what you want.');
        ta.value = _userPrompt;
        ta.addEventListener('input', function () {
            _userPrompt = ta.value;
        });
        s.body.appendChild(ta);
        return s.section;
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
        label.textContent = param.label || param.labelKey || param.id;
        wrap.appendChild(label);

        if (param.help) {
            var help = document.createElement('p');
            help.className = 'preview-contextual-ai-tools__param-help';
            help.textContent = param.help;
            wrap.appendChild(help);
        }

        var input = _renderParamInput(param);
        if (input) wrap.appendChild(input);

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

    function _renderParamInput(param) {
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
            var options = Array.isArray(param.options)
                ? param.options
                : _resolveOptionsFromData(param);
            if (!options || options.length === 0) {
                return _renderEmptyOptionsPlaceholder();
            }
            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                var optEl = document.createElement('option');
                optEl.value = opt.value;
                optEl.textContent = opt.label || opt.value;
                if (opt.value === _paramValues[pid]) optEl.selected = true;
                input.appendChild(optEl);
            }
            if (input.selectedIndex < 0 && options.length > 0) {
                input.selectedIndex = 0;
                _paramValues[pid] = options[0].value;
            }
            input.addEventListener('change', function () {
                _paramValues[pid] = input.value;
                _onParamChanged(pid);
            });
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

        var current = _paramValues[param.id];
        if (!Array.isArray(current)) {
            current = current != null && current !== '' ? [current] : [];
        }
        _paramValues[param.id] = current;

        for (var i = 0; i < options.length; i++) {
            wrap.appendChild(_renderTagSelectOption(param.id, options[i], current));
        }
        return wrap;
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

    function _readToggle(storageKeyName) {
        var key = (window.QuickSiteStorageKeys || {})[storageKeyName];
        if (!key) return true;
        var raw = localStorage.getItem(key);
        if (raw === null) return true;
        return raw !== 'false';
    }

    function _writeToggle(storageKeyName, value) {
        var key = (window.QuickSiteStorageKeys || {})[storageKeyName];
        if (!key) return;
        localStorage.setItem(key, value ? 'true' : 'false');
    }

    async function _onPrimaryActionClick() {
        if (_executing) return;
        if (Object.keys(_paramErrors).length > 0) return;
        var isAI = _isAiWorkflow();
        var conn = _getDefaultConnection();

        // User intentionally re-triggered — cancel any pending auto-execute
        // grace timer so we don't accidentally double-fire.
        _cancelAutoExecute();

        _executing = true;
        _updateActionStates();

        try {
            if (!isAI) {
                _morphPrimary(_t('runActive', 'Running…'));
                await _resolveAndPreviewDeterministic();
                if (_currentCommands && _currentCommands.length > 0) {
                    await _executeCommands(_currentCommands);
                }
                return;
            }
            if (!conn) {
                // No BYOK: generate prompt + auto-focus + auto-copy with toast.
                _morphPrimary(_t('phaseGenerating', 'Generating prompt…'));
                await _generatePrompt();
                _focusGeneralPrompt();
                _autoCopyPromptWithToast();
                return;
            }
            // BYOK present — generate + send. Auto-execute (if on) fires
            // via the 1.5s grace timer set up inside _sendToAi.
            _morphPrimary(_t('phaseGenerating', 'Generating prompt…'));
            await _generatePrompt();
            var modelName = conn.defaultModel || conn.model || '';
            _morphPrimary(_t('sendingToAi', 'Sending to {model}…').replace('{model}', modelName));
            await _sendToAi(conn);
        } catch (err) {
            console.error('[PreviewAiTools] primary action failed:', err);
        } finally {
            _executing = false;
            _updateActionStates();
        }
    }

    async function _onSecondaryActionClick() {
        if (_executing) return;
        if (Object.keys(_paramErrors).length > 0) return;
        if (!_isAiWorkflow()) return;
        _executing = true;
        _updateActionStates();
        try {
            await _generatePrompt();
            _focusGeneralPrompt();
            _autoCopyPromptWithToast();
        } catch (err) {
            console.error('[PreviewAiTools] generate-for-copy failed:', err);
        } finally {
            _executing = false;
            _updateActionStates();
        }
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

    function _autoCopyPromptWithToast() {
        var text = _generatedPrompt || (_generalPromptTextarea && _generalPromptTextarea.value) || '';
        if (!text) return;
        var toaster = (window.QuickSiteAdmin && typeof window.QuickSiteAdmin.showToast === 'function')
            ? window.QuickSiteAdmin.showToast.bind(window.QuickSiteAdmin)
            : null;
        var ok = function () {
            if (toaster) toaster(_t('copiedToast', 'Prompt copied to clipboard'), 'success');
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

    async function _onExecuteClick() {
        if (_executing) return;
        if (Object.keys(_paramErrors).length > 0) return;
        if (!_currentCommands || _currentCommands.length === 0) return;
        _executing = true;
        _updateActionStates();
        try {
            await _executeCommands(_currentCommands);
        } catch (err) {
            console.error('[PreviewAiTools] execute failed:', err);
        } finally {
            _executing = false;
            _updateActionStates();
        }
    }

    // ──────────────────────────── Send to AI ────────────────────────────

    async function _sendToAi(connection) {
        if (!connection) return;
        if (!window.QSAiCall || !window.QSProviderCatalog) {
            _aiResponseStatus = { type: 'error', message: 'AI caller not loaded.' };
            _updateAiResponseStatusUI();
            _updateActionStates();
            return;
        }
        if (!_generatedPrompt) return;

        var model = connection.defaultModel
            || (connection.models && connection.models[0])
            || (connection.model);
        if (!model) {
            _aiResponseStatus = { type: 'error', message: 'No model configured on the active connection.' };
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

        try {
            var result = await window.QSAiCall.call({
                connection: connection,
                model: model,
                messages: [{ role: 'user', content: _generatedPrompt }],
                options: { max_tokens: 16384, temperature: 0.7 },
                onChunk: function (_deltaText, ctx) {
                    // Stream chunks straight into the textarea so the user
                    // sees progress (mirrors /admin/workflows/{spec} UX).
                    // We don't fire `input` events here — programmatic value
                    // sets are silent, so _onAiResponseInput won't trip.
                    var liveContent = (ctx && ctx.content) || '';
                    lastChars = liveContent.length;
                    if (_aiResponseTextarea) {
                        _aiResponseTextarea.value = liveContent;
                        _aiResponseTextarea.scrollTop = _aiResponseTextarea.scrollHeight;
                    }
                    refreshSendStatus();
                }
            });
            var content = String((result && result.content) || '').trim();
            // Strip Markdown code fences the AI commonly wraps JSON in.
            if (content.indexOf('```') === 0) {
                content = content.replace(/^```(?:json)?\s*\n?/, '').replace(/\n?```\s*$/, '').trim();
            }
            _aiResponseText = content;
            if (_aiResponseTextarea) _aiResponseTextarea.value = content;
            _aiResponseStatus = _validateAiResponse(content);
            // Same pipeline path as user paste so Steps preview + Execute
            // button + auto-execute timer all wake up correctly.
            _syncCommandsFromAiResponse();
            _renderStepsPreview();
            _updateAiResponseStatusUI();
            _updateActionStates();
            _scheduleAutoExecute();
        } catch (err) {
            var errTpl = _t('sendFailed', 'Send failed: {error}');
            var msg = err && err.message ? err.message : String(err);
            _aiResponseStatus = { type: 'error', message: errTpl.replace('{error}', msg) };
            _currentCommands = [];
            _renderStepsPreview();
            _updateAiResponseStatusUI();
            _updateActionStates();
        } finally {
            clearInterval(statusTickerId);
        }
    }

    // ──────────────────────────── Execute batch ─────────────────────────

    async function _executeCommands(commands) {
        if (!Array.isArray(commands) || commands.length === 0) return;
        if (!_stepsBody) return;

        if (_stepsSetOpen) _stepsSetOpen(true);
        _stepsBody.replaceChildren();
        var rows = [];
        for (var i = 0; i < commands.length; i++) {
            var row = _renderStepRow(commands[i]);
            rows.push(row);
            _stepsBody.appendChild(row.el);
        }

        var ok = 0;
        var fail = 0;
        var runTpl = _t('executionRunning', 'Running {current}/{total}');

        for (var j = 0; j < commands.length; j++) {
            if (_stepsHeaderState) {
                _stepsHeaderState.textContent = runTpl
                    .replace('{current}', String(j + 1))
                    .replace('{total}', String(commands.length));
            }
            _updateStepRow(rows[j], 'running');
            try {
                var result = await _executeOneCommand(commands[j]);
                if (result.ok) {
                    ok++;
                    _updateStepRow(rows[j], 'ok', result.message || '');
                } else {
                    fail++;
                    _updateStepRow(rows[j], 'error', result.error || 'Failed');
                }
            } catch (e) {
                fail++;
                _updateStepRow(rows[j], 'error', (e && e.message) || String(e));
            }
        }

        if (_stepsHeaderState) {
            if (fail === 0) {
                _stepsHeaderState.textContent = _t('executionDone', 'Done ({ok} succeeded)')
                    .replace('{ok}', String(ok));
            } else {
                _stepsHeaderState.textContent = _t('executionDoneWithErrors', 'Done ({ok}/{total} succeeded, {fail} failed)')
                    .replace('{ok}', String(ok))
                    .replace('{total}', String(commands.length))
                    .replace('{fail}', String(fail));
            }
        }
        // Notify the rest of the editor that a workflow batch just ran,
        // mirroring the existing /admin/workflows/{spec} signal so things
        // like the miniplayer can refresh.
        try {
            window.dispatchEvent(new CustomEvent('quicksite:workflow-complete'));
        } catch (_e) { /* ignore */ }
        // Reload the iframe so visual changes appear immediately. Only
        // when at least one command succeeded — pure-failure runs leave
        // the iframe alone so the user can investigate.
        if (ok > 0 && window.PreviewManager && typeof window.PreviewManager.reload === 'function') {
            try { window.PreviewManager.reload(); } catch (_e) { /* ignore */ }
        }
    }

    async function _executeOneCommand(cmd) {
        var cfg = window.PreviewConfig || {};
        var commandName = cmd.command || cmd.name;
        if (!commandName) return { ok: false, error: 'Missing command name' };
        var method = String(cmd.method || 'POST').toUpperCase();
        var url = (cfg.managementUrl || '') + commandName;
        var options = {
            method: method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
        };
        if (cfg.authToken) options.headers['Authorization'] = 'Bearer ' + cfg.authToken;
        if (method !== 'GET' && cmd.params) {
            options.body = JSON.stringify(cmd.params);
        }
        var response;
        try {
            response = await fetch(url, options);
        } catch (e) {
            return { ok: false, error: (e && e.message) || 'Network error' };
        }
        var text = await response.text();
        var data;
        try {
            data = text ? JSON.parse(text) : { status: response.status };
        } catch (e) {
            return { ok: false, error: 'Invalid JSON response (HTTP ' + response.status + ')' };
        }
        var isSuccess = (data.status >= 200 && data.status < 300) || data.success === true;
        if (!isSuccess) {
            return { ok: false, error: data.error || data.message || ('HTTP ' + response.status) };
        }
        return { ok: true, message: data.message || '', data: data };
    }

    async function _resolveDeterministicCommands() {
        // Deterministic workflow: POST to /api/workflow-generate-steps with
        // current params. Returns data.steps as the fully-expanded command
        // list (forEach loops expanded, conditions evaluated, params
        // substituted). This is the same endpoint /admin/workflows/{spec}
        // uses for "Preview Steps" + manual execution.
        var cfg = window.PreviewConfig || {};
        var id = _activeWorkflowSummary && _activeWorkflowSummary.id;
        if (!id) return [];
        var url = (cfg.adminUrl || '') + 'api/' + _helperPath('workflow-generate-steps');
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        var res = await fetch(url, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ workflowId: id, params: _paramValues })
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var json = await res.json();
        if (!json || !json.success) throw new Error((json && json.message) || (json && json.error) || 'Resolution failed');
        return (json.data && Array.isArray(json.data.steps)) ? json.data.steps : [];
    }

    async function _resolveAndPreviewDeterministic() {
        try {
            _currentCommands = await _resolveDeterministicCommands();
        } catch (err) {
            console.warn('[PreviewAiTools] resolve failed:', err);
            _currentCommands = [];
        }
        _renderStepsPreview();
    }

    // ──────────────────────────── Steps preview ─────────────────────────

    function _renderStepsPreview() {
        if (!_stepsBody) return;
        _stepsBody.replaceChildren();
        if (!_currentCommands || _currentCommands.length === 0) {
            if (_stepsHeaderState) _stepsHeaderState.textContent = _t('stateIdle', 'Idle');
            return;
        }
        for (var i = 0; i < _currentCommands.length; i++) {
            var row = _renderStepRow(_currentCommands[i]);
            _stepsBody.appendChild(row.el);
        }
        if (_stepsHeaderState) {
            var tpl = _t('stepsReady', '{n} commands ready');
            _stepsHeaderState.textContent = tpl.replace('{n}', String(_currentCommands.length));
        }
    }

    // ──────────────────────────── Step row UI ───────────────────────────

    function _renderStepRow(cmd) {
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
        var isAI = _isAiWorkflow();
        var conn = _getDefaultConnection();

        if (_primaryActionBtn) {
            if (_executing) {
                _primaryActionBtn.disabled = true;
                _primaryActionBtn.title = '';
            } else if (hasParamErrors) {
                _primaryActionBtn.disabled = true;
                _primaryActionBtn.title = _t('runBlockedTooltip', 'Fix the highlighted parameters before running.');
                _primaryActionBtn.textContent = _computePrimaryLabel(isAI, conn);
            } else {
                _primaryActionBtn.disabled = false;
                _primaryActionBtn.title = '';
                _primaryActionBtn.textContent = _computePrimaryLabel(isAI, conn);
            }
        }
        if (_secondaryActionBtn) {
            _secondaryActionBtn.disabled = _executing || hasParamErrors;
        }
        if (_executeBtnEl) {
            // Show Execute when commands are queued AND not slated for auto-execution.
            // Auto-execute fires automatically for BYOK pipeline and on-paste,
            // so the explicit button is only needed when auto-exec is OFF.
            var hasCommands = isAI && _currentCommands && _currentCommands.length > 0;
            var autoExec = _readToggle('aiAutoExecute');
            var shouldShow = hasCommands && !autoExec && _aiResponseStatus.type === 'ok';
            _executeBtnEl.style.display = shouldShow ? '' : 'none';
            _executeBtnEl.disabled = _executing || hasParamErrors;
        }
    }

    function _computePrimaryLabel(isAI, conn) {
        if (!isAI) return _t('actionRun', 'Run');
        if (!conn) return _t('actionGeneratePrompt', 'Generate prompt');
        var autoExec = _readToggle('aiAutoExecute');
        return autoExec ? _t('actionRunWithAi', 'Run with AI') : _t('actionSendToAi', 'Send to AI');
    }

    function _morphPrimary(label) {
        if (!_primaryActionBtn) return;
        _primaryActionBtn.textContent = label;
    }

    // ──────────────────────────── Generate / Copy ───────────────────────

    function _generatePrompt() {
        if (!_isAiWorkflow()) return Promise.resolve();
        if (_promptGenerating) return Promise.resolve();
        _promptGenerating = true;
        var cfg = window.PreviewConfig || {};
        var id = _activeWorkflowSummary && _activeWorkflowSummary.id;
        if (!id) {
            _promptGenerating = false;
            return Promise.resolve();
        }
        var query = _buildParamQueryString();
        var url = (cfg.adminUrl || '') + 'api/' + _helperPath('ai-spec') + '/' + encodeURIComponent(id) + (query ? '?' + query : '');
        var headers = { 'Accept': 'application/json' };
        if (cfg.authToken) headers['Authorization'] = 'Bearer ' + cfg.authToken;
        return fetch(url, { headers: headers }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            if (!json || !json.success) throw new Error((json && json.error) || 'Unknown error');
            var prompt = (json.data && json.data.prompt) || '';
            var assembled = prompt;
            var trimmedUser = (_userPrompt || '').trim();
            if (trimmedUser) {
                assembled += '\n\n---\n\n**User Request:**\n' + trimmedUser;
            }
            _generatedPrompt = assembled;
            _promptGenerated = true;
            if (_generalPromptTextarea) _generalPromptTextarea.value = assembled;
        }).catch(function (err) {
            var tpl = _t('generateError', 'Generate failed: {error}');
            _generatedPrompt = tpl.replace('{error}', err && err.message ? err.message : String(err));
            if (_generalPromptTextarea) _generalPromptTextarea.value = _generatedPrompt;
        }).then(function () {
            _promptGenerating = false;
        });
    }

    function _buildParamQueryString() {
        var qs = new URLSearchParams();
        Object.keys(_paramValues).forEach(function (k) {
            var v = _paramValues[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { qs.append(k, String(item)); });
            } else if (v !== null && v !== undefined) {
                if (typeof v === 'object') {
                    // selector params + any structured value: ship as JSON
                    // so the server can decode + walk subfields (e.g.
                    // {{param.element.tag}}). The /api/ai-spec endpoint
                    // detects `{`-prefixed string values and json_decode()s
                    // them back into associative arrays.
                    qs.append(k, JSON.stringify(v));
                } else {
                    qs.append(k, typeof v === 'boolean' ? (v ? 'true' : 'false') : String(v));
                }
            }
        });
        return qs.toString();
    }

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

    // ──────────────────────────── AI response parse ─────────────────────

    function _onAiResponseInput() {
        _aiResponseText = _aiResponseTextarea ? _aiResponseTextarea.value : '';
        _aiResponseStatus = _validateAiResponse(_aiResponseText);
        _syncCommandsFromAiResponse();
        _renderStepsPreview();
        _updateAiResponseStatusUI();
        _updateActionStates();
        _scheduleAutoExecute();
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

    function _scheduleAutoExecute() {
        // Unified 1.5s grace period before auto-execute fires. Applies to
        // BOTH the BYOK Send pipeline (called from _sendToAi, while the
        // primary pipeline is still keeping `_executing = true`) AND manual
        // paste (called from _onAiResponseInput, _executing = false). The
        // `_executing` check is deferred to the timer's callback rather
        // than the schedule call, because _sendToAi schedules from INSIDE
        // _onPrimaryActionClick's try block — at schedule time _executing
        // is still true; by the time the 1.5s timer fires it has been
        // released in the finally.
        _cancelAutoExecute();
        if (!_isAiWorkflow()) return;
        if (_aiResponseStatus.type !== 'ok') return;
        if (!_currentCommands || _currentCommands.length === 0) return;
        if (!_readToggle('aiAutoExecute')) return;

        if (_autoExecHintEl) {
            _autoExecHintEl.textContent = _t('autoExecHint', 'Auto-executing in 1.5s — edit response to cancel');
            _autoExecHintEl.style.display = '';
        }
        _pasteAutoExecTimer = setTimeout(function () {
            _pasteAutoExecTimer = null;
            if (_autoExecHintEl) _autoExecHintEl.style.display = 'none';
            if (_executing) return;
            if (_aiResponseStatus.type !== 'ok') return;
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
            var tpl = _t('aiResponseValid', '{n} commands ready');
            _aiResponseStatusEl.textContent = tpl.replace('{n}', String(_aiResponseStatus.commandCount));
            _aiResponseStatusEl.classList.add('preview-contextual-ai-tools__ai-response-status--ok');
        } else {
            var errTpl = _t('aiResponseInvalid', 'Invalid JSON: {error}');
            _aiResponseStatusEl.textContent = _aiResponseStatus.empty
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
