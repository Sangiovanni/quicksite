/**
 * My Account page — the signed-in account's own self-service.
 *
 * Writes: the password change and the account deletion go to /admin/self
 * (they are account self-service, not commands); logoutSession
 * {everywhere} is still a command. All three act only on the caller and
 * re-verify the current password server-side — the client-side checks here
 * exist to give a fast, honest answer, never as the gate.
 *
 * Built with QSDom.el + named _render* helpers (one Element each) per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 * Static structure lives in templates/pages/account.php.
 */
(function () {
    'use strict';

    var CFG = window.QS_ACCOUNT_CONFIG || {};
    var T = window.QS_ACCOUNT_I18N || {};
    var el = window.QSDom.el;
    var clearNode = window.QSDom.clear;

    function api(cmd, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.apiRequest(cmd, 'POST', body);
    }

    // The password change and the account deletion are NOT commands — they
    // go to /admin/self. Same {ok, status, data} shape as api(), so the
    // callers below are unchanged apart from which door they knock on.
    function account(route, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.accountRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.accountRequest(route, 'POST', body);
    }

    function toast(message, type) {
        if (window.QuickSiteUtils && typeof window.QuickSiteUtils.showToast === 'function') {
            window.QuickSiteUtils.showToast(message, type || 'info');
        }
    }

    function serverMessage(res, fallback) {
        return (res && res.data && (res.data.message || res.data.error)) || fallback || T.error || 'Error';
    }

    /**
     * The refusal of a credential check (the password change, the deletion), in the panel's words:
     * a wrong answer (`wrong`), followed by the wait it started when it started one (data.retry_after,
     * as the sign-in pages say it); a check made during a wait (429) says the wait. Any other
     * answer is the server's own message.
     */
    function credentialRefusal(res, wrong) {
        var status = res && res.status;
        var envelope = (res && res.data) || {};
        var wait = Number((envelope.data && envelope.data.retry_after) || 0);
        var waitText = wait > 0
            ? (T.throttled || 'Too many failed attempts — try again in :duration')
                .split(':duration').join(window.QuickSiteAdmin.durationText(wait))
            : '';
        if (status === 401 && envelope.code === 'auth.invalid_credentials') {
            return waitText !== '' ? wrong + ' ' + waitText : wrong;
        }
        if (status === 429 && waitText !== '') {
            return waitText;
        }
        return serverMessage(res);
    }

    /** Send the browser to the login page — the only sane place after a session ends. */
    function toLogin() {
        window.location.href = CFG.loginUrl || '/admin/login';
    }

    // ============================================================
    // Shared pieces
    // ============================================================

    /**
     * A question in the panel's one confirm (QuickSiteUtils.confirm): its title, what it shows (body), Cancel and the
     * verb. `act` runs on the verb with the question open: true closes it, {ok: false, message} keeps it open showing
     * those words. Answers whether it was done.
     */
    function ask(title, body, verb, danger, act) {
        return QuickSiteUtils.confirm('', {
            title: title,
            body: body,
            confirmText: verb,
            type: danger ? 'danger' : 'info',
            action: act,
        });
    }

    /** One project standing between the caller and deleting their account. */
    function _renderBlockerRow(project) {
        var count = (typeof project.member_count === 'number')
            ? (project.member_count + ' ' + (T.members || 'members'))
            : null;
        return el('li', { class: 'account-blocker' }, [
            el('strong', { text: window.QuickSiteAdmin.projectLabel(project.project || '?', project.name) }),
            count ? el('span', { class: 'admin-hint', text: count }) : null,
        ]);
    }

    /** The whole "you still own these" panel, or nothing when there are none. */
    function _renderBlockers(projects) {
        var list = el('ul', { class: 'account-blocker-list' });
        projects.forEach(function (p) { list.appendChild(_renderBlockerRow(p)); });
        return el('div', { class: 'admin-alert admin-alert--warning' }, [
            el('strong', { text: T.soleOwnerTitle || 'You still own these projects' }),
            list,
            el('p', { class: 'admin-hint', text: T.soleOwnerHint || '' }),
        ]);
    }

    function showBlockers(projects) {
        var host = document.getElementById('account-delete-blockers');
        if (!host) return;
        clearNode(host);
        if (projects && projects.length) {
            host.appendChild(_renderBlockers(projects));
        }
    }

    // ============================================================
    // Change password
    // ============================================================

    function setupChangePassword() {
        var btn = document.getElementById('btn-change-password');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var currentEl = document.getElementById('account-current-password');
            var newEl = document.getElementById('account-new-password');
            var confirmEl = document.getElementById('account-confirm-password');
            var current = currentEl ? currentEl.value : '';
            var next = newEl ? newEl.value : '';
            var repeat = confirmEl ? confirmEl.value : '';

            if (current === '' || next === '') {
                toast(T.passwordRequired || 'Fill in both fields.', 'warning');
                return;
            }
            if (next !== repeat) {
                toast(T.passwordMismatch || 'The two new passwords do not match.', 'warning');
                return;
            }
            if (next.length < (CFG.minPasswordLength || 12)) {
                toast(T.passwordTooShort || 'The new password is too short.', 'warning');
                return;
            }
            if (next === current) {
                toast(T.passwordSameAsOld || 'The new password is the same as the current one.', 'warning');
                return;
            }

            btn.disabled = true;
            account('change-password', { current_password: current, new_password: next }).then(function (res) {
                btn.disabled = false;
                if (res && res.ok) {
                    if (currentEl) currentEl.value = '';
                    if (newEl) newEl.value = '';
                    if (confirmEl) confirmEl.value = '';
                    toast(T.passwordChanged || 'Password changed.', 'success');
                    return;
                }
                // A wrong current password is answered 401 auth.invalid_credentials,
                // which api.js deliberately does NOT treat as a dead session — the
                // user stays on this page and simply sees the refusal.
                toast(credentialRefusal(res, T.wrongCurrent || 'Your current password is not correct.'),
                    (res && res.status === 429) ? 'warning' : 'error');
            }).catch(function (err) {
                btn.disabled = false;
                toast((err && err.message) || String(err), 'error');
            });
        });
    }

    // ============================================================
    // Sign out everywhere
    // ============================================================

    function setupLogoutEverywhere() {
        var btn = document.getElementById('btn-logout-everywhere');
        if (!btn) return;

        btn.addEventListener('click', function () {
            ask(
                T.everywhereTitle || 'Sign out everywhere?',
                [el('p', { text: T.everywhereBody || '' })],
                T.everywhereBtn || 'Sign out everywhere', false,
                async function () {
                    var res;
                    try {
                        res = await api('logoutSession', { everywhere: true });
                    } catch (e) {
                        // The session may already be gone — the login page is the
                        // right destination either way.
                        toLogin();
                        return true;
                    }
                    if (!res || !res.ok) return { ok: false, message: serverMessage(res) };
                    toast(T.everywhereDone || 'Signed out everywhere.', 'success');
                    toLogin();
                    return true;
                }
            );
        });
    }

    // ============================================================
    // Delete account
    // ============================================================

    function setupDeleteAccount() {
        var btn = document.getElementById('btn-delete-account');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var passwordEl = document.getElementById('account-delete-password');
            var typedEl = document.getElementById('account-delete-typed');
            var password = passwordEl ? passwordEl.value : '';
            var typed = typedEl ? typedEl.value.trim() : '';

            if (password === '') {
                toast(T.deleteNeedsPassword || 'Enter your current password.', 'warning');
                return;
            }
            // The typed confirmation is the USERNAME — the one string only this
            // account's owner has in mind, and one that cannot be clicked through
            // the way a checkbox can. The page does not hold it, so this only
            // checks something was typed: the server compares it, together with
            // the password, and a wrong one of either is refused the same way.
            if (typed === '') {
                toast(T.deleteNeedsTyped || 'Type your username exactly to confirm.', 'warning');
                return;
            }

            ask(
                T.deleteTitle || 'Delete your account — final confirmation',
                [el('p', { text: T.deleteBody || '' })],
                T.deleteBtn || 'Delete my account', true,
                async function () {
                    var res = await account('delete', { current_password: password, username: typed, confirm: true });
                    if (res && res.ok) {
                        toast(T.deleteDone || 'Your account has been deleted.', 'success');
                        toLogin();
                        return true;
                    }
                    // 409 account.sole_owner names the projects that block the
                    // deletion — list them instead of leaving the user to guess.
                    var data = (res && res.data && res.data.data) || {};
                    if (res && res.status === 409 && Array.isArray(data.owned_projects)) {
                        showBlockers(data.owned_projects);
                    }
                    return { ok: false, message: credentialRefusal(res, T.wrongCredentials || 'Your current password or your username is not correct.') };
                }
            );
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupChangePassword();
        setupLogoutEverywhere();
        setupDeleteAccount();
    });
})();
