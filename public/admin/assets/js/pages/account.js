/**
 * My Account page (beta.11 S1.3) — the signed-in account's own self-service.
 *
 * Writes: the password change and the account deletion go to /admin/self
 * (they are account self-service, not commands — beta.11 S6); logoutSession
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

    // The password change and the account deletion are NOT commands (S6) — they
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

    /** Send the browser to the login page — the only sane place after a session ends. */
    function toLogin() {
        window.location.href = CFG.loginUrl || '/admin/login';
    }

    // ============================================================
    // Shared pieces
    // ============================================================

    /**
     * Confirm modal. Same shape as the members page's, kept local rather than
     * shared: the two differ in root node and in nothing else today, and a
     * premature shared modal module would have to guess at both pages' futures.
     */
    function _renderModal(title, bodyNodes, confirmLabel, danger, onConfirm) {
        var root = document.getElementById('account-modal-root');
        if (!root) return null;
        clearNode(root);

        function close() { clearNode(root); }

        var confirmBtn = el('button', {
            class: 'admin-btn ' + (danger ? 'admin-btn--danger' : 'admin-btn--primary'),
            type: 'button',
            onclick: function () {
                confirmBtn.disabled = true;
                onConfirm(close, confirmBtn);
            },
        }, [confirmLabel]);

        var modal = el('div', { class: 'admin-modal' }, [
            el('div', { class: 'admin-modal__backdrop', onclick: close }),
            el('div', { class: 'admin-modal__content' }, [
                el('div', { class: 'admin-modal__header' }, [
                    el('h3', { class: 'admin-modal__title', text: title }),
                    el('button', { class: 'admin-modal__close', type: 'button', 'aria-label': T.cancel || 'Cancel', onclick: close }, ['×']),
                ]),
                el('div', { class: 'admin-modal__body' }, bodyNodes),
                el('div', { class: 'admin-modal__footer' }, [
                    el('button', { class: 'admin-btn admin-btn--ghost', type: 'button', onclick: close }, [T.cancel || 'Cancel']),
                    confirmBtn,
                ]),
            ]),
        ]);
        root.appendChild(modal);
        return { close: close };
    }

    /** One project standing between the caller and deleting their account. */
    function _renderBlockerRow(project) {
        var count = (typeof project.member_count === 'number')
            ? (project.member_count + ' ' + (T.members || 'members'))
            : null;
        return el('li', { class: 'account-blocker' }, [
            el('strong', { text: project.name || project.project || '?' }),
            el('code', { class: 'account-blocker__id', text: String(project.project || '') }),
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
                toast(serverMessage(res), (res && res.status === 429) ? 'warning' : 'error');
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
            _renderModal(
                T.everywhereTitle || 'Sign out everywhere?',
                [el('p', { text: T.everywhereBody || '' })],
                T.everywhereBtn || 'Sign out everywhere', false,
                function (close) {
                    api('logoutSession', { everywhere: true }).then(function (res) {
                        close();
                        if (res && res.ok) {
                            toast(T.everywhereDone || 'Signed out everywhere.', 'success');
                            toLogin();
                            return;
                        }
                        toast(serverMessage(res), 'error');
                    }).catch(function () {
                        // The session may already be gone — the login page is the
                        // right destination either way.
                        toLogin();
                    });
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
            // The typed confirmation is deliberately the USERNAME: it is the one
            // string only this account's owner has in mind, and it cannot be
            // clicked through the way a checkbox can.
            if (typed === '' || typed !== (CFG.username || '')) {
                toast(T.deleteNeedsTyped || 'Type your username exactly to confirm.', 'warning');
                return;
            }

            _renderModal(
                T.deleteTitle || 'Delete your account — final confirmation',
                [el('p', { text: T.deleteBody || '' })],
                T.deleteBtn || 'Delete my account', true,
                function (close) {
                    account('delete', { current_password: password, confirm: true }).then(function (res) {
                        close();
                        if (res && res.ok) {
                            toast(T.deleteDone || 'Your account has been deleted.', 'success');
                            toLogin();
                            return;
                        }
                        // 409 account.sole_owner names the projects that block the
                        // deletion — list them instead of leaving the user to guess.
                        var data = (res && res.data && res.data.data) || {};
                        if (res && res.status === 409 && Array.isArray(data.owned_projects)) {
                            showBlockers(data.owned_projects);
                        }
                        toast(serverMessage(res), (res && res.status === 409) ? 'warning' : 'error');
                    }).catch(function (err) {
                        toast((err && err.message) || String(err), 'error');
                    });
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
