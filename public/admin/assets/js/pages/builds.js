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

        function field(key, labelText, hintText, placeholder) {
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
            return el('div', { class: 'admin-form-group' }, [
                el('label', { class: 'admin-label', for: 'builds-opt-' + key, text: labelText }),
                input,
                el('p', { class: 'admin-hint', text: hintText }),
            ]);
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
            field('public', T.optPublicLabel || '', T.optPublicHint || '', 'public'),
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

    /** Compare two filesystem paths the way the server's own containment check does. */
    function samePath(a, b) {
        var norm = function (p) {
            p = String(p || '').replace(/[\\/]+/g, '/').replace(/\/+$/, '');
            return CFG.caseInsensitivePaths ? p.toLowerCase() : p;
        };
        return norm(a) !== '' && norm(a) === norm(b);
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

    function _renderDeployForm(build) {
        var target = CFG.deployDefaultTarget || '';

        var input = el('input', {
            type: 'text',
            class: 'admin-input',
            id: 'builds-deploy-target',
            value: target,
            spellcheck: 'false',
        });
        input.value = target;

        var overwrite = el('input', { type: 'checkbox', id: 'builds-deploy-overwrite' });

        // WHERE IT LANDS, before anything is pressed. A deploy that just fires
        // looks broken in one case and alarming in the other, and both are
        // decided by the target plus the build's own folder names.
        var preview = el('div', { class: 'builds-deploy__preview' });
        var warnings = el('div', { class: 'builds-deploy__warnings' });

        function refreshPreview() {
            var t = input.value;
            clearNode(preview);
            clearNode(warnings);

            preview.appendChild(el('span', { class: 'builds-field__label', text: T.deployWillCreate || '' }));
            preview.appendChild(_renderPathLine(joinPath(t, build.public), T.deployDocRoot || ''));
            preview.appendChild(_renderPathLine(joinPath(t, build.secure), T.deployOutside || '', true));

            if (samePath(t, CFG.deployDefaultTarget)) {
                var merges = build.public === CFG.installPublicName
                          && build.secure === CFG.installSecureName;
                if (merges) {
                    warnings.appendChild(_renderAlert('error',
                        T.deployMergeTitle || '',
                        (T.deployMergeBody || '')
                            .replace('{public}', CFG.installPublicName)
                            .replace('{secure}', CFG.installSecureName)));
                } else {
                    warnings.appendChild(_renderAlert('warning',
                        T.deployBesideTitle || '',
                        (T.deployBesideBody || '')
                            .replace('{public}', CFG.installPublicName)
                            .replace('{secure}', CFG.installSecureName)
                            .replace('{docroot}', joinPath(t, build.public))));
                }
            }
        }

        input.addEventListener('input', refreshPreview);
        refreshPreview();

        var btn = _renderButton(T.deployBtn || 'Deploy', ICON_DEPLOY, 'primary', function () {
            onDeploy(build, input.value, overwrite.checked, false, this);
        });

        return [
            el('p', { class: 'admin-hint', text: T.deployIntro || '' }),
            el('div', { class: 'admin-form-group' }, [
                el('label', { class: 'admin-label', for: 'builds-deploy-target', text: T.deployTargetLabel || '' }),
                input,
                el('p', { class: 'admin-hint', text: T.deployTargetHint || '' }),
            ]),
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

    async function onDeploy(build, target, overwrite, acceptCollisions, btn) {
        var out = document.getElementById('builds-deploy-result');
        if (btn) btn.disabled = true;
        clearNode(out);
        out.appendChild(el('div', { class: 'admin-loading' }, [
            el('span', { class: 'admin-spinner' }),
            el('span', { text: T.deploying || '' }),
        ]));

        var body = { targetPath: target };
        if (overwrite) body.overwrite = true;
        if (acceptCollisions) body.acceptRouteCollisions = true;

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
            // The target the USER typed, never the one echoed back. Responses are
            // scrubbed of install-root paths (publicPaths.php), so a deploy to
            // the install's own root comes back as `data.target: "."` — correct
            // of the API, useless to read, and the page already knows the real
            // answer because it is what it sent.
            out.appendChild(_renderAlert('success', T.deployedMsg || '',
                (T.deployCopied || '').replace('{files}', String(files)).replace('{target}', target)));
            if (Array.isArray(data.route_collisions) && data.route_collisions.length) {
                out.appendChild(_renderCollisions(data.route_collisions, null));
            }
            out.appendChild(_renderServingGuidance(build, target));
            return;
        }

        // The two refusals a deployer can answer for themselves, each with the
        // control that answers it — rather than a message telling them to go and
        // find a parameter.
        var code = (res && res.data && res.data.code) || '';
        if (code === 'conflict.route_collision') {
            out.appendChild(_renderCollisions(data.collisions || [], function () {
                onDeploy(build, target, overwrite, true, btn);
            }));
            return;
        }
        if (code === 'conflict.files_exist') {
            out.appendChild(_renderAlert('warning', T.deployFilesExist || '',
                (T.deployFilesExistBody || '').replace('{n}', String(data.total_conflicts || 0))));
            out.appendChild(el('div', { class: 'builds-actions' }, [
                _renderButton(T.deployReplaceBtn || '', ICON_DEPLOY, 'danger', function () {
                    onDeploy(build, target, true, acceptCollisions, this);
                }),
            ]));
            return;
        }
        out.appendChild(_renderAlert('error', T.deployFailed || '', serverMessage(res)));
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
    function _renderServingGuidance(build, target) {
        var docRoot = joinPath(target, build.public);
        var securePath = joinPath(target, build.secure);
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
        if (build.space) {
            nodes.push(el('p', { class: 'admin-hint', text: (T.serveSpaceNote || '').replace('{space}', build.space) }));
        }

        // THE ONE WAY A CORRECT DEPLOY BECOMES A BREACH. Stated where it is read,
        // not left to the README nobody opens.
        nodes.push(_renderAlert('error', T.serveDangerTitle || '',
            (T.serveDangerBody || '')
                .replace('{target}', target)
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
