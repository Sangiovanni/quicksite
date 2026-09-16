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

    /**
     * Resolve one admin string by its FULL dot path, from the sub-trees
     * optimize.php emits. A path that resolves to nothing returns THE PATH
     * ITSELF, so an unset string shows on screen instead of English.
     *
     * @param {string} path      e.g. 'optimize.cssRefiner.selectAll'
     * @param {Object} [params]  :name markers, as PHP's t() does
     * @returns {string}
     */
    function t(path, params) {
        let node = window.QS_OPTIMIZE_I18N || {};
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

    /** Human-readable label for an analyzer id. */
    function analyzerLabel(analyzerId) {
        const key = 'optimize.cssRefiner.analyzers.' + analyzerId;
        const label = t(key);
        return label === key ? analyzerId : label;
    }

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
        if (!res.ok) throw new Error(t('optimize.cssRefiner.errGetStyles', { error: res.data?.error || res.status }));
        const content = res.data?.data?.content;
        if (!content && content !== '') throw new Error(t('optimize.cssRefiner.errGetStylesEmpty'));
        return content;
    }

    async function saveStyles(css) {
        const res = await QuickSiteAPI.request('editStyles', 'POST', { content: css });
        if (!res.ok) throw new Error(t('optimize.cssRefiner.errEditStyles', { error: res.data?.error || res.status }));
        return res.data;
    }

    async function saveRootVariable(name, value) {
        const res = await QuickSiteAPI.request('setRootVariables', 'POST', { variables: { [name]: value } });
        if (!res.ok) throw new Error(t('optimize.cssRefiner.errSetRootVariables', { name: name, error: res.data?.error || res.status }));
        return res.data;
    }

    // ── Analysis ──────────────────────────────────────────────────────────────

    async function runFullAnalysis() {
        setStatus('loading', t('optimize.cssRefiner.statusAnalyzing'));
        _btnAnalyze.disabled = true;
        _btnAutoRefine.disabled = true;

        try {
            _rawCss = await loadStyles();
            _workingCss = _rawCss;
            _results = analyze(_workingCss, null);
            renderResults(_results);
            setStatus('ok', t('optimize.cssRefiner.statusSuggestions',
                { count: totalSuggestions(_results) }));
            _btnReset.style.display = '';
        } catch (err) {
            setStatus('error', t('optimize.cssRefiner.statusAnalysisFailed',
                { message: err.message }));
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
        setStatus('loading', t('optimize.cssRefiner.statusPreparing'));
        _btnAnalyze.disabled = true;
        _btnAutoRefine.disabled = true;

        try {
            _rawCss = await loadStyles();
            _workingCss = _rawCss;
            const safeResults = analyze(_workingCss, SAFE_ANALYZERS);

            if (totalSuggestions(safeResults) === 0) {
                setStatus('ok', t('optimize.cssRefiner.statusNoSafeChanges'));
                return;
            }

            _autoRefinePending = safeResults;
            showAutoRefineModal(safeResults);
        } catch (err) {
            setStatus('error', t('optimize.cssRefiner.statusFailed', { message: err.message }));
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
        setStatus('loading', t('optimize.cssRefiner.statusApplying'));

        try {
            const applicableSuggestions = pendingResults
                .flatMap(r => r.suggestions.map(s => ({ ...s, _analyzerId: r.analyzerId })))
                .filter(s => s.enabled !== false && !REVIEW_ONLY.has(s._analyzerId));
            const { edits: allEdits, dropped } = collectNonOverlappingEdits(applicableSuggestions);

            if (!allEdits.length) {
                setStatus('ok', t('optimize.cssRefiner.statusNoNonOverlapping'));
                if (dropped > 0) {
                    showToast(t(dropped !== 1
                        ? 'optimize.cssRefiner.toastSkippedMany'
                        : 'optimize.cssRefiner.toastSkippedOne', { count: dropped }), 'warning');
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
            setStatus('ok', t(remaining !== 1
                ? 'optimize.cssRefiner.statusAppliedMany'
                : 'optimize.cssRefiner.statusAppliedOne', { count: remaining }));
            if (dropped > 0) {
                showToast(t(dropped !== 1
                        ? 'optimize.cssRefiner.toastSkippedSafeMany'
                        : 'optimize.cssRefiner.toastSkippedSafeOne', { count: dropped }), 'warning');
            }
            showToast(t('optimize.cssRefiner.toastAutoApplied'), 'success');
        } catch (err) {
            setStatus('error', t('optimize.cssRefiner.statusApplyFailed', { message: err.message }));
            showToast(t('optimize.cssRefiner.toastApplyFailed', { message: err.message }), 'error');
        }
    }

    // ── Apply Selected ────────────────────────────────────────────────────────

    async function applySelected() {
        const checked = getCheckedSuggestions();
        if (!checked.length) return;

        const tokenSuggestions = checked.filter(s => s._analyzerId === 'design-tokens');
        const editSuggestions = checked.filter(s => Array.isArray(s.edits) && s.edits.length > 0);

        setStatus('loading', t('optimize.cssRefiner.statusApplying'));
        _btnApplySelected.disabled = true;

        try {
            // Apply regular CSS edits
            if (editSuggestions.length) {
                const { edits: allEdits, dropped } = collectNonOverlappingEdits(editSuggestions);
                if (!allEdits.length && dropped > 0) {
                    throw new Error(t('optimize.cssRefiner.statusAllConflicted'));
                }

                const newCss = CSSRefiner.Utils.applyEdits(_workingCss, allEdits);
                await saveStyles(newCss);
                _workingCss = newCss;
                _rawCss = _workingCss;

                if (dropped > 0) {
                    showToast(t(dropped !== 1
                        ? 'optimize.cssRefiner.toastSkippedMany'
                        : 'optimize.cssRefiner.toastSkippedOne', { count: dropped }), 'warning');
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
            setStatus('ok', t(remaining !== 1
                ? 'optimize.cssRefiner.statusAppliedMany'
                : 'optimize.cssRefiner.statusAppliedOne', { count: remaining }));
            showToast(t('optimize.cssRefiner.toastApplied'), 'success');
        } catch (err) {
            setStatus('error', t('optimize.cssRefiner.statusApplyFailed', { message: err.message }));
            showToast(t('optimize.cssRefiner.toastApplyFailed', { message: err.message }), 'error');
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
        QSDom.clear(_resultsEl);
        _emptyEl.style.display = 'none';

        const hasAny = results.some(r => r.suggestions.length > 0);
        if (!hasAny) {
            _emptyEl.style.display = '';
            _emptyEl.querySelector('p').textContent = t('optimize.cssRefiner.noIssues');
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
        const label = analyzerLabel(analyzerId);

        const section = document.createElement('div');
        section.className = 'optimize-analyzer-section';
        section.dataset.analyzerId = analyzerId;

        // Section header
        const header = document.createElement('div');
        header.className = 'optimize-analyzer-header';
        const arrow = QSDom.iconEl(QuickSiteUtils.ICON_PATHS.chevronDown, 16, 'optimize-analyzer-arrow');
        header.appendChild(QSDom.el('button', {
            type: 'button',
            class: 'optimize-analyzer-toggle',
            'aria-expanded': 'true'
        }, [
            arrow,
            QSDom.el('span', { class: 'optimize-analyzer-label', text: label }),
            QSDom.el('span', {
                class: 'optimize-analyzer-count',
                text: '(' + suggestions.length + ')'
            }),
            isReviewOnly ? QSDom.el('span', {
                class: 'optimize-analyzer-badge optimize-analyzer-badge--warn',
                text: t('optimize.cssRefiner.reviewBadge')
            }) : null
        ]));
        header.appendChild(QSDom.el('div', { class: 'optimize-analyzer-actions' }, [
            QSDom.el('button', {
                type: 'button',
                class: 'admin-btn admin-btn--small admin-btn--ghost optimize-btn-select-all',
                'data-analyzer': analyzerId,
                text: t('optimize.cssRefiner.selectAll')
            }),
            QSDom.el('button', {
                type: 'button',
                class: 'admin-btn admin-btn--small admin-btn--ghost optimize-btn-skip-all',
                'data-analyzer': analyzerId,
                text: t('optimize.cssRefiner.skipAll')
            })
        ]));

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

        const check = QSDom.el('input', {
            type: 'checkbox', class: 'optimize-suggestion__check'
        });
        check.checked = !!s.checked;
        item.appendChild(QSDom.el('label', { class: 'optimize-suggestion__label' }, [
            check,
            QSDom.el('span', {
                class: 'optimize-suggestion__desc',
                text: s.description || s.id
            })
        ]));
        item.appendChild(QSDom.el('button', {
            type: 'button',
            class: 'admin-btn admin-btn--small admin-btn--ghost optimize-btn-diff',
            text: t('optimize.cssRefiner.diff')
        }));
        item.appendChild(QSDom.el('div', {
            class: 'optimize-suggestion__diff', style: 'display:none'
        }));
        if (isDesignToken) item.appendChild(_renderDesignTokenRow(s));

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
                writeBtn.textContent = t('optimize.cssRefiner.writing');
                try {
                    await saveRootVariable(name, value);
                    _workingCss = await loadStyles();
                    _rawCss = _workingCss;
                    writeBtn.textContent = t('optimize.cssRefiner.written');
                    writeBtn.classList.add('admin-btn--success');
                    showToast(t('optimize.cssRefiner.tokenWritten', { name: name }), 'success');
                } catch (err) {
                    writeBtn.disabled = false;
                    writeBtn.textContent = t('optimize.cssRefiner.writeToRoot');
                    showToast(t('optimize.cssRefiner.writeFailed', { message: err.message }), 'error');
                }
            });
        }

        // Checkbox change -> update apply bar
        item.querySelector('.optimize-suggestion__check').addEventListener('change', updateApplyBar);

        return item;
    }

    /**
     * The "write this value to :root as a variable" row of a design-token
     * suggestion.
     * @returns {HTMLElement} one .optimize-design-token-row
     */
    function _renderDesignTokenRow(s) {
        // suggestedValue = '--color-primary', _rootDecl = '--color-primary: #3a7bd5;'
        const varName = s.suggestedValue || '--token-' + s.id;
        // Extract the raw value from _rootDecl: '--varname: value;' -> 'value'
        const rawValue = s._rootDecl ? s._rootDecl.replace(/^[^:]+:\s*/, '').replace(/;$/, '').trim() : '';
        return QSDom.el('div', { class: 'optimize-design-token-row' }, [
            QSDom.el('code', { class: 'optimize-design-token-value', text: rawValue }),
            QSDom.iconEl(QuickSiteUtils.ICON_PATHS.arrowRight, 14),
            QSDom.el('input', {
                type: 'text',
                class: 'admin-input optimize-design-token-name',
                value: varName,
                placeholder: t('optimize.cssRefiner.tokenNamePlaceholder'),
                dataset: { rawValue: rawValue }
            }),
            QSDom.el('button', {
                type: 'button',
                class: 'admin-btn admin-btn--small admin-btn--secondary optimize-btn-write-token',
                text: t('optimize.cssRefiner.writeToRoot')
            })
        ]);
    }

    // ── Apply bar ─────────────────────────────────────────────────────────────

    function updateApplyBar() {
        const count = $$('.optimize-suggestion__check:checked', _resultsEl).length;
        if (count > 0) {
            _selectedCountEl.textContent = t(count !== 1
                ? 'optimize.cssRefiner.selectedMany'
                : 'optimize.cssRefiner.selectedOne', { count: count });
            _applyBar.style.display = '';
            _btnApplySelected.textContent = t('optimize.cssRefiner.applySelectedCount', { count: count });
        } else {
            hideApplyBar();
        }
    }

    function hideApplyBar() {
        _applyBar.style.display = 'none';
    }

    // ── Modal ─────────────────────────────────────────────────────────────────

    function showAutoRefineModal(results) {
        QSDom.clear(_modalBody);
        let total = 0;
        results.forEach(({ analyzerId, suggestions }) => {
            if (!suggestions.length) return;
            const row = QSDom.el('div', { class: 'optimize-modal-row' }, [
                QSDom.el('strong', { text: String(suggestions.length) }),
                ' ' + t('optimize.cssRefiner.modalRow', { label: analyzerLabel(analyzerId) })
            ]);
            _modalBody.appendChild(row);
            total += suggestions.length;
        });
        _modalConfirm.textContent = t(total !== 1
            ? 'optimize.cssRefiner.applyTotalMany'
            : 'optimize.cssRefiner.applyTotalOne', { count: total });
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
        QSDom.clear(_resultsEl);
        _resultsEl.style.display = 'none';
        _emptyEl.style.display = '';
        _emptyEl.querySelector('p').textContent = t('optimize.cssRefiner.emptyState');
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

    // ── Boot ──────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
