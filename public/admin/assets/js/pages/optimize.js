/**
 * Optimization Tools — CSS Refiner orchestration
 *
 * Coordinates:
 *  - Loading style.css via the management API (getStyles)
 *  - Running window.CSSRefiner analyzers in the browser
 *  - Rendering admin-native suggestion lists with diff toggles
 *  - Applying selected edits and saving via editStyles / setRootVariables
 *
 * Depends on: window.CSSRefiner (css-parser.js, utils.js, analyzers/*.js, ui/diff-view.js)
 *
 * @version 1.0.0
 */

(function () {
    'use strict';

    // ── Constants ────────────────────────────────────────────────────────────

    // Analyzers included in the "Auto-Refine Safe" one-click pipeline
    const SAFE_ANALYZERS = ['empty-rules', 'duplicates', 'media-queries'];

    // Analyzers that carry a review-only warning (not auto-applied)
    const REVIEW_ONLY = new Set(['near-duplicates', 'fuzzy-values']);

    // Human-readable labels (populated from i18n data attributes if available)
    const ANALYZER_LABELS = {
        'empty-rules':     'Empty Rules',
        'color-normalize': 'Color Normalize',
        'duplicates':      'Duplicates',
        'media-queries':   'Media Queries',
        'fuzzy-values':    'Fuzzy Values',
        'near-duplicates': 'Near-duplicates',
        'design-tokens':   'Design Tokens',
    };

    // ── State ────────────────────────────────────────────────────────────────

    let _rawCss = '';          // Original CSS loaded from API
    let _workingCss = '';      // CSS after last apply (may differ from _rawCss)
    let _results = [];         // [{analyzerId, suggestions: [{id, description, edits, diffHtml, checked}]}]
    let _autoRefinePending = null; // Edits waiting for modal confirmation

    // ── DOM refs ─────────────────────────────────────────────────────────────

    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

    let _page, _btnAnalyze, _btnAutoRefine, _btnReset, _statusEl;
    let _resultsEl, _emptyEl, _applyBar, _selectedCountEl, _btnApplySelected;
    let _modal, _modalBody, _modalConfirm, _modalCancel, _modalClose;

    // ── Init ─────────────────────────────────────────────────────────────────

    function init() {
        _page = $('#optimize-panel-css-refiner');
        if (!_page) return;

        // Check lib readiness
        if (_page.closest('.optimize-page')?.dataset.libReady !== 'true') return;
        if (!window.CSSRefiner) {
            console.warn('[Optimize] CSSRefiner lib not loaded');
            return;
        }

        _btnAnalyze       = $('#optimize-btn-analyze');
        _btnAutoRefine    = $('#optimize-btn-auto-refine');
        _btnReset         = $('#optimize-btn-reset');
        _statusEl         = $('#optimize-status');
        _resultsEl        = $('#optimize-results');
        _emptyEl          = $('#optimize-empty');
        _applyBar         = $('#optimize-apply-bar');
        _selectedCountEl  = $('#optimize-selected-count');
        _btnApplySelected = $('#optimize-btn-apply-selected');
        _modal            = $('#optimize-auto-refine-modal');
        _modalBody        = $('#optimize-modal-body');
        _modalConfirm     = $('#optimize-modal-confirm');
        _modalCancel      = $('#optimize-modal-cancel');
        _modalClose       = $('#optimize-modal-close');

        _btnAnalyze.addEventListener('click', runFullAnalysis);
        _btnAutoRefine.addEventListener('click', runAutoRefine);
        _btnReset.addEventListener('click', resetResults);
        _btnApplySelected.addEventListener('click', applySelected);

        _modalConfirm.addEventListener('click', confirmAutoRefine);
        _modalCancel.addEventListener('click', closeModal);
        _modalClose.addEventListener('click', closeModal);
        _modal.addEventListener('click', e => { if (e.target === _modal) closeModal(); });
    }

    // ── API helpers ───────────────────────────────────────────────────────────

    async function loadStyles() {
        const res = await QuickSiteAPI.request('getStyles', 'GET');
        if (!res.ok) throw new Error('getStyles failed: ' + (res.data?.error || res.status));
        const content = res.data?.data?.content;
        if (!content && content !== '') throw new Error('getStyles returned no content');
        return content;
    }

    async function saveStyles(css) {
        const res = await QuickSiteAPI.request('editStyles', 'POST', { content: css });
        if (!res.ok) throw new Error('editStyles failed: ' + (res.data?.error || res.status));
        return res.data;
    }

    async function saveRootVariable(name, value) {
        const res = await QuickSiteAPI.request('setRootVariables', 'POST', { variables: { [name]: value } });
        if (!res.ok) throw new Error('setRootVariables failed for ' + name + ': ' + (res.data?.error || res.status));
        return res.data;
    }

    // ── Analysis ──────────────────────────────────────────────────────────────

    async function runFullAnalysis() {
        setStatus('loading', 'Analyzing…');
        _btnAnalyze.disabled = true;
        _btnAutoRefine.disabled = true;

        try {
            _rawCss = await loadStyles();
            _workingCss = _rawCss;
            _results = analyze(_workingCss, null);
            renderResults(_results);
            setStatus('ok', `${totalSuggestions(_results)} suggestions`);
            _btnReset.style.display = '';
        } catch (err) {
            setStatus('error', 'Analysis failed: ' + err.message);
            console.error('[Optimize]', err);
        } finally {
            _btnAnalyze.disabled = false;
            _btnAutoRefine.disabled = false;
        }
    }

    function analyze(css, analyzerFilter) {
        const ast = CSSRefiner.Parser.parse(css);
        const analyzers = [
            'empty-rules', 'color-normalize', 'duplicates',
            'media-queries', 'fuzzy-values', 'near-duplicates', 'design-tokens',
        ].filter(id => analyzerFilter ? analyzerFilter.includes(id) : true);

        return analyzers.map(id => {
            const key = camelize(id);
            const analyzer = CSSRefiner.Analyzers?.[key];
            if (!analyzer?.analyze) return null;
            const suggestions = analyzer.analyze(ast, css);
            return {
                analyzerId: id,
                suggestions: (suggestions || []).map(s => ({
                    ...s,
                    checked: s.enabled !== false,
                })),
            };
        }).filter(Boolean);
    }

    function camelize(id) {
        // 'empty-rules' -> 'emptyRules'
        return id.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
    }

    function totalSuggestions(results) {
        return results.reduce((n, r) => n + r.suggestions.length, 0);
    }

    // ── Auto-Refine Safe ──────────────────────────────────────────────────────

    async function runAutoRefine() {
        setStatus('loading', 'Preparing…');
        _btnAnalyze.disabled = true;
        _btnAutoRefine.disabled = true;

        try {
            _rawCss = await loadStyles();
            _workingCss = _rawCss;
            const safeResults = analyze(_workingCss, SAFE_ANALYZERS);

            if (totalSuggestions(safeResults) === 0) {
                setStatus('ok', 'No safe changes needed — CSS looks clean!');
                return;
            }

            _autoRefinePending = safeResults;
            showAutoRefineModal(safeResults);
        } catch (err) {
            setStatus('error', 'Failed: ' + err.message);
        } finally {
            _btnAnalyze.disabled = false;
            _btnAutoRefine.disabled = false;
        }
    }

    async function confirmAutoRefine() {
        if (!_autoRefinePending) return;
        const pendingResults = _autoRefinePending;
        _modal.style.display = 'none';
        _autoRefinePending = null;
        setStatus('loading', 'Applying…');

        try {
            const applicableSuggestions = pendingResults
                .flatMap(r => r.suggestions.map(s => ({ ...s, _analyzerId: r.analyzerId })))
                .filter(s => s.enabled !== false && !REVIEW_ONLY.has(s._analyzerId));
            const { edits: allEdits, dropped } = collectNonOverlappingEdits(applicableSuggestions);

            if (!allEdits.length) {
                setStatus('ok', 'No safe non-overlapping changes were available to apply.');
                if (dropped > 0) {
                    showToast(`${dropped} conflicting change${dropped !== 1 ? 's were' : ' was'} skipped.`, 'warning');
                }
                return;
            }

            const newCss = CSSRefiner.Utils.applyEdits(_workingCss, allEdits);
            await saveStyles(newCss);
            _workingCss = newCss;
            _rawCss = _workingCss;

            // Re-run full analysis on cleaned CSS
            _results = analyze(_workingCss, null);
            renderResults(_results);
            const remaining = totalSuggestions(_results);
            setStatus('ok', `Applied. ${remaining} suggestion${remaining !== 1 ? 's' : ''} remaining.`);
            if (dropped > 0) {
                showToast(`${dropped} conflicting safe change${dropped !== 1 ? 's were' : ' was'} skipped.`, 'warning');
            }
            showToast('Auto-Refine applied successfully.', 'success');
        } catch (err) {
            setStatus('error', 'Apply failed: ' + err.message);
            showToast('Failed to apply: ' + err.message, 'error');
        }
    }

    // ── Apply Selected ────────────────────────────────────────────────────────

    async function applySelected() {
        const checked = getCheckedSuggestions();
        if (!checked.length) return;

        const tokenSuggestions = checked.filter(s => s._analyzerId === 'design-tokens');
        const editSuggestions = checked.filter(s => Array.isArray(s.edits) && s.edits.length > 0);

        setStatus('loading', 'Applying…');
        _btnApplySelected.disabled = true;

        try {
            // Apply regular CSS edits
            if (editSuggestions.length) {
                const { edits: allEdits, dropped } = collectNonOverlappingEdits(editSuggestions);
                if (!allEdits.length && dropped > 0) {
                    throw new Error('All selected edits conflicted with each other.');
                }

                const newCss = CSSRefiner.Utils.applyEdits(_workingCss, allEdits);
                await saveStyles(newCss);
                _workingCss = newCss;
                _rawCss = _workingCss;

                if (dropped > 0) {
                    showToast(`${dropped} conflicting change${dropped !== 1 ? 's were' : ' was'} skipped.`, 'warning');
                }
            }

            // Write design token variables to :root
            if (tokenSuggestions.length) {
                for (const s of tokenSuggestions) {
                    // suggestedValue = '--var-name', _rootDecl = '--var-name: value;'
                    const name = s.suggestedValue;
                    const value = s._rootDecl ? s._rootDecl.replace(/^[^:]+:\s*/, '').replace(/;$/, '').trim() : null;
                    if (name && value) {
                        await saveRootVariable(name, value);
                    }
                }

                // Reload because setRootVariables mutates the stylesheet on the server.
                _workingCss = await loadStyles();
                _rawCss = _workingCss;
            }

            // Re-analyze
            _results = analyze(_workingCss, null);
            renderResults(_results);
            const remaining = totalSuggestions(_results);
            setStatus('ok', `Applied. ${remaining} suggestion${remaining !== 1 ? 's' : ''} remaining.`);
            showToast('Changes applied successfully.', 'success');
        } catch (err) {
            setStatus('error', 'Apply failed: ' + err.message);
            showToast('Failed to apply: ' + err.message, 'error');
        } finally {
            _btnApplySelected.disabled = false;
        }
    }

    function getCheckedSuggestions() {
        return $$('.optimize-suggestion', _resultsEl)
            .filter(el => el.querySelector('.optimize-suggestion__check')?.checked)
            .map(el => {
                const aId = el.dataset.analyzerId;
                const sId = el.dataset.suggestionId;
                const group = _results.find(r => r.analyzerId === aId);
                const suggestion = group?.suggestions.find(s => s.id === sId);
                if (!suggestion) return null;
                return { ...suggestion, _analyzerId: aId };
            })
            .filter(Boolean);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    function renderResults(results) {
        _resultsEl.innerHTML = '';
        _emptyEl.style.display = 'none';

        const hasAny = results.some(r => r.suggestions.length > 0);
        if (!hasAny) {
            _emptyEl.style.display = '';
            _emptyEl.querySelector('p').textContent = 'No issues found — CSS looks clean!';
            _resultsEl.style.display = 'none';
            hideApplyBar();
            return;
        }

        _resultsEl.style.display = '';

        results.forEach(({ analyzerId, suggestions }) => {
            if (!suggestions.length) return;
            const section = buildAnalyzerSection(analyzerId, suggestions);
            _resultsEl.appendChild(section);
        });

        updateApplyBar();
    }

    function buildAnalyzerSection(analyzerId, suggestions) {
        const isReviewOnly = REVIEW_ONLY.has(analyzerId);
        const label = ANALYZER_LABELS[analyzerId] || analyzerId;

        const section = document.createElement('div');
        section.className = 'optimize-analyzer-section';
        section.dataset.analyzerId = analyzerId;

        // Section header
        const header = document.createElement('div');
        header.className = 'optimize-analyzer-header';
        header.innerHTML = `
            <button type="button" class="optimize-analyzer-toggle" aria-expanded="true">
                <svg class="optimize-analyzer-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="optimize-analyzer-label">${escHtml(label)}</span>
                <span class="optimize-analyzer-count">(${suggestions.length})</span>
                ${isReviewOnly ? '<span class="optimize-analyzer-badge optimize-analyzer-badge--warn">review</span>' : ''}
            </button>
            <div class="optimize-analyzer-actions">
                <button type="button" class="admin-btn admin-btn--small admin-btn--ghost optimize-btn-select-all" data-analyzer="${escHtml(analyzerId)}">Select All</button>
                <button type="button" class="admin-btn admin-btn--small admin-btn--ghost optimize-btn-skip-all" data-analyzer="${escHtml(analyzerId)}">Skip All</button>
            </div>
        `;

        const list = document.createElement('div');
        list.className = 'optimize-suggestion-list';

        suggestions.forEach(s => {
            list.appendChild(buildSuggestionItem(analyzerId, s));
        });

        header.querySelector('.optimize-analyzer-toggle').addEventListener('click', function () {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            list.style.display = expanded ? 'none' : '';
        });

        header.querySelector('.optimize-btn-select-all')?.addEventListener('click', () => {
            $$('.optimize-suggestion__check', list).forEach(cb => { cb.checked = true; });
            updateApplyBar();
        });

        header.querySelector('.optimize-btn-skip-all')?.addEventListener('click', () => {
            $$('.optimize-suggestion__check', list).forEach(cb => { cb.checked = false; });
            updateApplyBar();
        });

        section.appendChild(header);
        section.appendChild(list);
        return section;
    }

    function buildSuggestionItem(analyzerId, s) {
        const isDesignToken = analyzerId === 'design-tokens';
        const item = document.createElement('div');
        item.className = 'optimize-suggestion';
        item.dataset.analyzerId = analyzerId;
        item.dataset.suggestionId = s.id;

        item.innerHTML = `
            <label class="optimize-suggestion__label">
                <input type="checkbox" class="optimize-suggestion__check" ${s.checked ? 'checked' : ''}>
                <span class="optimize-suggestion__desc">${escHtml(s.description || s.id)}</span>
            </label>
            <button type="button" class="admin-btn admin-btn--small admin-btn--ghost optimize-btn-diff">diff</button>
            <div class="optimize-suggestion__diff" style="display:none"></div>
            ${isDesignToken ? buildDesignTokenInput(s) : ''}
        `;

        // Diff toggle
        const diffBtn = item.querySelector('.optimize-btn-diff');
        const diffEl  = item.querySelector('.optimize-suggestion__diff');
        diffBtn.addEventListener('click', () => {
            const open = diffEl.style.display !== 'none';
            diffEl.style.display = open ? 'none' : '';
            if (!open && !diffEl._rendered) {
                diffEl._rendered = true;
                CSSRefiner.UI.DiffView.renderDiff(diffEl, s.diff || {});
            }
        });

        // Design token write-to-root button
        const writeBtn = item.querySelector('.optimize-btn-write-token');
        if (writeBtn) {
            writeBtn.addEventListener('click', async () => {
                const nameInput = item.querySelector('.optimize-design-token-name');
                const name = nameInput?.value?.trim();
                const value = nameInput?.dataset?.rawValue;
                if (!name || !value) return;
                writeBtn.disabled = true;
                writeBtn.textContent = '…';
                try {
                    await saveRootVariable(name, value);
                    _workingCss = await loadStyles();
                    _rawCss = _workingCss;
                    writeBtn.textContent = 'Written!';
                    writeBtn.classList.add('admin-btn--success');
                    showToast(`${name} written to :root`, 'success');
                } catch (err) {
                    writeBtn.disabled = false;
                    writeBtn.textContent = 'Write to :root';
                    showToast('Failed: ' + err.message, 'error');
                }
            });
        }

        // Checkbox change -> update apply bar
        item.querySelector('.optimize-suggestion__check').addEventListener('change', updateApplyBar);

        return item;
    }

    function buildDesignTokenInput(s) {
        // suggestedValue = '--color-primary', _rootDecl = '--color-primary: #3a7bd5;'
        const varName = s.suggestedValue || '--token-' + s.id;
        // Extract the raw value from _rootDecl: '--varname: value;' -> 'value'
        const rawValue = s._rootDecl ? s._rootDecl.replace(/^[^:]+:\s*/, '').replace(/;$/, '').trim() : '';
        return `
            <div class="optimize-design-token-row">
                <code class="optimize-design-token-value">${escHtml(rawValue)}</code>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <input type="text" class="admin-input optimize-design-token-name" value="${escHtml(varName)}" placeholder="--variable-name"
                       data-raw-value="${escHtml(rawValue)}">
                <button type="button" class="admin-btn admin-btn--small admin-btn--secondary optimize-btn-write-token">Write to :root</button>
            </div>
        `;
    }

    // ── Apply bar ─────────────────────────────────────────────────────────────

    function updateApplyBar() {
        const count = $$('.optimize-suggestion__check:checked', _resultsEl).length;
        if (count > 0) {
            _selectedCountEl.textContent = `${count} change${count !== 1 ? 's' : ''} selected`;
            _applyBar.style.display = '';
            _btnApplySelected.textContent = `Apply Selected (${count})`;
        } else {
            hideApplyBar();
        }
    }

    function hideApplyBar() {
        _applyBar.style.display = 'none';
    }

    // ── Modal ─────────────────────────────────────────────────────────────────

    function showAutoRefineModal(results) {
        _modalBody.innerHTML = '';
        let total = 0;
        results.forEach(({ analyzerId, suggestions }) => {
            if (!suggestions.length) return;
            const row = document.createElement('div');
            row.className = 'optimize-modal-row';
            row.innerHTML = `<strong>${suggestions.length}</strong>&nbsp;${escHtml(ANALYZER_LABELS[analyzerId] || analyzerId)} changes`;
            _modalBody.appendChild(row);
            total += suggestions.length;
        });
        _modalConfirm.textContent = `Apply ${total} change${total !== 1 ? 's' : ''}`;
        _modal.style.display = '';
        setStatus('', '');
    }

    function closeModal() {
        _modal.style.display = 'none';
        _autoRefinePending = null;
        setStatus('', '');
    }

    function collectNonOverlappingEdits(suggestions) {
        const seen = new Set();
        const rawEdits = suggestions.flatMap(s => (s.edits || []).map(edit => ({
            start: edit.start,
            end: edit.end,
            replacement: edit.replacement,
            suggestionId: s.id,
        })));

        const validEdits = rawEdits.filter(edit => Number.isInteger(edit.start)
            && Number.isInteger(edit.end)
            && edit.start >= 0
            && edit.end >= edit.start)
            .filter(edit => {
                const key = `${edit.start}:${edit.end}:${edit.replacement}`;
                if (seen.has(key)) {
                    return false;
                }
                seen.add(key);
                return true;
            })
            .sort((a, b) => b.start - a.start || b.end - a.end);

        const accepted = [];
        let dropped = rawEdits.length - validEdits.length;

        for (const edit of validEdits) {
            const overlaps = accepted.some(existing => CSSRefiner.Utils.editsOverlap(existing, edit));
            if (overlaps) {
                dropped += 1;
                continue;
            }
            accepted.push(edit);
        }

        return {
            edits: accepted.map(({ start, end, replacement }) => ({ start, end, replacement })),
            dropped,
        };
    }

    // ── Misc ──────────────────────────────────────────────────────────────────

    function resetResults() {
        _results = [];
        _rawCss = '';
        _workingCss = '';
        _resultsEl.innerHTML = '';
        _resultsEl.style.display = 'none';
        _emptyEl.style.display = '';
        _emptyEl.querySelector('p').textContent = 'Click Analyze to scan your CSS.';
        _btnReset.style.display = 'none';
        hideApplyBar();
        setStatus('', '');
    }

    function setStatus(type, msg) {
        if (!_statusEl) return;
        _statusEl.textContent = msg;
        _statusEl.className = 'optimize-toolbar__status' + (type ? ' optimize-toolbar__status--' + type : '');
    }

    function showToast(msg, type = 'success') {
        if (window.AdminToast?.show) {
            window.AdminToast.show(msg, type);
        } else if (window.showAdminToast) {
            window.showAdminToast(msg, type);
        }
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
