/**
 * Builds page (beta.11 S3.8) — the project's ONE build.
 *
 * Retention is N = 1, so everything here is singular: getBuild answers 404 when
 * there is none (the empty state, not an error), `build` REFUSES while one
 * exists, and deleting is how you make room for the next one. That last fact is
 * why the delete confirmation offers a download first — the build on disk is the
 * only copy.
 *
 * PERMISSION IS ASKED PER CONTROL, not per page. The page itself needs only
 * getBuild (content.read), so a viewer reaches it and sees the build's details
 * and the space picture; build / download / delete live in the `build` category
 * (developer and up) and their buttons are simply not rendered without it.
 *
 * Built with QSDom.el + named _render* helpers (one Element each) per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 * Static structure lives in templates/pages/builds.php.
 */
(function () {
    'use strict';

    var CFG = window.QS_BUILDS_CONFIG || {};
    var T = window.QS_BUILDS_I18N || {};
    var el = window.QSDom.el;
    var svgIcon = window.QSDom.svgIcon;
    var clearNode = window.QSDom.clear;

    var ICON_DOWNLOAD = 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2';
    var ICON_TRASH = 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6';
    var ICON_BUILD = 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z';
    var ICON_REFRESH = 'M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15';

    // Last successful getBuild payload — the delete modal's download-first button
    // and the space panel both read it rather than re-fetching.
    var currentBuild = null;

    function api(cmd, method, body) {
        return window.QuickSiteAdmin.apiRequest(cmd, method, body);
    }

    function toast(message, type) {
        window.QuickSiteAdmin.showToast(message, type || 'info');
    }

    function serverMessage(res, fallback) {
        return (res && res.data && (res.data.message || res.data.error)) || fallback || T.error || 'Error';
    }

    function formatSize(bytes) {
        return window.QuickSiteUtils.formatSize(Number(bytes) || 0);
    }

    /**
     * Can this page actually run `command` right now?
     *
     * Permission AND a bound project, for the reason dashboard.js documents: a
     * project-scoped command with no project never reaches the server, so a
     * control gated on permission alone would render for a zero-membership
     * account and only ever produce an error.
     */
    function canRun(command) {
        var admin = window.QuickSiteAdmin;
        var apiMod = window.QuickSiteAPI;
        if (admin && admin.permissions && !admin.hasPermission(command)) return false;
        if (apiMod && !apiMod.isProjectScoped(command)) return true;
        return !!(apiMod ? apiMod.getCurrentProject() : CFG.project);
    }

    // ============================================================
    // Small shared pieces
    // ============================================================

    function _renderAlert(kind, title, bodyText) {
        var children = [];
        if (title) children.push(el('strong', { class: 'builds-alert__title', text: title }));
        if (bodyText) children.push(el('p', { class: 'builds-alert__body', text: bodyText }));
        return el('div', { class: 'admin-alert admin-alert--' + kind }, children);
    }

    function _renderField(label, value) {
        return el('div', { class: 'builds-field' }, [
            el('span', { class: 'builds-field__label', text: label }),
            el('span', { class: 'builds-field__value', text: value }),
        ]);
    }

    function _renderButton(label, iconD, kind, onClick) {
        var btn = el('button', { type: 'button', class: 'admin-btn admin-btn--' + kind, onclick: onClick });
        if (iconD) btn.appendChild(svgIcon(iconD, 16));
        btn.appendChild(document.createTextNode(' ' + label));
        return btn;
    }

    function _renderModal(title, bodyNodes, footerNodes) {
        var root = document.getElementById('builds-modal-root');
        clearNode(root);
        function close() { clearNode(root); }

        root.appendChild(el('div', { class: 'admin-modal admin-modal--builds' }, [
            el('div', { class: 'admin-modal__backdrop', onclick: close }),
            el('div', { class: 'admin-modal__content' }, [
                el('div', { class: 'admin-modal__header' }, [
                    el('h3', { class: 'admin-modal__title', text: title }),
                    el('button', { class: 'admin-modal__close', type: 'button', 'aria-label': T.close || 'Close', onclick: close }, ['×']),
                ]),
                el('div', { class: 'admin-modal__body' }, bodyNodes),
                el('div', { class: 'admin-modal__footer' }, footerNodes(close)),
            ]),
        ]));
        return { close: close };
    }

    // ============================================================
    // The build panel
    // ============================================================

    function setBody(nodes) {
        var body = document.getElementById('builds-body');
        clearNode(body);
        nodes.forEach(function (n) { if (n) body.appendChild(n); });
    }

    function setLoading(message) {
        setBody([el('div', { class: 'admin-loading' }, [
            el('span', { class: 'admin-spinner' }),
            el('span', { text: message || T.loading || 'Loading...' }),
        ])]);
    }

    async function loadBuild() {
        setLoading();
        var res;
        try {
            res = await api('getBuild');
        } catch (e) {
            setBody([_renderAlert('error', T.error || 'Error', String(e && e.message ? e.message : e))]);
            return;
        }

        // 404 is the EMPTY STATE, not a failure: a project with no build is the
        // normal starting point.
        var data = (res && res.ok) ? (res.data && res.data.data) : null;
        if (!data || !data.exists) {
            currentBuild = null;
            if (res && !res.ok && res.status !== 404) {
                setBody([_renderAlert('error', T.error || 'Error', serverMessage(res))]);
                return;
            }
            setBody(_renderEmptyState());
            return;
        }

        currentBuild = data;
        setBody(_renderBuild(data));
    }

    function _renderEmptyState() {
        var nodes = [
            el('p', { class: 'builds-empty__title', text: T.none || 'This project has no build yet.' }),
            el('p', { class: 'admin-hint', text: T.noneHint || '' }),
        ];
        if (canRun('build')) {
            nodes.push(el('div', { class: 'builds-actions' }, [
                _renderButton(T.buildBtn || 'Build now', ICON_BUILD, 'primary', onBuild),
            ]));
        } else {
            nodes.push(_renderAlert('info', null, T.noControls || ''));
        }
        return nodes;
    }

    function _renderBuild(b) {
        var nodes = [];

        var nameChildren = [el('strong', { class: 'builds-name', text: b.name })];
        if (b.complete === false) {
            nameChildren.push(el('span', { class: 'builds-badge builds-badge--incomplete', text: T.incomplete || 'incomplete' }));
        }
        nodes.push(el('div', { class: 'builds-head' }, nameChildren));

        if (b.complete === false) {
            nodes.push(_renderAlert('warning', null, T.incompleteBody || ''));
        }
        if (b.oauth_secrets_included) {
            nodes.push(_renderAlert('warning', null, T.oauthWarn || ''));
        }

        nodes.push(_renderFacts(b));

        // A build already exists, so `build` will refuse. Say so HERE rather than
        // letting the user find out from a 409 — and say what to do about it.
        if (canRun('build')) {
            nodes.push(_renderAlert('info', T.oneAtATime || '', T.oneAtATimeBody || ''));
        }

        var actions = [];
        // An incomplete build has no archive worth handing over: downloadBuild
        // refuses it, so the button would only ever produce an error.
        if (b.complete !== false && canRun('downloadBuild')) {
            actions.push(_renderButton(T.downloadBtn || 'Download', ICON_DOWNLOAD, 'outline', function () {
                onDownload(this);
            }));
        }
        if (canRun('deleteBuild')) {
            actions.push(_renderButton(T.deleteBtn || 'Delete', ICON_TRASH, 'danger', function () {
                onDeleteConfirm(b);
            }));
        }
        if (actions.length) {
            nodes.push(el('div', { class: 'builds-actions' }, actions));
        } else {
            nodes.push(_renderAlert('info', null, T.noControls || ''));
        }

        return nodes;
    }

    function _renderFacts(b) {
        var fields = [];
        if (b.created) {
            fields.push(_renderField(T.fieldCreated || 'Created', new Date(b.created).toLocaleString()));
        }
        fields.push(_renderField(T.fieldSize || 'Size', formatSize(b.size_bytes)));
        if (typeof b.file_count === 'number') {
            fields.push(_renderField(T.fieldFiles || 'Files', String(b.file_count)));
        }
        if (typeof b.pages_count === 'number') {
            fields.push(_renderField(T.fieldPages || 'Pages', String(b.pages_count)));
        }
        if (Array.isArray(b.languages) && b.languages.length) {
            fields.push(_renderField(T.fieldLanguages || 'Languages', b.languages.join(', ')));
        }
        if (b.public) fields.push(_renderField(T.fieldPublic || 'Public folder', b.public));
        if (b.secure) fields.push(_renderField(T.fieldSecure || 'Secure folder', b.secure));
        fields.push(_renderField(T.fieldSpace || 'URL space', b.space ? b.space : (T.spaceRoot || '(root)')));
        return el('div', { class: 'builds-facts' }, fields);
    }

    // ============================================================
    // Actions
    // ============================================================

    async function onBuild() {
        setLoading(T.building || 'Building…');
        var res;
        try {
            res = await api('build', 'POST', {});
        } catch (e) {
            setBody([_renderAlert('error', T.error || 'Error', String(e && e.message ? e.message : e))]);
            return;
        }

        if (res && res.ok) {
            toast(T.builtMsg || 'Build completed', 'success');
            await loadBuild();
            await loadSpace(true);
            return;
        }

        // THE REFUSAL, made actionable. 409 conflict.already_exists means a build
        // is already there — which is a state the user can fix, not an error to
        // stare at. Re-render the panel: it shows the build, why a second one was
        // refused, and the two buttons that resolve it.
        if (res && res.status === 409) {
            await loadBuild();
            toast(serverMessage(res), 'warning');
            return;
        }
        setBody([_renderAlert('error', T.error || 'Error', serverMessage(res))]);
    }

    /**
     * Fetch the archive. Not an anchor: the management surface requires an
     * Authorization header and a plain link cannot send one, so downloadFile
     * fetches with the header and hands the blob to the browser.
     */
    async function onDownload(btn) {
        if (btn) btn.disabled = true;
        try {
            var res = await window.QuickSiteAdmin.downloadFile('downloadBuild');
            if (!res.ok) {
                toast(serverMessage(res, T.downloadFailed), 'error');
                return false;
            }
            return true;
        } catch (e) {
            toast(T.downloadFailed || 'Download failed', 'error');
            return false;
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    /**
     * The delete confirmation, with a download offered inside it.
     *
     * At N = 1, deleting is not tidying — it is the only way to make room for the
     * next build, and the build being deleted is the only copy anywhere. So the
     * modal that asks says both of those things and puts the download one click
     * away, instead of leaving the user to cancel, download, and come back.
     */
    function onDeleteConfirm(b) {
        var body = [
            el('p', { text: T.deleteBody || '' }),
            el('p', { class: 'admin-hint', text: T.deleteWhy || '' }),
            el('p', { class: 'builds-modal__name', text: b.name }),
        ];

        _renderModal(T.deleteTitle || 'Delete this build?', body, function (close) {
            var footer = [];
            if (b.complete !== false && canRun('downloadBuild')) {
                footer.push(_renderButton(T.deleteDownloadFirst || 'Download it first', ICON_DOWNLOAD, 'outline', async function () {
                    var ok = await onDownload(this);
                    if (ok) toast(T.deleteDownloaded || 'Downloaded', 'success');
                }));
            }
            footer.push(el('button', {
                class: 'admin-btn admin-btn--ghost', type: 'button', onclick: close,
            }, [T.cancel || 'Cancel']));
            footer.push(_renderButton(T.deleteBtn || 'Delete', ICON_TRASH, 'danger', async function () {
                this.disabled = true;
                var res;
                try {
                    res = await api('deleteBuild', 'POST', {});
                } catch (e) {
                    this.disabled = false;
                    toast(T.deleteFailed || 'Delete failed', 'error');
                    return;
                }
                close();
                if (res && res.ok) {
                    toast(T.deletedMsg || 'Build deleted', 'success');
                    await loadBuild();
                    await loadSpace(true);
                } else {
                    toast(serverMessage(res, T.deleteFailed), 'error');
                }
            }));
            return footer;
        });
    }

    // ============================================================
    // Space — read from the EXISTING measurement, never a second one
    // ============================================================

    /**
     * getMySpaceUsage already returns a per-project breakdown with `builds` as
     * its own line, so this panel measures nothing: it reads that report and
     * asks one question of it.
     *
     * THE WARNING. A project's build is roughly the size of its content, so a
     * project can grow past the point where its own build still fits. What is
     * available for a build is the free quota PLUS whatever the current build
     * already occupies — at N = 1 the old build has to go before a new one is
     * made, so its bytes come back. The warning fires once the projected build
     * would take more than `spaceWarnFraction` of that.
     *
     * A caller who does not OWN this project gets no figure at all: the quota
     * that applies belongs to the owner, and this command reports only what the
     * caller owns. Saying "no warning" there would be a claim; saying whose
     * quota it is, is the truth.
     */
    async function loadSpace(refresh) {
        var panel = document.getElementById('builds-space');
        if (!panel) return;
        clearNode(panel);
        panel.appendChild(el('div', { class: 'admin-loading' }, [
            el('span', { class: 'admin-spinner' }),
            el('span', { text: T.loading || 'Loading...' }),
        ]));

        var res;
        try {
            res = await api('getMySpaceUsage', 'POST', refresh ? { refresh: true } : {});
        } catch (e) {
            clearNode(panel);
            panel.appendChild(_renderAlert('error', T.error || 'Error', String(e && e.message ? e.message : e)));
            return;
        }

        var data = (res && res.ok) ? (res.data && res.data.data) : null;
        clearNode(panel);
        if (!data) {
            panel.appendChild(_renderAlert('error', T.error || 'Error', serverMessage(res)));
            return;
        }

        var row = null;
        (data.projects || []).forEach(function (p) {
            if (p.name === CFG.project) row = p;
        });

        if (!row) {
            panel.appendChild(el('p', { class: 'admin-hint', text: T.spaceNotOwner || '' }));
            return;
        }

        var quota = data.quota || {};
        var buildBytes = (row.builds && row.builds.size) || 0;

        panel.appendChild(el('div', { class: 'builds-facts' }, [
            _renderField(T.spaceProject || 'This project uses', formatSize(row.total)),
            _renderField(T.spaceOfWhich || 'of which the build is', formatSize(buildBytes)),
            quota.configured ? _renderField(T.spaceFree || 'Left in your quota', formatSize(quota.free)) : null,
        ]));

        if (!quota.configured) {
            panel.appendChild(el('p', { class: 'admin-hint', text: T.spaceNoQuota || '' }));
        } else {
            var need = Number(row.content) || 0;
            var available = (Number(quota.free) || 0) + buildBytes;
            var fraction = typeof CFG.spaceWarnFraction === 'number' ? CFG.spaceWarnFraction : 0.5;
            if (need > available * fraction) {
                panel.appendChild(_renderAlert(
                    'warning',
                    T.spaceWarnTitle || '',
                    (T.spaceWarnBody || '')
                        .replace('{need}', formatSize(need))
                        .replace('{free}', formatSize(available))
                ));
            }
        }

        panel.appendChild(el('div', { class: 'builds-actions' }, [
            _renderButton(T.refresh || 'Re-measure', ICON_REFRESH, 'ghost', function () {
                this.disabled = true;
                loadSpace(true);
            }),
        ]));
    }

    // ============================================================
    // Init
    // ============================================================

    document.addEventListener('DOMContentLoaded', async function () {
        var chip = document.getElementById('builds-project-chip');
        if (chip && CFG.project) chip.textContent = CFG.project;

        // admin.js loads permissions asynchronously; every control here is gated
        // on them, so render nothing until they have landed.
        await (window.QuickSiteAdmin && window.QuickSiteAdmin.permissionsReady
            ? window.QuickSiteAdmin.permissionsReady : Promise.resolve());

        await loadBuild();
        // Owner-wide usage walks the disk, so it loads after the build panel
        // rather than delaying it.
        loadSpace(false);
    });
})();
