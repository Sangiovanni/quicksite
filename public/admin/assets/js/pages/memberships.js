/**
 * My Memberships page (C8 8.3c) — the caller's own membership surface.
 *
 * Calls listProjects / listMyInvitations / listMyProposals plus the
 * self-service verbs (accept/decline/leave/withdraw/dismiss/requestToJoin,
 * setSelectedProject) via QuickSiteAdmin.apiRequest — all GLOBAL commands,
 * so a 0-membership account gets a clean page, never 403 noise.
 *
 * Built with QSDom.el + named _render* helpers (one Element each) per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 * Static structure lives in templates/pages/memberships.php.
 */
(function () {
    'use strict';

    var CFG = window.QS_MEMBERSHIPS_CONFIG || {};
    var T = window.QS_MEMBERSHIPS_I18N || {};
    var el = window.QSDom.el;
    var svgIcon = window.QSDom.svgIcon;
    var clearNode = window.QSDom.clear;

    var ICON_CHECK = 'M20 6L9 17l-5-5';
    var ICON_X = 'M18 6L6 18M6 6l12 12';
    var ICON_EDIT = 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z';
    var ICON_OUT = 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9';

    function api(cmd, method, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.apiRequest(cmd, method, body);
    }

    function toast(message, type) {
        if (window.QuickSiteUtils && typeof window.QuickSiteUtils.showToast === 'function') {
            window.QuickSiteUtils.showToast(message, type || 'info');
        }
    }

    function serverMessage(res, fallback) {
        return (res && res.data && (res.data.message || res.data.error)) || fallback || T.error || 'Error';
    }

    function notifyMembershipChange() {
        window.dispatchEvent(new CustomEvent('quicksite:memberships-changed'));
    }

    // ============================================================
    // Small shared pieces
    // ============================================================

    function _renderRoleChip(role) {
        return el('span', { class: 'members-role-chip members-role-chip--' + String(role || ''), text: String(role || '?') });
    }

    function _renderNote(noteText) {
        return el('blockquote', { class: 'members-note', text: noteText });
    }

    function _renderMeta(parts) {
        var wrap = el('span', { class: 'members-row__meta' });
        parts.forEach(function (p) {
            if (p == null || p === '') return;
            if (wrap.childNodes.length > 0) {
                wrap.appendChild(el('span', { class: 'members-row__meta-sep', text: ' · ' }));
            }
            if (typeof p === 'string') wrap.appendChild(document.createTextNode(p));
            else wrap.appendChild(p);
        });
        return wrap;
    }

    function _renderActionBtn(label, iconD, kind, onClick) {
        var b = el('button', {
            class: 'admin-btn admin-btn--sm ' + (kind === 'danger' ? 'admin-btn--danger' : (kind === 'primary' ? 'admin-btn--primary' : 'admin-btn--ghost')),
            type: 'button',
            onclick: onClick,
        });
        if (iconD) b.appendChild(svgIcon(iconD, 14));
        b.appendChild(document.createTextNode(label));
        return b;
    }

    function _renderEmpty(text) {
        return el('div', { class: 'admin-empty members-empty', text: text });
    }

    function _renderRow(mainChildren, actionButtons) {
        var main = el('div', { class: 'members-row__main' }, mainChildren);
        var actions = el('div', { class: 'members-row__actions' }, actionButtons);
        return el('div', { class: 'members-row' }, [main, actions]);
    }

    // One shared confirm modal (admin-modal classes come from admin.css).
    function _renderConfirmModal(title, bodyNodes, confirmLabel, danger, onConfirm) {
        var root = document.getElementById('memberships-modal-root');
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

        var modal = el('div', { class: 'admin-modal members-modal-open' }, [
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

    function setCount(chipId, n) {
        var chip = document.getElementById(chipId);
        if (!chip) return;
        chip.textContent = String(n);
        chip.hidden = n === 0;
    }

    // ============================================================
    // My projects
    // ============================================================

    function _renderProjectRow(p) {
        var isOwned = p.my_role === 'owner';
        var isEditing = p.name === CFG.editedProject;

        var title = el('span', { class: 'members-row__title' }, [
            el('strong', { text: p.name }),
            (p.site_name && p.site_name !== p.name) ? el('span', { class: 'members-row__subtle', text: ' — ' + p.site_name }) : null,
        ]);
        var chips = el('span', { class: 'members-row__chips' }, [
            _renderRoleChip(p.my_role),
            isOwned ? el('span', { class: 'members-chip members-chip--owned', text: T.ownedChip || 'OWNED' }) : null,
            isEditing ? el('span', { class: 'members-chip members-chip--editing', text: T.editingChip || 'editing' }) : null,
        ]);

        var actions = [];
        if (!isEditing) {
            actions.push(_renderActionBtn(T.editBtn || 'Edit this project', ICON_EDIT, 'ghost', function () {
                onEditProject(p.name, this);
            }));
        }
        if (!isOwned) {
            actions.push(_renderActionBtn(T.leaveBtn || 'Leave', ICON_OUT, 'danger', function () {
                onLeaveProject(p.name);
            }));
        }
        return _renderRow([title, chips], actions);
    }

    function renderProjects(projects) {
        var root = document.getElementById('memberships-projects');
        clearNode(root);
        if (!projects.length) {
            root.appendChild(_renderEmpty(T.projectsEmpty || 'No projects.'));
            return;
        }
        projects.forEach(function (p) { root.appendChild(_renderProjectRow(p)); });
    }

    function onEditProject(project, btn) {
        if (btn) btn.disabled = true;
        api('setSelectedProject', 'POST', { project: project }).then(function (res) {
            if (res && res.ok) {
                // Cache-busted navigation (not reload()) — same bfcache-safe
                // pattern as the header picker and the dashboard switch.
                window.location.href = window.location.pathname + '?t=' + Date.now();
            } else {
                if (btn) btn.disabled = false;
                toast(serverMessage(res), 'error');
            }
        });
    }

    function onLeaveProject(project) {
        _renderConfirmModal(
            T.leaveTitle || 'Leave project',
            [el('p', { text: (T.leaveConfirm || 'Leave {project}?').replace('{project}', project) })],
            T.leaveBtn || 'Leave', true,
            function (close, btn) {
                api('leaveProject', 'POST', { project: project }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.leftMsg || 'You left the project', 'success');
                        // Leaving changes the caller's SERVER-computed permission set
                        // (nav links, PAGE_PERMISSIONS, the project picker) — a soft
                        // refreshAll() leaves stale tabs that only correct themselves on
                        // the next click. Reload instead; no notifyMembershipChange first
                        // (its fetches would be cancelled by the reload — 8.3c round 2).
                        window.location.href = window.location.pathname + '?t=' + Date.now();
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                });
            }
        );
    }

    // ============================================================
    // Invitation inbox
    // ============================================================

    function _renderInviteRow(inv) {
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: inv.project_name || inv.project }),
                (inv.project_name && inv.project_name !== inv.project) ? el('span', { class: 'members-row__subtle', text: ' (' + inv.project + ')' }) : null,
            ]),
            el('span', { class: 'members-row__chips' }, [
                el('span', { class: 'members-row__subtle', text: (T.offeredRole || 'offered role') + ': ' }),
                _renderRoleChip(inv.role),
            ]),
            _renderMeta([
                inv.invited_by ? (T.invitedBy || 'Invited by') + ' ' + (inv.invited_by.name || inv.invited_by.user_id) : null,
                inv.sponsored_by ? (T.proposedBy || 'proposed by') + ' ' + (inv.sponsored_by.name || inv.sponsored_by.user_id) : null,
                inv.at || null,
            ]),
        ];
        if (inv.note) main.push(_renderNote(inv.note));
        return _renderRow(main, [
            _renderActionBtn(T.accept || 'Accept', ICON_CHECK, 'primary', function () { onAccept(inv); }),
            _renderActionBtn(T.decline || 'Decline', ICON_X, 'ghost', function () { onDecline(inv); }),
        ]);
    }

    function renderInbox(invitations) {
        var root = document.getElementById('memberships-inbox');
        clearNode(root);
        setCount('memberships-inbox-count', invitations.length);
        if (!invitations.length) {
            root.appendChild(_renderEmpty(T.inboxEmpty || 'No pending invitations.'));
            return;
        }
        invitations.forEach(function (inv) { root.appendChild(_renderInviteRow(inv)); });
    }

    function onAccept(inv) {
        api('acceptInvitation', 'POST', { project: inv.project }).then(function (res) {
            if (res && res.ok) {
                toast(T.acceptedMsg || 'Invitation accepted', 'success');
            } else {
                // 409 invitation.void and friends: surface the server's own words.
                toast(serverMessage(res), res && res.status === 409 ? 'warning' : 'error');
            }
            refreshAll();
            notifyMembershipChange();
        });
    }

    function onDecline(inv) {
        _renderConfirmModal(
            T.decline || 'Decline',
            [el('p', { text: (inv.project_name || inv.project) + ' — ' + String(inv.role || '') })],
            T.decline || 'Decline', false,
            function (close) {
                api('declineInvitation', 'POST', { project: inv.project }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.declinedMsg || 'Invitation declined', 'success');
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                    refreshAll();
                    notifyMembershipChange();
                });
            }
        );
    }

    // ============================================================
    // My join requests
    // ============================================================

    function _renderRequestRow(req) {
        var main = [
            el('span', { class: 'members-row__title' }, [el('strong', { text: req.project_name || req.project })]),
            _renderMeta([req.at || null]),
        ];
        if (req.note) main.push(_renderNote(req.note));
        return _renderRow(main, [
            _renderActionBtn(T.withdraw || 'Withdraw', ICON_X, 'ghost', function () { onWithdraw(req.project, null, T.withdrawnMsg); }),
        ]);
    }

    function renderRequests(requests) {
        var root = document.getElementById('memberships-requests');
        clearNode(root);
        setCount('memberships-requests-count', requests.length);
        if (!requests.length) {
            root.appendChild(_renderEmpty(T.requestsEmpty || 'No pending join requests.'));
            return;
        }
        requests.forEach(function (r) { root.appendChild(_renderRequestRow(r)); });
    }

    function onWithdraw(project, userId, doneMsg) {
        var body = { project: project };
        if (userId) body.user_id = userId;
        api('withdrawJoinRequest', 'POST', body).then(function (res) {
            if (res && res.ok) {
                toast(doneMsg || T.withdrawnMsg || 'Withdrawn', 'success');
            } else {
                toast(serverMessage(res), 'error');
            }
            refreshAll();
            notifyMembershipChange();
        });
    }

    // ============================================================
    // My proposals (sponsor lane)
    // ============================================================

    function _renderProposalRow(pr) {
        var statusText = pr.status === 'awaiting_answer'
            ? (T.awaitingAnswer || 'invitation sent — awaiting their answer')
            : (T.pendingValidation || 'awaiting validation');
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: (pr.user && (pr.user.name || pr.user.user_id)) || '?' }),
                el('span', { class: 'members-row__subtle', text: ' ' + (T.proposalFor || 'for') + ' ' + (pr.project_name || pr.project) }),
            ]),
            el('span', { class: 'members-row__chips' }, [
                _renderRoleChip(pr.role),
                el('span', { class: 'members-chip members-chip--status', text: statusText }),
            ]),
            _renderMeta([pr.at || null]),
        ];
        if (pr.note) main.push(_renderNote(pr.note));
        var actions = [];
        if (pr.status === 'pending_validation') {
            actions.push(_renderActionBtn(T.withdraw || 'Withdraw', ICON_X, 'ghost', function () {
                onWithdraw(pr.project, pr.user ? pr.user.user_id : null, T.proposalWithdrawnMsg);
            }));
        }
        return _renderRow(main, actions);
    }

    function renderProposals(proposals) {
        var root = document.getElementById('memberships-proposals');
        clearNode(root);
        setCount('memberships-proposals-count', proposals.length);
        if (!proposals.length) {
            root.appendChild(_renderEmpty(T.proposalsEmpty || 'No outgoing proposals.'));
            return;
        }
        proposals.forEach(function (p) { root.appendChild(_renderProposalRow(p)); });
    }

    // ============================================================
    // Notices
    // ============================================================

    function _renderNoticeRow(n) {
        var statusLabel = n.status === 'refused' ? (T.statusRefused || 'Refused')
            : n.status === 'removed' ? (T.statusRemoved || 'Removed')
            : (T.statusDeleted || 'Deleted');
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('span', { class: 'members-chip members-chip--' + n.status, text: statusLabel }),
                el('strong', { class: 'members-row__notice-name', text: n.project_name || n.project }),
            ]),
            _renderMeta([n.at || null]),
        ];
        if (n.note) {
            main.push(el('span', { class: 'members-row__subtle', text: (T.reason || 'Reason') + ':' }));
            main.push(_renderNote(n.note));
        }
        return _renderRow(main, [
            _renderActionBtn(T.dismiss || 'Dismiss', ICON_CHECK, 'ghost', function () { onDismiss(n.project); }),
        ]);
    }

    function renderNotices(notices) {
        var root = document.getElementById('memberships-notices');
        clearNode(root);
        setCount('memberships-notices-count', notices.length);
        if (!notices.length) {
            root.appendChild(_renderEmpty(T.noticesEmpty || 'No notices.'));
            return;
        }
        notices.forEach(function (n) { root.appendChild(_renderNoticeRow(n)); });
    }

    function onDismiss(project) {
        api('dismissProjectNotice', 'POST', { project: project }).then(function (res) {
            if (res && res.ok) {
                toast(T.dismissedMsg || 'Notice dismissed', 'success');
            } else {
                toast(serverMessage(res), 'error');
            }
            refreshAll();
            notifyMembershipChange();
        });
    }

    // ============================================================
    // Request to join
    // ============================================================

    var REQUEST_ERROR_KEYS = {
        'join.unavailable': 'errUnavailable',
        'join.requests_closed': 'errClosed',
        'member.already_exists': 'errAlreadyMember',
        'invitation.already_pending': 'errAlreadyInvited',
        'request.already_pending': 'errAlreadyRequested',
        'request.notice_pending': 'errNoticePending',
    };

    function onSendJoinRequest() {
        var projectInput = document.getElementById('request-project-id');
        var noteInput = document.getElementById('request-note');
        var btn = document.getElementById('btn-send-join-request');
        var project = projectInput.value.trim();
        var note = noteInput.value.trim();
        if (!project) { projectInput.focus(); return; }
        if (!note) { toast(T.noteRequired || 'The note is required.', 'warning'); noteInput.focus(); return; }

        btn.disabled = true;
        api('requestToJoin', 'POST', { project: project, note: note }).then(function (res) {
            btn.disabled = false;
            if (res && res.ok) {
                toast(T.requestSentMsg || 'Join request sent', 'success');
                projectInput.value = '';
                noteInput.value = '';
                refreshAll();
                notifyMembershipChange();
                return;
            }
            var code = res && res.data && res.data.code;
            var key = code && REQUEST_ERROR_KEYS[code];
            toast((key && T[key]) || serverMessage(res), 'warning');
        });
    }

    // ============================================================
    // Load + init
    // ============================================================

    function refreshAll() {
        api('listProjects', 'GET').then(function (res) {
            renderProjects((res && res.ok && res.data && res.data.data && res.data.data.projects) || []);
        });
        api('listMyInvitations', 'GET').then(function (res) {
            var d = (res && res.ok && res.data && res.data.data) || {};
            renderInbox(d.invitations || []);
            renderRequests(d.requests || []);
            renderNotices(d.notices || []);
        });
        api('listMyProposals', 'GET').then(function (res) {
            renderProposals((res && res.ok && res.data && res.data.data && res.data.data.proposals) || []);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sendBtn = document.getElementById('btn-send-join-request');
        if (sendBtn) sendBtn.addEventListener('click', onSendJoinRequest);
        refreshAll();
    });
})();
