/**
 * Builds page — the project's ONE build.
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
 * A build reaches a server by the deployer's own means: this page offers the
 * download, and the README.txt inside the archive says the rest.
 *
 * Built with QSDom.el + named _render* helpers (one Element each) per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 * Static structure lives in templates/pages/builds.php.
 */
(function () {
    'use strict';

    var CFG = window.QS_BUILDS_CONFIG || {};
    var el = window.QSDom.el;
    var svgIcon = window.QSDom.svgIcon;
    var clearNode = window.QSDom.clear;

    var ICON_DOWNLOAD = 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2';
    var ICON_BUILD = 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z';
    var ICON_REFRESH = 'M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15';
    var ICON_WARNING = 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01';

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

    /**
     * Whether the next build carries the project's real OAuth secrets (`include_oauth_secrets`).
     * Off until the box is ticked, and off again on every page load: the default is the safe one.
     */
    var includeOAuthSecrets = false;

    /** The box's explanation, which names the build's secure folder: rewritten when that name changes. */
    var secretsHint = null;

    function api(cmd, method, body) {
        return window.QuickSiteAdmin.apiRequest(cmd, method, body);
    }

    function toast(message, type) {
        window.QuickSiteAdmin.showToast(message, type || 'info');
    }

    /** The server's own words for a refusal, else the page's word for what failed. */
    function serverMessage(res, word) {
        return (res && res.data && (res.data.message || res.data.error)) || word;
    }

    /** Can this page run `command` now: the panel's one rule, QuickSiteAdmin.canRun() (permission and a project). */
    function canRun(command) {
        return window.QuickSiteAdmin.canRun(command);
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

    /**
     * What the deployed site's sign-in lacks (the build's `oauth` report), with the way to the OAuth
     * providers page where the build keys are entered. Null when nothing is missing — and for a
     * build made before the report existed, which has none.
     */
    function _renderOAuthGaps(report, secureName) {
        if (!report || typeof report !== 'object') return null;
        var missing = Array.isArray(report.missing_build_keys) ? report.missing_build_keys : [];
        var leftOut = Array.isArray(report.secrets_left_out) ? report.secrets_left_out : [];
        var notOffered = Array.isArray(report.not_offered) ? report.not_offered : [];
        var lines = [];
        if (missing.length) lines.push(QSPanelWords.t('builds.oauthMissingBody', { providers: QSPanelWords.list(missing) }));
        if (leftOut.length) {
            lines.push(QSPanelWords.t('builds.oauthLeftOutBody', {
                providers: QSPanelWords.list(leftOut),
                secure: String(secureName || 'secure')
            }));
        }
        if (notOffered.length) lines.push(QSPanelWords.t('builds.oauthNotOffered', { providers: QSPanelWords.list(notOffered) }));
        if (report.address_routes_without_keys === true) lines.push(QSPanelWords.t('builds.oauthAddressNoKeys'));
        if (!lines.length) return null;
        var children = [el('strong', { class: 'builds-alert__title', text: QSPanelWords.t('builds.oauthMissingTitle') })];
        lines.forEach(function (text) { children.push(el('p', { class: 'builds-alert__body', text: text })); });
        // The link only for a role that can open the page (it is gated on listOAuthProviders).
        var url = String(CFG.oauthPageUrl || '');
        if (url !== '' && canRun('listOAuthProviders')) {
            children.push(el('p', { class: 'builds-alert__body' }, [
                el('a', { href: url, class: 'builds-alert__link', text: QSPanelWords.t('builds.oauthKeysLink') }),
            ]));
        }
        return el('div', { class: 'admin-alert admin-alert--warning' }, children);
    }

    function _renderField(label, value) {
        return el('div', { class: 'builds-field' }, [
            el('span', { class: 'builds-field__label', text: label }),
            el('span', { class: 'builds-field__value', text: value }),
        ]);
    }

    /** One line of the layout preview: a folder, and what it is for. */
    function _renderPathLine(path, note, danger) {
        return el('div', { class: 'builds-options__path' }, [
            el('code', { class: 'builds-options__path-value', text: path }),
            el('span', {
                class: 'builds-options__path-note' + (danger ? ' builds-options__path-note--danger' : ''),
                text: note,
            }),
        ]);
    }

    function _renderButton(label, iconD, kind, onClick) {
        var btn = el('button', { type: 'button', class: 'admin-btn admin-btn--' + kind, onclick: onClick });
        if (iconD) btn.appendChild(svgIcon(iconD, 16));
        btn.appendChild(document.createTextNode(' ' + label));
        return btn;
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
            el('span', { text: message }),
        ])]);
    }

    async function loadBuild() {
        setLoading(QSPanelWords.t('common.loading'));
        var res;
        try {
            res = await api('getBuild');
        } catch (e) {
            setBody([_renderAlert('error', QSPanelWords.t('common.error'), String(e && e.message ? e.message : e))]);
            return;
        }

        // 404 is the EMPTY STATE, not a failure: a project with no build is the
        // normal starting point.
        var data = (res && res.ok) ? (res.data && res.data.data) : null;
        if (!data || !data.exists) {
            if (res && !res.ok && res.status !== 404) {
                setBody([_renderAlert('error', QSPanelWords.t('common.error'), serverMessage(res, QSPanelWords.t('common.error')))]);
                return;
            }
            setBody(_renderEmptyState());
            return;
        }

        setBody(_renderBuild(data));
    }

    function _renderEmptyState(errorText) {
        var nodes = [
            el('p', { class: 'builds-empty__title', text: QSPanelWords.t('builds.none') }),
            el('p', { class: 'admin-hint', text: QSPanelWords.t('builds.noneHint') }),
        ];
        if (errorText) {
            nodes.push(_renderAlert('error', QSPanelWords.t('common.error'), errorText));
        }
        if (canRun('build')) {
            // Opened when a build was just refused: the reason is almost always
            // one of these three fields, and it is already filled in with what
            // was typed.
            nodes.push(_renderBuildOptions(!!errorText));
            // The secrets box only for a role that may set the project's keys: the server
            // refuses the parameter from any other.
            if (canRun('setOAuthCredentials')) nodes.push(_renderSecretsBox());
            nodes.push(el('div', { class: 'builds-actions' }, [
                _renderButton(QSPanelWords.t('builds.buildBtn'), ICON_BUILD, 'primary', onBuild),
            ]));
        } else {
            nodes.push(_renderAlert('info', null, QSPanelWords.t('builds.noControls')));
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
     * /shop/ needs the space. Getting either wrong is only discovered once the
     * site is on a server, which is why the layout is previewed here as it is
     * typed rather than described in a hint.
     */
    function _renderBuildOptions(open) {
        var preview = el('div', { class: 'builds-options__preview' });

        /**
         * One field. `locked` starts it READ-ONLY behind an explicit unlock.
         *
         * Used for the public folder name, which is the one of the three that is
         * usually not the author's decision at all — the destination host picks
         * it. Read-only rather than hidden, because a deployer moving to a host
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
                var unlock = _renderButton(QSPanelWords.t('builds.optUnlock'), null, 'primary', function () {
                    input.removeAttribute('readonly');
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
            preview.appendChild(el('span', { class: 'builds-field__label', text: QSPanelWords.t('builds.optPreview') }));
            preview.appendChild(_renderPathLine(pub + '/', QSPanelWords.t('builds.optPublicNote')));
            // The one the README had to be corrected for: with a space set, the
            // document root is still <public>/ and the site lives one level in,
            // so "/" is NOT the site.
            if (space !== '') {
                preview.appendChild(_renderPathLine(pub + '/' + space + '/', QSPanelWords.t('builds.optSpaceServes', { space: space })));
            }
            preview.appendChild(_renderPathLine(sec + '/', QSPanelWords.t('builds.optSecureNote'), true));
            _refreshSecretsHint();
        }
        refresh();

        var body = el('div', { class: 'builds-options__body' }, [
            field('public', QSPanelWords.t('builds.optPublicLabel'), QSPanelWords.t('builds.optPublicHint'), 'public', true),
            field('secure', QSPanelWords.t('builds.optSecureLabel'), QSPanelWords.t('builds.optSecureHint'), 'secure'),
            field('space', QSPanelWords.t('builds.optSpaceLabel'), QSPanelWords.t('builds.optSpaceHint'), ''),
            preview,
        ]);

        var details = el('details', { class: 'builds-options' });
        if (open) details.setAttribute('open', 'open');
        details.appendChild(el('summary', { class: 'builds-options__summary', text: QSPanelWords.t('builds.optTitle') }));
        details.appendChild(body);
        return details;
    }

    /**
     * "Include the OAuth secrets", beside Build now: off by default, in the warning style, with
     * what each choice means. Unticked, the build keeps each provider's client id and writes a
     * placeholder for its secret, which the deployer replaces on the server.
     */
    function _renderSecretsBox() {
        var box = el('input', { type: 'checkbox', id: 'builds-opt-oauth-secrets' });
        box.checked = includeOAuthSecrets;
        box.addEventListener('change', function () { includeOAuthSecrets = box.checked; });
        secretsHint = el('p', { class: 'builds-alert__body' });
        _refreshSecretsHint();
        return el('div', { class: 'admin-alert admin-alert--warning' }, [
            el('label', { class: 'admin-checkbox', for: 'builds-opt-oauth-secrets' }, [
                box,
                el('strong', { class: 'builds-alert__title' }, [svgIcon(ICON_WARNING, 16), ' ' + QSPanelWords.t('builds.secretsLabel')]),
            ]),
            secretsHint,
        ]);
    }

    /** The box's explanation, with the secure folder the next build is given. */
    function _refreshSecretsHint() {
        if (!secretsHint) return;
        secretsHint.textContent = QSPanelWords.t('builds.secretsHint', { secure: buildOptions.secure || 'secure' });
    }

    function _renderBuild(b) {
        var nodes = [];

        var nameChildren = [el('strong', { class: 'builds-name', text: b.name })];
        if (b.complete === false) {
            nameChildren.push(el('span', { class: 'builds-badge builds-badge--incomplete', text: QSPanelWords.t('builds.incomplete') }));
        }
        nodes.push(el('div', { class: 'builds-head' }, nameChildren));

        if (b.complete === false) {
            nodes.push(_renderAlert('warning', null, QSPanelWords.t('builds.incompleteBody')));
        }
        if (b.oauth_secrets_included) {
            nodes.push(_renderAlert('warning', null, QSPanelWords.t('builds.oauthWarn')));
        }
        var oauthGaps = _renderOAuthGaps(b.oauth, b.secure);
        if (oauthGaps) nodes.push(oauthGaps);

        nodes.push(_renderFacts(b));

        // A build that may carry the OAuth secrets is out of reach of a role that may
        // build but not set the project's keys: say so where the buttons would be, and
        // who to ask, since that role cannot make room for a build of its own either.
        var outOfReach = _outOfReach(b) && (canRun('downloadBuild') || canRun('deleteBuild'));

        // A build already exists, so `build` will refuse. Say so HERE rather than
        // letting the user find out from a 409 — and say what to do about it.
        if (canRun('build') && !outOfReach) {
            nodes.push(_renderAlert('info', QSPanelWords.t('builds.oneAtATime'), QSPanelWords.t('builds.oneAtATimeBody')));
        }

        if (outOfReach) {
            nodes.push(_renderAlert('info', null, QSPanelWords.t('builds.secretsRoleOnly')));
        }

        var actions = [];
        if (_mayDownload(b)) {
            actions.push(_renderButton(QSPanelWords.t('builds.downloadBtn'), ICON_DOWNLOAD, 'outline', function () {
                onDownload(this);
            }));
        }
        if (_mayDelete(b)) {
            actions.push(_renderButton(QSPanelWords.t('builds.deleteBtn'), null, 'danger', function () {
                onDeleteConfirm(b);
            }));
        }
        if (actions.length) {
            nodes.push(el('div', { class: 'builds-actions' }, actions));
        } else if (!outOfReach) {
            nodes.push(_renderAlert('info', null, QSPanelWords.t('builds.noControls')));
        }

        // How this build reaches a server: only for a caller who can take the archive.
        if (_mayDownload(b)) {
            nodes.push(el('p', { class: 'admin-hint', text: QSPanelWords.t('builds.serverHint') }));
        }

        return nodes;
    }

    /**
     * A finished build only a role that may set the project's keys can touch, and this
     * caller's role may not: the rule downloadBuild and deleteBuild answer by. A manifest
     * that does not say the build carries no OAuth secrets is read as carrying them.
     */
    function _outOfReach(b) {
        return b.complete !== false && b.oauth_secrets_included !== false && !canRun('setOAuthCredentials');
    }

    /** Whether this caller may download the build: an incomplete one is refused to everyone. */
    function _mayDownload(b) {
        return b.complete !== false && canRun('downloadBuild') && !_outOfReach(b);
    }

    /** Whether this caller may delete the build: an incomplete one goes for every role that may build. */
    function _mayDelete(b) {
        return canRun('deleteBuild') && !_outOfReach(b);
    }

    function _renderFacts(b) {
        var fields = [];
        if (b.created) {
            fields.push(_renderField(QSPanelWords.t('builds.fieldCreated'), QSPanelWords.date(b.created)));
        }
        fields.push(_renderField(QSPanelWords.t('builds.fieldSize'), window.QuickSiteUtils.formatSize(b.size_bytes)));
        if (typeof b.file_count === 'number') {
            fields.push(_renderField(QSPanelWords.t('builds.fieldFiles'), QSPanelWords.number(b.file_count)));
        }
        if (typeof b.pages_count === 'number') {
            fields.push(_renderField(QSPanelWords.t('builds.fieldPages'), QSPanelWords.number(b.pages_count)));
        }
        if (Array.isArray(b.languages) && b.languages.length) {
            // Language codes, a value rather than a sentence: listed with the panel's language's separator, no "and".
            fields.push(_renderField(QSPanelWords.t('builds.fieldLanguages'),
                new Intl.ListFormat(QSPanelWords.lang, { style: 'narrow', type: 'conjunction' }).format(b.languages.map(String))));
        }
        if (b.public) fields.push(_renderField(QSPanelWords.t('builds.fieldPublic'), b.public));
        if (b.secure) fields.push(_renderField(QSPanelWords.t('builds.fieldSecure'), b.secure));
        fields.push(_renderField(QSPanelWords.t('builds.fieldSpace'), b.space ? b.space : QSPanelWords.t('builds.spaceRoot')));
        return el('div', { class: 'builds-facts' }, fields);
    }

    // ============================================================
    // Actions
    // ============================================================

    async function onBuild() {
        setLoading(QSPanelWords.t('builds.building'));
        // Empty means "use the default", which is what the server does with an
        // absent parameter — so an empty field is sent as absent rather than as
        // an empty string the validator would then have to have an opinion about.
        var body = {};
        if (buildOptions.public) body.public = buildOptions.public;
        if (buildOptions.secure) body.secure = buildOptions.secure;
        if (buildOptions.space) body.space = buildOptions.space;
        if (includeOAuthSecrets) body.include_oauth_secrets = true;

        var res;
        try {
            res = await api('build', 'POST', body);
        } catch (e) {
            setBody(_renderEmptyState(String(e && e.message ? e.message : e)));
            return;
        }

        if (res && res.ok) {
            toast(QSPanelWords.t('builds.builtMsg'), 'success');
            // The next build starts from the safe default again.
            includeOAuthSecrets = false;
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
            toast(serverMessage(res, QSPanelWords.t('common.error')), 'warning');
            return;
        }
        // Anything else — a rejected folder name, most likely — comes back into
        // the FORM with what was typed still in it and the server's own reason
        // above it. Replacing the panel with a bare error would make the user
        // re-enter three fields to fix one character.
        setBody(_renderEmptyState(serverMessage(res, QSPanelWords.t('common.error'))));
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
                toast(serverMessage(res, QSPanelWords.t('builds.downloadFailed')), 'error');
                return false;
            }
            return true;
        } catch (e) {
            toast(QSPanelWords.t('builds.downloadFailed'), 'error');
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
     * question says both of those things and puts the download one click away in
     * its body, instead of leaving the user to cancel, download, and come back.
     * It is the panel's one confirm; a refusal stays in it in the server's words.
     */
    async function onDeleteConfirm(b) {
        var body = [
            el('p', { text: QSPanelWords.t('builds.deleteBody') }),
            el('p', { class: 'admin-hint', text: QSPanelWords.t('builds.deleteWhy') }),
            el('p', { class: 'builds-modal__name', text: b.name }),
        ];
        if (_mayDownload(b)) {
            body.push(el('div', {}, [_renderButton(QSPanelWords.t('builds.deleteDownloadFirst'), ICON_DOWNLOAD, 'outline', async function () {
                var ok = await onDownload(this);
                if (ok) toast(QSPanelWords.t('builds.deleteDownloaded'), 'success');
            })]));
        }
        var deleted = await QuickSiteUtils.confirm('', {
            title: QSPanelWords.t('builds.deleteTitle'),
            body: body,
            type: 'danger',
            confirmText: QSPanelWords.t('builds.deleteBtn'),
            action: async function () {
                var res = await api('deleteBuild', 'POST', {});
                if (!res || !res.ok) return { ok: false, message: serverMessage(res, QSPanelWords.t('builds.deleteFailed')) };
                return true;
            },
        });
        if (!deleted) return;
        toast(QSPanelWords.t('builds.deletedMsg'), 'success');
        await loadBuild();
        await loadSpace(true);
    }

    // ============================================================
    // Space — read from the EXISTING measurement, never a second one
    // ============================================================

    /**
     * The account space report already returns a per-project breakdown with `builds` as
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
            el('span', { text: QSPanelWords.t('common.loading') }),
        ]));

        var res;
        try {
            // Not a command — your own quota is an account fact.
            res = await window.QuickSiteAdmin.accountRequest(
                refresh ? 'space-usage?refresh=1' : 'space-usage', 'GET'
            );
        } catch (e) {
            clearNode(panel);
            panel.appendChild(_renderAlert('error', QSPanelWords.t('common.error'), String(e && e.message ? e.message : e)));
            return;
        }

        var data = (res && res.ok) ? (res.data && res.data.data) : null;
        clearNode(panel);
        if (!data) {
            panel.appendChild(_renderAlert('error', QSPanelWords.t('common.error'), serverMessage(res, QSPanelWords.t('common.error'))));
            return;
        }

        var row = null;
        (data.projects || []).forEach(function (p) {
            if (p.project === CFG.project) row = p;
        });

        if (!row) {
            panel.appendChild(el('p', { class: 'admin-hint', text: QSPanelWords.t('builds.spaceNotOwner') }));
            return;
        }

        var quota = data.quota || {};
        var buildBytes = (row.builds && row.builds.size) || 0;

        panel.appendChild(el('div', { class: 'builds-facts' }, [
            _renderField(QSPanelWords.t('builds.spaceProject'), window.QuickSiteUtils.formatSize(row.total)),
            _renderField(QSPanelWords.t('builds.spaceOfWhich'), window.QuickSiteUtils.formatSize(buildBytes)),
            quota.configured
                ? _renderField(QSPanelWords.t('builds.spaceFree'), window.QuickSiteUtils.formatSize(quota.free))
                : null,
        ]));

        if (!quota.configured) {
            panel.appendChild(el('p', { class: 'admin-hint', text: QSPanelWords.t('builds.spaceNoQuota') }));
        } else {
            var need = Number(row.content) || 0;
            var available = (Number(quota.free) || 0) + buildBytes;
            var fraction = typeof CFG.spaceWarnFraction === 'number' ? CFG.spaceWarnFraction : 0.5;
            if (need > available * fraction) {
                panel.appendChild(_renderAlert(
                    'warning',
                    QSPanelWords.t('builds.spaceWarnTitle'),
                    QSPanelWords.t('builds.spaceWarnBody', {
                        need: window.QuickSiteUtils.formatSize(need),
                        free: window.QuickSiteUtils.formatSize(available)
                    })
                ));
            }
        }

        panel.appendChild(el('div', { class: 'builds-actions' }, [
            _renderButton(QSPanelWords.t('builds.refresh'), ICON_REFRESH, 'ghost', function () {
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
        if (chip && CFG.project) chip.textContent = CFG.projectLabel || CFG.project;

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
