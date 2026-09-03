/**
 * QuickSite Admin — operator update notice.
 *
 * DISCOVERY, NOT ACTION. An update is applied on the server with git, which has
 * no HTTP surface at all. Nothing here applies anything; it only tells the
 * person who maintains the machine that there is something to apply, because a
 * procedure cannot tell you — you have to remember to run it.
 *
 * ⚠ THIS FILE NAMES NO SCRIPT. It used to point at update.sh / update.bat, which
 * were removed: on a git install the whole procedure is `git pull`, and three
 * platforms of shell wrapping one command produced more defects than the command
 * ever would. If a notice string ever names a file again, that file has to exist.
 *
 * WHO SEES IT is decided SERVER-SIDE, in layout.php, from
 * secure/management/config/operator.php. This file is not even loaded into a
 * page whose account is not listed, so "hide it in CSS" is not what is
 * happening. The list grants nothing: the update-check endpoint stays callable
 * by any authenticated account exactly as before, and this module calls nothing
 * else. It is a display preference, and treating it as anything more would
 * reintroduce the installation-wide principal beta.10 removed.
 *
 * IT IS NOT A COMMAND. The update check reports on the INSTALLATION, not on any
 * project, so it is an arm of the panel's own helper API (/admin/api) rather
 * than a member of the project CLI under /management.
 *
 * THE THROTTLE IS NOT COSMETIC. Unauthenticated GitHub API calls are limited to
 * 60 per hour per address, and the check is a live network round trip on the
 * server. Firing it on every panel navigation would spend that budget in a few
 * minutes of ordinary editing and make each page wait on GitHub. One check per
 * QS_UPDATE_CHECK_INTERVAL_MS per browser is plenty for news that changes a few
 * times a year.
 *
 * DOM is built with createElement + textContent (CLAUDE.md HTML-in-JS hygiene).
 */
(function () {
    'use strict';

    var CHECK_INTERVAL_MS = 6 * 60 * 60 * 1000;   // 6 hours

    var cfg = window.QUICKSITE_CONFIG || {};
    if (!cfg.isOperator) {
        return;   // belt: the layout does not even emit this script otherwise
    }

    var host = document.getElementById('admin-update-notice');
    if (!host || !window.QSDom || !window.QuickSiteAPI) {
        return;
    }

    var keys = window.QuickSiteStorageKeys || {};
    var KEY_AT     = keys.updateCheckAt || 'qs_update_check_at';
    var KEY_HIDDEN = keys.updateNoticeHidden || 'qs_update_notice_hidden';
    var KEY_SEEN   = keys.updateLastSeen || 'qs_update_last_seen';

    // Storage can throw (private-browsing quota, disabled storage). A failure to
    // remember the last check must degrade to "check again", never to a broken
    // panel — so every access is wrapped and answers a neutral value.
    function readStore(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function writeStore(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* nothing to do */ }
    }

    var strings = (cfg.translations && cfg.translations.updateNotice) || {};
    function t(key, fallback) {
        return typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : fallback;
    }

    /**
     * The banner. ONE Element, built from parts — the panel's `_render*` idiom.
     * @param {string} latest   version string from the update check
     * @param {string} current  the running version, or '' when not known
     * @param {string} url      release page on GitHub ('' when absent)
     * @returns {HTMLElement}
     */
    function _renderNotice(latest, current, url) {
        var D = window.QSDom;

        // Composed from text nodes rather than one interpolated string, so a
        // version value can never be read as markup wherever it came from.
        var text = D.el('span', {}, [
            D.el('strong', { text: t('label', 'QuickSite update:') }),
            ' ',
            D.el('span', { text: t('available', 'version') + ' ' }),
            D.el('code', { text: latest })
        ]);

        // The running version is only in hand right after a live check. The
        // throttled re-show has the new version and not the old one, and an
        // empty <code> would read as a bug — so the clause is dropped instead
        // of being filled with a placeholder.
        if (current !== '') {
            text.appendChild(D.el('span', { text: ' ' + t('youHave', 'is available — you have') + ' ' }));
            text.appendChild(D.el('code', { text: current }));
            text.appendChild(document.createTextNode('. '));
        } else {
            text.appendChild(D.el('span', { text: ' ' + t('availableSuffix', 'is available.') + ' ' }));
        }
        text.appendChild(D.el('span', {
            text: t('howTo', 'Update on the server with git pull, then reload this page.')
        }));

        if (url) {
            text.appendChild(document.createTextNode(' '));
            text.appendChild(D.el('a', {
                'class': 'admin-security-warning__link',
                href: url,
                target: '_blank',
                rel: 'noopener noreferrer',
                text: t('releaseNotes', 'Release notes')
            }));
        }

        var dismiss = D.el('button', {
            type: 'button',
            'class': 'admin-security-warning__dismiss',
            'aria-label': t('dismiss', 'Dismiss'),
            onclick: function () {
                // The VERSION is remembered, not a boolean — the next release
                // has a different value and shows the notice again.
                writeStore(KEY_HIDDEN, latest);
                host.hidden = true;
                window.QSDom.clear(host);
            }
        });
        dismiss.textContent = '×';

        return D.el('div', { 'class': 'admin-security-warning__content' }, [
            D.svgIcon('M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16', 18),
            text,
            dismiss
        ]);
    }

    function show(data) {
        var latest  = String(data.latest_version || '');
        var current = String(data.current_version || '');
        if (latest === '') {
            return;
        }
        if (readStore(KEY_HIDDEN) === latest) {
            return;   // this exact version was dismissed
        }
        window.QSDom.clear(host);
        host.appendChild(_renderNotice(latest, current, String(data.release_url || '')));
        host.hidden = false;
    }

    function check() {
        // The arm carries no project and works for an operator with zero
        // memberships. fetchHelper RESOLVES with the arm's data and REJECTS on a
        // non-success answer, so the catch below is the offline/error path.
        window.QuickSiteAPI.fetchHelper('update-check')
            .then(function (d) {
                writeStore(KEY_AT, String(Date.now()));
                if (!d) {
                    return;   // nothing to say
                }
                if (d.update_available === true) {
                    show(d);
                    writeStore(KEY_SEEN, String(d.latest_version || ''));
                } else {
                    // Up to date, or the check could not reach GitHub. Forget the
                    // previous finding so a banner does not survive the update
                    // that answered it.
                    writeStore(KEY_SEEN, '');
                }
            })
            .catch(function () {
                // A failed check is not news. Record the attempt so a server with
                // no outbound access does not retry on every single navigation.
                writeStore(KEY_AT, String(Date.now()));
            });
    }

    var last = parseInt(readStore(KEY_AT) || '0', 10);
    if (!isFinite(last) || last <= 0 || (Date.now() - last) > CHECK_INTERVAL_MS) {
        check();
    } else {
        // Inside the throttle window: re-show what the last check found, without
        // spending a request. Nothing is cached beyond the version string, so a
        // stale banner can only ever be one interval old.
        var seen = readStore(KEY_SEEN);
        if (seen && seen !== '' && readStore(KEY_HIDDEN) !== seen) {
            show({ latest_version: seen, current_version: '', release_url: '' });
        }
    }
})();
