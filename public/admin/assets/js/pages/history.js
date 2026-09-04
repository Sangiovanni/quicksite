/**
 * QuickSite Admin — Command History page (/admin/history)
 *
 * Browses `getCommandHistory` for the currently edited project, exports what is
 * on screen as CSV, and clears the stored trail through `clearCommandHistory`.
 *
 * ⚠ REWRITTEN IN S6.6 FROM innerHTML STRING-GLUE TO createElement.
 * Every row of this table is built from a LOG RECORD, and a log record is the
 * one thing on the page whose content came from somebody else's request. The
 * old version interpolated those values into HTML strings — mostly escaped, but
 * `entry.command` went into an href unescaped, which was safe only because the
 * dispatcher's route allowlist runs before the logging callback is installed, so
 * the name can only ever be one of the real command names. That is a property of
 * a different file, held in place by the order of two blocks in the dispatcher.
 * Building the DOM instead means the page does not depend on it (CLAUDE.md).
 *
 * Dependencies: QuickSiteAdmin (admin.js), QSDom (core/dom.js), QuickSiteUtils.
 *
 * @version 2.0.0
 */

(function () {
    'use strict';

    const el = window.QSDom.el;
    const clearNode = window.QSDom.clear;

    // State
    let currentPage = 1;
    const pageSize = 50;
    let totalEntries = 0;
    let totalPages = 0;
    const entryCache = new Map();
    // The records currently on screen. The CSV export is built from THESE — what
    // you exported is what you were looking at, filters and page included —
    // rather than from a second unfiltered fetch, which is how the previous
    // export (QuickSiteAdmin.exportHistory, removed) came to disagree with the
    // page it was launched from.
    let loadedEntries = [];

    // DOM elements (cached after init)
    let filterDate, filterCommand, filterStatus;
    let historyContent, pagination, pageInfo, prevBtn, nextBtn;
    let detailModal, detailContent;
    let clearModal, clearBefore, clearPreview, clearConfirmBtn;

    /**
     * Dotted-path lookup over the page's own i18n bundle, then the layout's
     * subset, then the English literal.
     *
     * QS_HISTORY_I18N is emitted by history.php. The layout's
     * QUICKSITE_CONFIG.translations carries only a few branches (common,
     * dashboard, …) and never carried `history`, so before S6.6 every JS-built
     * label here resolved to its fallback — a French panel showed English rows.
     * The layout lookup is kept second so `common.*` still resolves if the page
     * bundle ever stops carrying it.
     */
    function t(path, fallback) {
        const parts = path.split('.');
        for (const root of [window.QS_HISTORY_I18N, window.QUICKSITE_CONFIG?.translations]) {
            let node = root;
            let ok = true;
            for (const p of parts) {
                if (!node || typeof node !== 'object') { ok = false; break; }
                node = node[p];
            }
            if (ok && typeof node === 'string' && node !== '') return node;
        }
        return fallback;
    }

    // =======================================================================
    // Publisher — the field this slice exists to fix
    // =======================================================================
    /**
     * A record's publisher is `{user_id, token_name}`.
     *
     * ⚠ `token_name` IS THE USER'S DISPLAY NAME, not an API token's name. It is
     * written as `$tokenInfo['name'] ?? 'Unknown'`, and `$tokenInfo` is the
     * resolved user — the token stopped carrying a name in beta.10. The key is a
     * leftover from when it did; it is read here for what it actually holds.
     *
     * The old renderer passed this object straight to escapeHtml, which assigns
     * it to textContent, which stringifies it — hence `[object Object]` on every
     * row. The user was always in the record.
     *
     * A very old record may carry a plain string instead. Measured on this
     * install: 6,805 records, all of them the object shape, none a string. The
     * string branch is kept anyway because it costs one line and another
     * install's logs are not this install's.
     */
    function publisherName(entry) {
        const p = entry?.publisher;
        if (!p) return null;
        if (typeof p === 'string') return p;
        return p.token_name || null;
    }

    function publisherId(entry) {
        const p = entry?.publisher;
        if (!p || typeof p === 'string') return null;
        return p.user_id || null;
    }

    /** "Name" with the user id as the hover title, or the id alone, or a dash. */
    function renderPublisherCell(entry) {
        const name = publisherName(entry);
        const id = publisherId(entry);
        if (!name && !id) return el('span', { class: 'admin-muted', text: '—' });
        if (name && id) return el('span', { text: name, title: id });
        return el('span', { text: name || id });
    }

    // =======================================================================
    // Load
    // =======================================================================
    function currentFilters() {
        const q = { page: currentPage, limit: pageSize };
        const date = filterDate?.value || '';
        if (date) { q.start_date = date; q.end_date = date; }
        const cmd = filterCommand?.value.trim() || '';
        if (cmd) q.command = cmd;
        const status = filterStatus?.value || '';
        if (status) q.status = status;
        return q;
    }

    async function loadHistory() {
        clearNode(historyContent);
        historyContent.appendChild(
            el('div', { class: 'admin-loading' }, [
                el('span', { class: 'admin-spinner' }),
                el('span', { text: t('common.loading', 'Loading...') })
            ])
        );

        try {
            const result = await QuickSiteAdmin.apiRequest(
                'getCommandHistory', 'GET', null, [], currentFilters()
            );

            if (result.ok && result.data?.data) {
                const entries = result.data.data.entries || [];
                const pg = result.data.data.pagination || {};

                totalEntries = pg.total || entries.length;
                totalPages = pg.pages || Math.ceil(totalEntries / pageSize);
                currentPage = pg.page || currentPage;
                loadedEntries = entries;

                if (entries.length === 0) { renderEmpty(); return; }
                renderHistoryTable(entries);
                updatePagination();
            } else {
                loadedEntries = [];
                renderError(result.data?.message || 'Unknown error');
            }
        } catch (error) {
            loadedEntries = [];
            renderError(error.message);
        }
        syncExportState();
    }

    function renderEmpty() {
        loadedEntries = [];
        clearNode(historyContent);
        historyContent.appendChild(
            el('div', { class: 'admin-empty' }, [
                el('p', {
                    class: 'admin-empty__title',
                    text: t('history.noHistory', 'No commands have been executed yet')
                }),
                el('p', {
                    class: 'admin-empty__text',
                    text: t('history.noHistoryFiltered', 'No command history found for the selected filters.')
                })
            ])
        );
        pagination.style.display = 'none';
        syncExportState();
    }

    function renderError(message) {
        clearNode(historyContent);
        historyContent.appendChild(
            el('div', { class: 'admin-alert admin-alert--error' }, [
                t('history.errors.loadFailed', 'Failed to load history:') + ' ',
                String(message)
            ])
        );
        pagination.style.display = 'none';
    }

    function isSuccessEntry(entry) {
        const s = entry.result?.http_status ?? entry.result?.status;
        return typeof s === 'number' ? (s >= 200 && s < 300) : s === 'success';
    }

    function renderRow(entry) {
        const ok = isSuccessEntry(entry);
        const commandUrl = window.QUICKSITE_CONFIG?.commandUrl || '';
        const consoleOn = window.QUICKSITE_CONFIG?.consoleEnabled !== false;

        // The command cell links into the console — but only when there IS a
        // console. With it turned off (S6.5) that link goes to a page that
        // renders a "turned off" notice, so the name is shown as plain text
        // instead of a link that leads nowhere useful.
        const nameNode = el('code', { text: entry.command });
        const commandCell = (consoleOn && commandUrl)
            ? el('a', { class: 'admin-link', href: commandUrl + '/' + encodeURIComponent(entry.command) }, [nameNode])
            : nameNode;

        return el('tr', null, [
            el('td', null, [el('small', { text: new Date(entry.timestamp).toLocaleString() })]),
            el('td', null, [commandCell]),
            el('td', null, [methodBadge(entry.method)]),
            el('td', null, [renderPublisherCell(entry)]),
            el('td', null, [
                el('span', {
                    class: 'badge ' + (ok ? 'badge--success' : 'badge--error'),
                    text: ok ? t('history.filters.success', 'Success') : t('history.filters.error', 'Error')
                })
            ]),
            el('td', { text: (entry.duration_ms ?? '?') + 'ms' }),
            el('td', null, [
                el('button', {
                    class: 'admin-btn admin-btn--ghost admin-btn--sm',
                    type: 'button',
                    title: t('history.columns.details', 'Details'),
                    dataset: { action: 'show-detail', entryId: String(entry.id) }
                }, [
                    window.QSDom.svgIcon('M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z', 16)
                ])
            ])
        ]);
    }

    function methodBadge(method) {
        const m = String(method || '');
        return el('span', { class: 'badge badge--' + m.toLowerCase(), text: m });
    }

    function renderHistoryTable(entries) {
        entryCache.clear();

        const head = el('thead', null, [
            el('tr', null, [
                el('th', { text: t('history.columns.timestamp', 'Timestamp') }),
                el('th', { text: t('history.columns.command', 'Command') }),
                el('th', { text: t('history.columns.method', 'Method') }),
                el('th', { text: t('history.columns.user', 'User') }),
                el('th', { text: t('history.columns.status', 'Status') }),
                el('th', { text: t('history.columns.duration', 'Duration') }),
                el('th', { text: t('history.columns.details', 'Details') })
            ])
        ]);

        const body = el('tbody');
        entries.forEach(entry => {
            entryCache.set(String(entry.id), entry);
            body.appendChild(renderRow(entry));
        });

        clearNode(historyContent);
        historyContent.appendChild(
            el('div', { class: 'admin-table-wrapper' }, [
                el('table', { class: 'admin-table' }, [head, body])
            ])
        );
    }

    function updatePagination() {
        if (totalPages <= 1) { pagination.style.display = 'none'; return; }
        pagination.style.display = 'flex';
        pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages
            + ' (' + totalEntries + ' entries)';
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    function changePage(delta) {
        currentPage += delta;
        loadHistory();
    }

    // =======================================================================
    // Detail modal
    // =======================================================================
    function detailRow(label, node) {
        return el('div', { class: 'admin-detail-item' }, [
            el('label', { text: label }),
            node
        ]);
    }

    function showDetail(entry) {
        if (!entry) return;
        const name = publisherName(entry);
        const id = publisherId(entry);

        const grid = el('div', { class: 'admin-detail-grid' }, [
            detailRow(t('history.detail.id', 'ID'), el('code', { text: String(entry.id ?? '') })),
            detailRow(t('history.detail.timestamp', 'Timestamp'),
                el('span', { text: new Date(entry.timestamp).toLocaleString() })),
            detailRow(t('history.detail.command', 'Command'), el('code', { text: entry.command })),
            detailRow(t('history.detail.method', 'Method'), methodBadge(entry.method)),
            detailRow(t('history.detail.duration', 'Duration'),
                el('span', { text: (entry.duration_ms ?? '?') + 'ms' })),
            // Both halves, separately labelled: the display name is what a person
            // recognises, the user id is what survives a rename.
            detailRow(t('history.detail.publisher', 'Publisher'),
                el('span', { text: name || '—' })),
            detailRow(t('history.detail.userId', 'User ID'),
                id ? el('code', { text: id }) : el('span', { class: 'admin-muted', text: '—' }))
        ]);

        clearNode(detailContent);
        detailContent.appendChild(grid);

        // ⚠ PARAMETERS COME BEFORE THE BODY, because for much of the command
        // surface they ARE the request: `getStructure/page/home` and
        // `getRoutes?verbose=1` carry no body at all, and before S6.6 those
        // records showed an empty body and nothing else. `params.url` is the
        // ordered path segments, `params.query` the query string. Rendered only
        // when there is something to show, so a body-only command does not grow
        // an empty section.
        if (entry.params && (entry.params.url || entry.params.query)) {
            detailContent.appendChild(el('h4', { text: t('history.detail.params', 'Parameters') }));
            detailContent.appendChild(
                el('div', { class: 'admin-code' }, [
                    el('pre', { text: JSON.stringify(entry.params, null, 2) })
                ])
            );
        }

        detailContent.appendChild(el('h4', { text: t('history.detail.requestBody', 'Request Body') }));
        detailContent.appendChild(
            el('div', { class: 'admin-code' }, [
                el('pre', { text: JSON.stringify(entry.body, null, 2) })
            ])
        );
        detailContent.appendChild(el('h4', { text: t('history.detail.response', 'Response') }));
        detailContent.appendChild(
            el('div', { class: 'admin-code admin-code--response' }, [
                el('pre', { text: JSON.stringify(entry.result, null, 2) })
            ])
        );

        detailModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeDetail() {
        if (!detailModal) return;
        detailModal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // =======================================================================
    // CSV export
    // =======================================================================
    /**
     * One CSV cell, RFC 4180 quoted, with the spreadsheet-formula guard.
     *
     * TWO SEPARATE HAZARDS, and they need different treatment:
     *
     *   1. STRUCTURE — a value containing a comma, a double quote or a newline
     *      would otherwise split one cell into several or end the record early.
     *      A request body routinely contains all three. Every cell is therefore
     *      wrapped in double quotes with internal quotes doubled, unconditionally
     *      rather than only when it looks necessary.
     *
     *   2. FORMULA INJECTION — a cell whose first character is =, +, -, @, TAB or
     *      CR is executed as a formula by Excel / LibreOffice / Sheets when the
     *      file is opened. Quoting does NOT prevent this: the quotes are consumed
     *      by the CSV parser and the spreadsheet then sees the bare value. The
     *      cell is prefixed with an apostrophe, which those applications read as
     *      "this is text". A command body is attacker-supplied and lands in this
     *      export verbatim, so this is a real path, not a theoretical one.
     */
    function csvCell(value) {
        let s = (value === null || value === undefined) ? '' : String(value);
        if (/^[=+\-@\t\r]/.test(s)) {
            s = "'" + s;
        }
        return '"' + s.replace(/"/g, '""') + '"';
    }

    function buildCsv(entries) {
        const header = [
            'timestamp', 'command', 'method', 'user', 'user_id',
            'http_status', 'code', 'duration_ms', 'params', 'body', 'result'
        ];
        const lines = [header.map(csvCell).join(',')];

        entries.forEach(entry => {
            lines.push([
                entry.timestamp,
                entry.command,
                entry.method,
                publisherName(entry),
                publisherId(entry),
                entry.result?.http_status ?? entry.result?.status ?? '',
                entry.result?.code ?? '',
                entry.duration_ms,
                // null for a command with no parameters, so the cell is empty
                // rather than carrying the word "null".
                (entry.params === undefined || entry.params === null) ? '' : JSON.stringify(entry.params),
                entry.body === undefined ? '' : JSON.stringify(entry.body),
                entry.result === undefined ? '' : JSON.stringify(entry.result)
            ].map(csvCell).join(','));
        });

        // CRLF per RFC 4180, and a UTF-8 BOM so Excel does not mis-decode
        // non-ASCII content in a body it opens by double-click.
        return '﻿' + lines.join('\r\n') + '\r\n';
    }

    function exportCsv() {
        if (!loadedEntries.length) {
            QuickSiteAdmin.showToast(t('history.exportEmpty', 'Nothing to export'), 'warning');
            return;
        }
        const blob = new Blob([buildCsv(loadedEntries)], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = el('a', {
            href: url,
            download: 'command-history-' + new Date().toISOString().split('T')[0] + '.csv'
        });
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        // Freed on the next tick: revoking synchronously can race the download in
        // some browsers, which then saves an empty file.
        setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    function syncExportState() {
        const btn = document.getElementById('history-export');
        if (btn) btn.disabled = loadedEntries.length === 0;
    }

    // =======================================================================
    // Clear — destructive, so preview first
    // =======================================================================
    function openClear() {
        // Default to today: the command deletes strictly BEFORE this date, so
        // today's own entries are kept and the common case ("clear the backlog,
        // keep what I am working on") is one click.
        clearBefore.value = new Date().toISOString().split('T')[0];
        clearConfirmBtn.disabled = true;
        clearModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        previewClear();
    }

    function closeClear() {
        if (!clearModal) return;
        clearModal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function setPreviewText(text, variant) {
        clearNode(clearPreview);
        clearPreview.className = 'admin-alert admin-alert--' + (variant || 'info');
        clearPreview.appendChild(document.createTextNode(text));
    }

    /**
     * Ask the command what it WOULD delete. `clearCommandHistory` answers a
     * preview whenever `confirm` is absent, so the numbers in the dialog come
     * from the same code path that will do the deleting — not from a count this
     * page worked out for itself and could get wrong.
     */
    async function previewClear() {
        const before = clearBefore.value;
        clearConfirmBtn.disabled = true;
        if (!before) {
            setPreviewText(t('history.clear.pickDate', 'Choose a date to see what would be deleted.'), 'info');
            return;
        }
        setPreviewText(t('common.loading', 'Loading...'), 'info');
        try {
            const result = await QuickSiteAdmin.apiRequest(
                'clearCommandHistory', 'POST', { before: before }
            );
            const data = result.data?.data;
            const d = data?.would_delete;
            if (!result.ok || !d) {
                setPreviewText(result.data?.message || 'Could not read what would be deleted.', 'warning');
                return;
            }
            if (!d.files) {
                // ⚠ "Nothing to delete" on its own is the message that sent
                // somebody looking for a bug. Deletion is DAY-GRANULAR — a day
                // is a whole file, named for its date — so a project whose only
                // file is today's has nothing any valid date can reach, and no
                // amount of re-picking will change that. Say which days exist,
                // so the answer is visibly a fact about the data rather than a
                // refusal.
                renderNothingToDelete(data?.stored_days || []);
                return;
            }
            setPreviewText(
                d.entries + ' entries in ' + d.files + ' day(s), ' + d.size_kb + ' KB '
                + 'will be permanently deleted.', 'warning'
            );
            clearConfirmBtn.disabled = false;
        } catch (e) {
            setPreviewText(String(e.message || e), 'error');
        }
    }

    /** "Nothing to delete", with the days that DO exist so it explains itself. */
    function renderNothingToDelete(days) {
        clearNode(clearPreview);
        clearPreview.className = 'admin-alert admin-alert--info';
        clearPreview.appendChild(el('div', {
            text: t('history.clear.nothing', 'Nothing to delete before that date.')
        }));
        if (!days.length) {
            clearPreview.appendChild(el('div', {
                text: t('history.clear.noDays', 'This project has no stored history at all.')
            }));
            return;
        }
        clearPreview.appendChild(el('div', {
            text: t('history.clear.storedDays', 'Days currently stored:')
        }));
        const list = el('ul');
        days.forEach(d => {
            list.appendChild(el('li', {
                text: d.date + ' — ' + d.entries + ' entries'
            }));
        });
        clearPreview.appendChild(list);
        clearPreview.appendChild(el('div', {
            class: 'admin-form-hint',
            text: t('history.clear.dayGranularHint',
                'History is stored one file per day and is deleted a whole day at a time, '
                + 'so a day only disappears once the date you pick is after it. '
                + 'Today cannot be cleared.')
        }));
    }

    async function doClear() {
        const before = clearBefore.value;
        if (!before) return;
        clearConfirmBtn.disabled = true;
        try {
            const result = await QuickSiteAdmin.apiRequest(
                'clearCommandHistory', 'POST', { before: before, confirm: true }
            );
            if (result.ok) {
                QuickSiteAdmin.showToast(
                    t('history.clear.done', 'Command history cleared'), 'success'
                );
                closeClear();
                currentPage = 1;
                loadHistory();
            } else {
                setPreviewText(result.data?.message || 'Clear failed.', 'error');
                clearConfirmBtn.disabled = false;
            }
        } catch (e) {
            setPreviewText(String(e.message || e), 'error');
            clearConfirmBtn.disabled = false;
        }
    }

    // =======================================================================
    // Init
    // =======================================================================
    function init() {
        filterDate = document.getElementById('filter-date');
        filterCommand = document.getElementById('filter-command');
        filterStatus = document.getElementById('filter-status');
        historyContent = document.getElementById('history-content');
        pagination = document.getElementById('history-pagination');
        pageInfo = document.getElementById('page-info');
        prevBtn = document.getElementById('prev-page');
        nextBtn = document.getElementById('next-page');
        detailModal = document.getElementById('detail-modal');
        detailContent = document.getElementById('detail-content');
        clearModal = document.getElementById('clear-modal');
        clearBefore = document.getElementById('clear-before');
        clearPreview = document.getElementById('clear-preview');
        clearConfirmBtn = document.getElementById('clear-confirm');

        if (!historyContent) return;   // not this page

        if (filterDate) filterDate.valueAsDate = new Date();

        document.getElementById('history-search')?.addEventListener('click', () => {
            currentPage = 1; loadHistory();
        });
        document.getElementById('history-reset')?.addEventListener('click', () => {
            if (filterDate) filterDate.valueAsDate = new Date();
            if (filterCommand) filterCommand.value = '';
            if (filterStatus) filterStatus.value = '';
            currentPage = 1; loadHistory();
        });
        document.getElementById('clear-date-filter')?.addEventListener('click', () => {
            if (filterDate) filterDate.value = '';
            currentPage = 1; loadHistory();
        });
        document.getElementById('history-export')?.addEventListener('click', exportCsv);
        document.getElementById('history-clear')?.addEventListener('click', openClear);

        prevBtn?.addEventListener('click', () => changePage(-1));
        nextBtn?.addEventListener('click', () => changePage(1));

        clearBefore?.addEventListener('change', previewClear);
        clearConfirmBtn?.addEventListener('click', doClear);

        filterCommand?.addEventListener('keypress', e => {
            if (e.key === 'Enter') { currentPage = 1; loadHistory(); }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { closeDetail(); closeClear(); }
        });

        historyContent.addEventListener('click', e => {
            const btn = e.target.closest('[data-action="show-detail"]');
            if (btn) showDetail(entryCache.get(btn.dataset.entryId));
        });

        document.querySelectorAll('[data-action="close-detail"]')
            .forEach(n => n.addEventListener('click', closeDetail));
        document.querySelectorAll('[data-action="close-clear"]')
            .forEach(n => n.addEventListener('click', closeClear));

        loadHistory();
    }

    document.addEventListener('DOMContentLoaded', init);

    // Exposed for the probes, which assert the CSV rules directly rather than
    // through a download the harness cannot open.
    window.QSHistoryInternals = { buildCsv, csvCell, publisherName, publisherId };

})();
