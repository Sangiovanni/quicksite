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
 * DEPLOY has TWO gates on top of that, and they are independent: the
 * installation must allow deploying at all (deploy.php, an operator decision
 * the panel cannot change) AND the caller's role must hold `deployBuild` (the
 * `deploy` category, admin and owner). The page template asks both and emits no
 * section unless they pass; this file asks both again before filling it.
 *
 * The deploy panel's real work is not the button — it is saying WHERE the files
 * land before the button is pressed, and WHAT MAKES THEM SERVE afterwards.
 * Copying does not point a document root; only the deployer can. But on a
 * redeploy the document root is already pointed and nothing needs doing, so the
 * guidance leads with "check first" and treats configuration as the fallback.
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
    var ICON_DEPLOY = 'M12 19V5m0 0l-7 7m7-7l7 7M4 21h16';

    // Last successful getBuild payload — the delete modal's download-first button
    // and the space panel both read it rather than re-fetching.
    var currentBuild = null;

    /**
     * What the next build will be shaped like.
     *
     * `build` has always accepted these three; nothing in the panel could set
     * them, so every build made from here came out with the defaults and a
     * deployer who needed `htdocs` or a URL space had to go through
     * /admin/command. Held in module state rather than read off the inputs at
     * submit time, so a refused build can re-render the form with what was typed
     * instead of throwing it away.
     */
    var buildOptions = { public: 'public', secure: 'secure', space: '' };

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
            loadDeploy(null);
            return;
        }

        currentBuild = data;
        setBody(_renderBuild(data));
        loadDeploy(data);
    }

    function _renderEmptyState(errorText) {
        var nodes = [
            el('p', { class: 'builds-empty__title', text: T.none || 'This project has no build yet.' }),
            el('p', { class: 'admin-hint', text: T.noneHint || '' }),
        ];
        if (errorText) {
            nodes.push(_renderAlert('error', T.error || 'Error', errorText));
        }
        if (canRun('build')) {
            // Opened when a build was just refused: the reason is almost always
            // one of these three fields, and it is already filled in with what
            // was typed.
            nodes.push(_renderBuildOptions(!!errorText));
            nodes.push(el('div', { class: 'builds-actions' }, [
                _renderButton(T.buildBtn || 'Build now', ICON_BUILD, 'primary', onBuild),
            ]));
        } else {
            nodes.push(_renderAlert('info', null, T.noControls || ''));
        }
        return nodes;
    }

    /**
     * The three knobs `build` has always taken, behind a closed disclosure.
     *
     * Closed by default because the defaults are right for most people and the
     * common path should stay one click. Present because they are not right for
     * everyone: a host that forces `htdocs` or `public_html` as the document
     * root decides the public folder's name, and a site that has to answer under
     * /shop/ needs the space. Getting either wrong is only discovered after a
     * deploy that reported success, which is why the layout is previewed here as
     * it is typed rather than described in a hint.
     */
    function _renderBuildOptions(open) {
        var preview = el('div', { class: 'builds-deploy__preview' });

        /**
         * One field. `locked` starts it READ-ONLY behind an explicit unlock.
         *
         * Used for the public folder name, which is the one of the three that is
         * usually not the author's decision at all — the destination host picks
         * it. Read-only rather than hidden, because it is also the field that
         * decides which deploy warning fires, and a deployer moving to a host
         * that forces `htdocs` must be able to say so. The unlock is deliberate,
         * the same shape as the disclosure this sits inside.
         */
        function field(key, labelText, hintText, placeholder, locked) {
            var input = el('input', {
                type: 'text',
                class: 'admin-input',
                id: 'builds-opt-' + key,
                placeholder: placeholder || '',
                spellcheck: 'false',
            });
            input.value = buildOptions[key];
            input.addEventListener('input', function () {
                buildOptions[key] = input.value.trim();
                refresh();
            });

            var children = [
                el('label', { class: 'admin-label', for: 'builds-opt-' + key, text: labelText }),
                input,
            ];

            if (locked) {
                input.setAttribute('readonly', 'readonly');
                input.className = 'admin-input builds-options__locked';
                var unlock = _renderButton(T.optUnlock || '', null, 'ghost', function () {
                    input.removeAttribute('readonly');
                    input.className = 'admin-input';
                    unlock.remove();
                });
                children.push(el('div', { class: 'builds-options__unlock' }, [unlock]));
            }

            children.push(el('p', { class: 'admin-hint', text: hintText }));
            return el('div', { class: 'admin-form-group' }, children);
        }

        function refresh() {
            var pub = buildOptions.public || 'public';
            var sec = buildOptions.secure || 'secure';
            var space = buildOptions.space || '';
            clearNode(preview);
            preview.appendChild(el('span', { class: 'builds-field__label', text: T.optPreview || '' }));
            preview.appendChild(_renderPathLine(pub + '/', T.deployDocRoot || ''));
            // The one the README had to be corrected for: with a space set, the
            // document root is still <public>/ and the site lives one level in,
            // so "/" is NOT the site.
            if (space !== '') {
                preview.appendChild(_renderPathLine(pub + '/' + space + '/', (T.optSpaceServes || '').replace('{space}', space)));
            }
            preview.appendChild(_renderPathLine(sec + '/', T.deployOutside || '', true));
        }
        refresh();

        var body = el('div', { class: 'builds-options__body' }, [
            field('public', T.optPublicLabel || '', T.optPublicHint || '', 'public', true),
            field('secure', T.optSecureLabel || '', T.optSecureHint || '', 'secure'),
            field('space', T.optSpaceLabel || '', T.optSpaceHint || '', ''),
            preview,
        ]);

        var details = el('details', { class: 'builds-options' });
        if (open) details.setAttribute('open', 'open');
        details.appendChild(el('summary', { class: 'builds-options__summary', text: T.optTitle || '' }));
        details.appendChild(body);
        return details;
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
        // Empty means "use the default", which is what the server does with an
        // absent parameter — so an empty field is sent as absent rather than as
        // an empty string the validator would then have to have an opinion about.
        var body = {};
        if (buildOptions.public) body.public = buildOptions.public;
        if (buildOptions.secure) body.secure = buildOptions.secure;
        if (buildOptions.space) body.space = buildOptions.space;

        var res;
        try {
            res = await api('build', 'POST', body);
        } catch (e) {
            setBody(_renderEmptyState(String(e && e.message ? e.message : e)));
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
        // Anything else — a rejected folder name, most likely — comes back into
        // the FORM with what was typed still in it and the server's own reason
        // above it. Replacing the panel with a bare error would make the user
        // re-enter three fields to fix one character.
        setBody(_renderEmptyState(serverMessage(res)));
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
    // Deploy — copy the build onto a path on this server
    // ============================================================

    /**
     * TWO GATES, and the section only exists when both hold.
     *
     *   1. The INSTALLATION allows deploying (CFG.deployEnabled, from
     *      deploy.php via the page). An operator decision; nothing here can
     *      change it.
     *   2. THIS CALLER may deploy (`deployBuild`, the `deploy` category, admin
     *      and owner). A developer who can build and download still cannot push.
     *
     * The page template already asked both and emitted no shell unless they
     * passed, so this is the second half of a deliberate doubling: whatever
     * reaches the DOM, nothing fills it with a deploy button unless both gates
     * agree here too.
     */
    function deployAllowedHere() {
        return CFG.deployEnabled === true && canRun('deployBuild');
    }

    /** `<target>/<folder>`, in the reader's own separator style. */
    function joinPath(target, folder) {
        var sep = String(target || '').indexOf('\\') !== -1 ? '\\' : '/';
        return String(target || '').replace(/[\\/]+$/, '') + sep + folder;
    }

    function setDeploy(nodes) {
        var panel = document.getElementById('builds-deploy');
        if (!panel) return;
        clearNode(panel);
        nodes.forEach(function (n) { if (n) panel.appendChild(n); });
    }

    /**
     * Render the deploy panel for the build that is on disk.
     *
     * Nothing to deploy, or an incomplete build, is stated rather than left as a
     * form that would only produce a refusal.
     */
    function loadDeploy(build) {
        var panel = document.getElementById('builds-deploy');
        if (!panel || !deployAllowedHere()) return;

        if (!build) {
            setDeploy([el('p', { class: 'admin-hint', text: T.none || '' })]);
            return;
        }
        if (build.complete === false) {
            setDeploy([_renderAlert('warning', null, T.incompleteBody || '')]);
            return;
        }
        setDeploy(_renderDeployForm(build));
    }

    /**
     * Where to deploy — WITHOUT the install's own path ever reaching the browser.
     *
     * `publicPaths.php` exists because this project decided install-root paths do
     * not belong in responses; emitting one into a page contradicted that for no
     * gain. The capability is kept and the path is not: choosing "this
     * installation's own root" sends NO targetPath, and `deployBuild` already
     * defaults to SERVER_ROOT when none is given. The server knows where it
     * lives; the browser does not have to.
     *
     * A consequence worth stating: the two warnings below can only fire in
     * install-root MODE, because that is the only case where the page knows the
     * target is the install. A deployer who types the install's path by hand
     * gets no client-side warning — the server's own co-tenancy refusals cover
     * that case, and they are the ones that matter.
     */
    function _renderDeployForm(build) {
        var mode = 'path';   // 'path' | 'installRoot'

        var input = el('input', {
            type: 'text',
            class: 'admin-input',
            id: 'builds-deploy-target',
            placeholder: T.deployTargetPlaceholder || '',
            spellcheck: 'false',
        });
        input.value = '';

        var overwrite = el('input', { type: 'checkbox', id: 'builds-deploy-overwrite' });

        var pathRow  = el('div', { class: 'admin-form-group' }, [
            el('label', { class: 'admin-label', for: 'builds-deploy-target', text: T.deployTargetLabel || '' }),
            input,
            el('p', { class: 'admin-hint', text: T.deployTargetHint || '' }),
        ]);
        var modeRow  = el('div', { class: 'builds-deploy__mode' });
        var preview  = el('div', { class: 'builds-deploy__preview' });
        var warnings = el('div', { class: 'builds-deploy__warnings' });

        // WHERE IT LANDS, before anything is pressed. A deploy that just fires
        // looks broken in one case and alarming in the other, and both are
        // decided by the target plus the build's own folder names.
        function refresh() {
            clearNode(preview);
            clearNode(warnings);
            clearNode(modeRow);

            var installRoot = mode === 'installRoot';
            pathRow.style.display = installRoot ? 'none' : '';

            if (installRoot) {
                modeRow.appendChild(el('span', { class: 'builds-deploy__mode-label', text: T.deployToInstall || '' }));
                modeRow.appendChild(_renderButton(T.deployElsewhere || '', null, 'ghost', function () {
                    mode = 'path';
                    refresh();
                }));
            } else {
                modeRow.appendChild(_renderButton(T.deployUseInstall || '', null, 'ghost', function () {
                    mode = 'installRoot';
                    refresh();
                }));
            }

            // With no target chosen there is nothing truthful to preview.
            var typed = input.value.trim();
            if (!installRoot && typed === '') {
                return;
            }

            preview.appendChild(el('span', { class: 'builds-field__label', text: T.deployWillCreate || '' }));
            var pubLine = installRoot ? build.public + '/'  : joinPath(typed, build.public);
            var secLine = installRoot ? build.secure + '/' : joinPath(typed, build.secure);
            preview.appendChild(_renderPathLine(pubLine, T.deployDocRoot || ''));
            preview.appendChild(_renderPathLine(secLine, T.deployOutside || '', true));
            if (installRoot) {
                preview.appendChild(el('p', { class: 'admin-hint', text: T.deployInsideInstall || '' }));
            }

            if (!installRoot) {
                return;
            }
            // Only reachable in install-root mode — see the note above.
            var merges = build.public === CFG.installPublicName
                      && build.secure === CFG.installSecureName;
            warnings.appendChild(merges
                ? _renderAlert('error', T.deployMergeTitle || '',
                    (T.deployMergeBody || '')
                        .replace('{public}', CFG.installPublicName)
                        .replace('{secure}', CFG.installSecureName))
                : _renderAlert('warning', T.deployBesideTitle || '',
                    (T.deployBesideBody || '')
                        .replace('{public}', CFG.installPublicName)
                        .replace('{secure}', CFG.installSecureName)
                        .replace('{docroot}', build.public + '/')));
        }

        input.addEventListener('input', refresh);
        refresh();

        var btn = _renderButton(T.deployBtn || 'Deploy', ICON_DEPLOY, 'primary', function () {
            // In install-root mode the target is DELIBERATELY absent: the server
            // fills it in from SERVER_ROOT, so the path never round-trips.
            onDeploy(build, mode === 'installRoot' ? null : input.value.trim(),
                     overwrite.checked, {}, this);
        });

        return [
            el('p', { class: 'admin-hint', text: T.deployIntro || '' }),
            pathRow,
            modeRow,
            preview,
            warnings,
            el('label', { class: 'builds-deploy__check', for: 'builds-deploy-overwrite' }, [
                overwrite,
                el('span', {}, [
                    el('span', { text: T.deployOverwrite || '' }),
                    el('span', { class: 'admin-hint', text: T.deployOverwriteHint || '' }),
                ]),
            ]),
            el('div', { class: 'builds-actions' }, [btn]),
            el('div', { id: 'builds-deploy-result' }),
        ];
    }

    function _renderPathLine(path, note, danger) {
        return el('div', { class: 'builds-deploy__path' }, [
            el('code', { class: 'builds-deploy__path-value', text: path }),
            el('span', {
                class: 'builds-deploy__path-note' + (danger ? ' builds-deploy__path-note--danger' : ''),
                text: note,
            }),
        ]);
    }

    /**
     * Every co-tenancy refusal arrives with the ONE control that answers it.
     *
     * Each row is its own named refusal with its own opt-in, and none of them is
     * `overwrite`. That separation is the point of the slice: a single blunt
     * replace-everything checkbox is how a deployer destroys a site they did not
     * know was there, so the panel never lets one click stand for "yes" to a
     * question the deployer was never asked.
     */
    var DEPLOY_REFUSALS = {
        'conflict.route_collision':          { flag: 'acceptRouteCollisions', btn: 'deployAnywayBtn' },
        'conflict.files_exist':              { flag: 'overwrite',             btn: 'deployReplaceBtn',
                                               title: 'deployFilesExist',     body: 'deployFilesExistBody' },
        'deploy.update_confirmation_required': { flag: 'confirmUpdate',       btn: 'deployConfirmUpdateBtn',
                                               title: 'deployUpdateTitle',    body: 'deployUpdateBody' },
        'deploy.secure_folder_in_use':       { flag: 'replaceDeployment',     btn: 'deployReplaceOtherBtn',
                                               title: 'deployInUseTitle',     body: 'deployInUseBody', danger: true },
        'deploy.secure_folder_unmarked':     { flag: 'adoptSecureFolder',     btn: 'deployAdoptBtn',
                                               title: 'deployUnmarkedTitle',  body: 'deployUnmarkedBody', danger: true },
    };

    async function onDeploy(build, target, overwrite, optIns, btn) {
        var out = document.getElementById('builds-deploy-result');
        if (btn) btn.disabled = true;
        clearNode(out);
        out.appendChild(el('div', { class: 'admin-loading' }, [
            el('span', { class: 'admin-spinner' }),
            el('span', { text: T.deploying || '' }),
        ]));

        var body = {};
        // A null target is DELIBERATE: install-root mode sends none and lets the
        // server fill it in, so the install's path never reaches the browser.
        if (target) body.targetPath = target;
        if (overwrite) body.overwrite = true;
        Object.keys(optIns || {}).forEach(function (k) { if (optIns[k]) body[k] = true; });

        var res;
        try {
            res = await api('deployBuild', 'POST', body);
        } catch (e) {
            clearNode(out);
            out.appendChild(_renderAlert('error', T.deployFailed || '', String(e && e.message ? e.message : e)));
            if (btn) btn.disabled = false;
            return;
        }
        if (btn) btn.disabled = false;
        clearNode(out);

        var data = (res && res.data && res.data.data) || {};

        if (res && res.ok) {
            toast(T.deployedMsg || 'Deployed', 'success');
            var files = (data.public_deployment ? data.public_deployment.files_copied : 0)
                      + (data.secure_deployment ? data.secure_deployment.files_copied : 0);
            out.appendChild(_renderAlert('success', T.deployedMsg || '',
                (T.deployCopied || '').replace('{files}', String(files))
                    .replace('{target}', target || (T.deployInstallRootLabel || ''))));
            if (Array.isArray(data.route_collisions) && data.route_collisions.length) {
                out.appendChild(_renderCollisions(data.route_collisions, null));
            }
            // Files this build does not own that were left alone. Reported,
            // because on a shared document root "nothing happened here" is
            // information, not silence.
            if (data.shared_paths_skipped && data.shared_paths_skipped.count) {
                out.appendChild(_renderAlert('info', T.deploySharedTitle || '',
                    (T.deploySharedBody || '')
                        .replace('{n}', String(data.shared_paths_skipped.count))
                        .replace('{paths}', (data.shared_paths_skipped.paths || []).join(', '))));
            }
            if (data.ownership_marker && data.ownership_marker.written === false) {
                out.appendChild(_renderAlert('warning', null, data.ownership_marker.warning || ''));
            }
            out.appendChild(_renderServingGuidance(build, target, data));
            return;
        }

        var code = (res && res.data && res.data.code) || '';
        var spec = DEPLOY_REFUSALS[code];
        if (spec) {
            if (code === 'conflict.route_collision') {
                out.appendChild(_renderCollisions(data.collisions || [], function () {
                    onDeploy(build, target, overwrite, _withFlag(optIns, spec.flag), btn);
                }));
                return;
            }
            out.appendChild(_renderAlert(spec.danger ? 'error' : 'warning',
                T[spec.title] || '',
                (T[spec.body] || '')
                    .replace('{n}', String(data.total_conflicts || 0))
                    .replace('{folder}', data.secure_folder || '')
                    .replace('{date}', data.deployed_at ? new Date(data.deployed_at).toLocaleString() : '')));
            out.appendChild(el('div', { class: 'builds-actions' }, [
                _renderButton(T[spec.btn] || '', ICON_DEPLOY, spec.danger ? 'danger' : 'primary', function () {
                    onDeploy(build, target,
                             spec.flag === 'overwrite' ? true : overwrite,
                             spec.flag === 'overwrite' ? optIns : _withFlag(optIns, spec.flag),
                             this);
                }),
            ]));
            return;
        }
        out.appendChild(_renderAlert('error', T.deployFailed || '', serverMessage(res)));
    }

    /** optIns plus one more, without mutating the caller's object. */
    function _withFlag(optIns, flag) {
        var next = {};
        Object.keys(optIns || {}).forEach(function (k) { next[k] = optIns[k]; });
        next[flag] = true;
        return next;
    }

    function _renderCollisions(collisions, onAnyway) {
        var list = el('ul', { class: 'builds-deploy__collisions' });
        collisions.forEach(function (c) {
            list.appendChild(el('li', {}, [
                el('code', { text: '/' + (c.segment || c.route || '') }),
                el('span', { class: 'builds-deploy__path-note', text: c.shadowed_by || '' }),
            ]));
        });

        var kind = onAnyway ? 'warning' : 'info';
        var box = el('div', { class: 'admin-alert admin-alert--' + kind }, [
            el('strong', { class: 'builds-alert__title', text: T.deployCollisionTitle || '' }),
            el('p', { class: 'builds-alert__body', text: T.deployCollisionBody || '' }),
            list,
        ]);
        if (onAnyway) {
            box.appendChild(el('div', { class: 'builds-actions' }, [
                _renderButton(T.deployAnywayBtn || '', ICON_DEPLOY, 'danger', onAnyway),
            ]));
        }
        return box;
    }

    /**
     * WHAT TO DO NEXT — and the order matters more than the content.
     *
     * Copying files does not point a document root; only the deployer can. But
     * on a REDEPLOY the document root is already pointed, the web server config
     * already exists, and a deploy is a pure file copy — so the site serves the
     * moment the copy finishes and there is nothing to do at all. That case is
     * common enough that leading with the configuration would send people to
     * edit a vhost they must not touch, which has already happened once here.
     *
     * So: check first, and treat the configuration as the fallback. What is
     * REQUIRED is stated as required (a document root at <public>/); what is
     * CONDITIONAL is stated as conditional (the nginx include).
     */
    function _renderServingGuidance(build, target, data) {
        // Paths come from the SERVER's answer where it gave one: it scrubs its
        // own install root out of responses, so an install-root deploy reads as
        // "public/" rather than an absolute path — which is the point of not
        // shipping that path to the browser in the first place.
        var paths = (data && data.deployed_paths) || {};
        var insideInstall = !target;
        var docRoot = paths.public || (target ? joinPath(target, build.public) : build.public + '/');
        var securePath = paths.secure || (target ? joinPath(target, build.secure) : build.secure + '/');
        var targetLabel = target || (T.deployInstallRootLabel || '');
        var nodes = [];

        nodes.push(el('h3', { class: 'builds-deploy__heading', text: T.serveTitle || '' }));
        nodes.push(el('p', { text: T.serveCheckFirst || '' }));
        nodes.push(el('p', { class: 'admin-hint', text: T.serveRedeploy || '' }));
        nodes.push(el('p', { class: 'admin-hint', text: T.serveRealCheck || '' }));

        nodes.push(el('h4', { class: 'builds-deploy__subheading', text: T.serveIfNotTitle || '' }));
        nodes.push(el('p', {}, [
            el('span', { text: (T.serveDocRoot || '') + ' ' }),
            el('code', { text: docRoot }),
        ]));
        if (insideInstall) {
            nodes.push(el('p', { class: 'admin-hint', text: T.deployInsideInstall || '' }));
        }
        if (build.space) {
            nodes.push(el('p', { class: 'admin-hint', text: (T.serveSpaceNote || '').replace('{space}', build.space) }));
        }

        // THE ONE WAY A CORRECT DEPLOY BECOMES A BREACH. Stated where it is read,
        // not left to the README nobody opens.
        nodes.push(_renderAlert('error', T.serveDangerTitle || '',
            (T.serveDangerBody || '')
                .replace('{target}', targetLabel)
                .replace(/\{secure\}/g, build.secure)));

        nodes.push(el('div', { class: 'builds-deploy__server' }, [
            el('strong', { text: T.serveApacheTitle || '' }),
            el('p', { text: T.serveApacheBody || '' }),
        ]));
        nodes.push(el('div', { class: 'builds-deploy__server' }, [
            el('strong', { text: T.serveNginxTitle || '' }),
            el('p', { text: T.serveNginxBody || '' }),
            el('code', { class: 'builds-deploy__conf', text: joinPath(securePath, 'nginx_routes.conf') }),
            el('p', { class: 'admin-hint', text: T.serveNginxOptional || '' }),
        ]));

        nodes.push(el('p', { class: 'admin-hint', text: T.serveMore || '' }));

        return el('div', { class: 'builds-deploy__guidance' }, nodes);
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
