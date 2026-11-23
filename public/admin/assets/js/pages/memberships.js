/**
 * My Memberships page — the caller's own membership surface.
 *
 * Calls the listProjects command, plus the account endpoint for the inbox, the
 * proposals list and the self-service verbs (accept/decline/leave/withdraw/
 * dismiss/request-to-join). Only the first is a command: the
 * command surface is a CLI for DEVELOPING a project, and getting into or out of
 * one is an operation on your account's access, served by /admin/self. Both
 * doors answer any authenticated caller, so a 0-membership account gets a clean
 * page, never 403 noise. Switching which project you EDIT is a third thing
 * again (panel state — QuickSiteAdmin.setSelectedProject).
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
    var ICON_OUT = 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9';
    var ICON_BUILD = 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z';

    function api(cmd, method, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.apiRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.apiRequest(cmd, method, body);
    }

    // Membership self-service is NOT a command surface: joining a
    // project, leaving one, and answering an invitation are operations on your
    // ACCOUNT's access, so they go to /admin/self. Same {ok, status, data}
    // shape as api(), so every caller below is unchanged apart from the door.
    function account(route, method, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.accountRequest !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.accountRequest(route, method, body);
    }

    // Panel state, not a command — same {ok, status, data} shape as api().
    function selectProject(project) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.setSelectedProject !== 'function') {
            return Promise.reject(new Error('QuickSiteAdmin not available'));
        }
        return admin.setSelectedProject(project);
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
                wrap.appendChild(el('span', { text: ' · ' }));
            }
            if (typeof p === 'string') wrap.appendChild(document.createTextNode(p));
            else wrap.appendChild(p);
        });
        return wrap;
    }

    /** A row's action with its word: kind 'primary', 'ghost', or 'danger' (a ghost red on hover, as the bin is: Leave, Decline). */
    function _renderActionBtn(label, iconD, kind, onClick) {
        var b = el('button', {
            class: 'admin-btn ' + (kind === 'danger' ? 'admin-btn--ghost admin-action--delete' : (kind === 'primary' ? 'admin-btn--primary' : 'admin-btn--ghost')),
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

    /**
     * The zero-membership empty state.
     *
     * Three ways out of an account that belongs to nothing: accept an
     * invitation (the inbox below), ask to join (the form below), or make your
     * own project - the only one that needs nobody else's cooperation, and the
     * one the page never offered. createProject is scope global / access any,
     * so every authenticated caller may take it.
     *
     * It LINKS to the dashboard rather than repeating the create modal here: a
     * second copy of that form would be a second source of truth.
     *
     * @returns {HTMLElement} ONE .admin-empty
     */
    function _renderProjectsEmpty() {
        var children = [el('p', { text: T.projectsEmpty || 'No projects.' })];
        if (CFG.createUrl) {
            children.push(el('a', {
                class: 'admin-btn admin-btn--primary members-empty__cta',
                href: CFG.createUrl,
                text: T.projectsEmptyCreate || 'memberships.myProjects.createCta'
            }));
        }
        return el('div', { class: 'admin-empty members-empty' }, children);
    }

    function _renderRow(mainChildren, actionButtons) {
        var main = el('div', { class: 'members-row__main' }, mainChildren);
        var actions = el('div', { class: 'members-row__actions' }, actionButtons);
        return el('div', { class: 'members-row' }, [main, actions]);
    }

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

    /** A command's answer as a question's action wants it: true, or the server's words kept in the question. */
    function answered(res) {
        return res && res.ok ? true : { ok: false, message: serverMessage(res) };
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

    /**
     * "Name #id" (QuickSiteAdmin.projectLabel). The server sends a row's name
     * only when it is the caller's to see: a private project they only asked to
     * join comes with its id as its name, and shows as its id alone.
     */
    function projectLabel(id, name) {
        return window.QuickSiteAdmin.projectLabel(id, name);
    }

    /** A row's star toggle: the panel's (QuickSiteAdmin.syncStarToggle / bindStarToggle). */
    function _renderStarToggle(projectId) {
        var star = el('button', { class: 'admin-btn admin-btn--ghost admin-project-star', type: 'button' });
        window.QuickSiteAdmin.syncStarToggle(star, projectId);
        window.QuickSiteAdmin.bindStarToggle(star);
        return star;
    }

    function _renderProjectRow(p) {
        var isOwned = p.my_role === 'owner';
        var isEditing = p.project === CFG.editedProject;

        var title = el('span', { class: 'members-row__title' }, [
            _renderStarToggle(p.project),
            el('strong', { text: projectLabel(p.project, p.name) }),
        ]);
        var chips = el('span', { class: 'members-row__chips' }, [
            _renderRoleChip(p.my_role),
            isOwned ? el('span', { class: 'members-chip members-chip--owned', text: T.ownedChip || 'OWNED' }) : null,
            isEditing ? el('span', { class: 'members-chip members-chip--editing', text: T.editingChip || 'editing' }) : null,
        ]);

        var actions = [];
        if (!isEditing) {
            actions.push(QSControls.edit(T.editBtn || 'Edit this project', function (e) {
                onEditProject(p.project, e.currentTarget);
            }, { focusKey: 'project-edit:' + p.project }));
        }
        // Per-project route to the builds page. Gated on THIS project's role
        // against the server-derived set of roles that hold getBuild — the
        // panel-wide permission set answers for the EDITED project only, so it
        // would gate every row by the wrong project's role.
        if ((CFG.buildRoles || []).indexOf(p.my_role) !== -1) {
            actions.push(_renderActionBtn(T.buildsBtn || 'Builds', ICON_BUILD, 'ghost', function () {
                onOpenBuilds(p.project, isEditing, this);
            }));
        }
        if (!isOwned) {
            actions.push(_renderActionBtn(T.leaveBtn || 'Leave', ICON_OUT, 'danger', function () {
                onLeaveProject(p.project, p.name);
            }));
        }
        return _renderRow([title, chips], actions);
    }

    // The last list drawn, so a star set anywhere on the page re-orders the rows.
    var shownProjects = [];

    /** My projects, starred ones first (listProjects answers them by name). */
    function renderProjects(projects) {
        shownProjects = projects;
        var root = document.getElementById('memberships-projects');
        clearNode(root);
        if (!projects.length) {
            root.appendChild(_renderProjectsEmpty());
            return;
        }
        window.QuickSiteAdmin.starredFirst(projects, function (p) { return p.project; })
            .forEach(function (p) { root.appendChild(_renderProjectRow(p)); });
    }
    document.addEventListener('quicksite:stars-changed', function () {
        if (shownProjects.length) renderProjects(shownProjects);
    });

    function onEditProject(project, btn) {
        if (btn) btn.disabled = true;
        selectProject(project).then(function (res) {
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

    /**
     * Open the builds page FOR THIS ROW'S PROJECT.
     *
     * The builds page acts on the edited project, so a row that is not the
     * edited one has to switch first — the same panel-state write the Edit
     * button uses. Switching only to land on a page the user did not ask for would be
     * a surprise, which is why the navigation goes straight to builds rather
     * than reloading this page.
     */
    function onOpenBuilds(project, isEditing, btn) {
        var url = CFG.buildsUrl || 'builds';
        if (isEditing) {
            window.location.href = url;
            return;
        }
        if (btn) btn.disabled = true;
        selectProject(project).then(function (res) {
            if (res && res.ok) {
                window.location.href = url;
            } else {
                if (btn) btn.disabled = false;
                toast(serverMessage(res), 'error');
            }
        });
    }

    function onLeaveProject(project, name) {
        ask(
            T.leaveTitle || 'Leave project',
            [el('p', { text: (T.leaveConfirm || 'Leave {project}?').replace('{project}', projectLabel(project, name)) })],
            T.leaveBtn || 'Leave', true,
            async function () {
                var res = await account('leave-project', 'POST', { project: project });
                if (!res || !res.ok) return answered(res);
                toast(T.leftMsg || 'You left the project', 'success');
                // Leaving changes the caller's SERVER-computed permission set
                // (nav links, PAGE_PERMISSIONS, the project picker) — a soft
                // refreshAll() leaves stale tabs that only correct themselves on
                // the next click. Reload instead; no notifyMembershipChange first
                // (its fetches would be cancelled by the reload).
                window.location.href = window.location.pathname + '?t=' + Date.now();
                return true;
            }
        );
    }

    // ============================================================
    // Invitation inbox
    // ============================================================

    function _renderInviteRow(inv) {
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: projectLabel(inv.project, inv.project_name) }),
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
            _renderActionBtn(T.decline || 'Decline', null, 'danger', function () { onDecline(inv); }),
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
        account('accept-invitation', 'POST', { project: inv.project }).then(function (res) {
            if (res && res.ok) {
                openAcceptedProject(inv);
                return;
            }
            // 409 invitation.void and friends: surface the server's own words.
            toast(serverMessage(res), res && res.status === 409 ? 'warning' : 'error');
            refreshAll();
            notifyMembershipChange();
        });
    }

    /**
     * An accepted invitation opens its project: whoever just said yes to a project expects
     * to be on it, and every page acts on the edited project, so staying on the previous one
     * reads as working on the new one. The same selected-project write and cache-busted
     * navigation as Edit this project, with the message carried across the reload. Should
     * the write fail the membership still stands: the page stays where it is, and the new
     * row offers Edit this project.
     */
    function openAcceptedProject(inv) {
        function stayHere() {
            toast(T.acceptedMsg || 'Invitation accepted', 'success');
            refreshAll();
            notifyMembershipChange();
        }
        selectProject(inv.project).then(function (res) {
            if (!res || !res.ok) {
                stayHere();
                return;
            }
            var words = T.acceptedOpenedMsg || T.acceptedMsg || 'Invitation accepted';
            window.QuickSiteAdmin.setPendingMessage(words.replace('{project}', projectLabel(inv.project, inv.project_name)), 'success');
            window.location.href = window.location.pathname + '?t=' + Date.now();
        }, stayHere);
    }

    function onDecline(inv) {
        ask(
            T.declineTitle,
            [el('p', { text: projectLabel(inv.project, inv.project_name) + ' — ' + String(inv.role || '') })],
            T.decline || 'Decline', true,
            async function () {
                var res = await account('decline-invitation', 'POST', { project: inv.project });
                await refreshAll();
                notifyMembershipChange();
                if (!res || !res.ok) return answered(res);
                toast(T.declinedMsg || 'Invitation declined', 'success');
                return true;
            }
        );
    }

    // ============================================================
    // My join requests
    // ============================================================

    function _renderRequestRow(req) {
        var main = [
            el('span', { class: 'members-row__title' }, [el('strong', { text: projectLabel(req.project, req.project_name) })]),
            _renderMeta([req.at || null]),
        ];
        if (req.note) main.push(_renderNote(req.note));
        var label = String(T.withdrawRequestOf).split('{project}').join(projectLabel(req.project, req.project_name));
        return _renderRow(main, [
            QSControls.bin(label, function () {
                onWithdraw(req.project, null, T.withdrawnMsg,
                    String(T.withdrawRequestTitle).split('{project}').join(projectLabel(req.project, req.project_name)));
            }, { focusKey: 'request-withdraw:' + req.project }),
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

    /** Withdraw a request or a proposal, asked first (`question` names it): a refusal stays in the question. */
    function onWithdraw(project, userId, doneMsg, question) {
        var body = { project: project };
        if (userId) body.user_id = userId;
        return ask(question, [el('p', { text: T.withdrawText })], T.withdraw || 'Withdraw', true, async function () {
            var res = await account('withdraw-request', 'POST', body);
            await refreshAll();
            notifyMembershipChange();
            if (!res || !res.ok) return answered(res);
            toast(doneMsg || T.withdrawnMsg || 'Withdrawn', 'success');
            return true;
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
                el('span', { class: 'members-row__subtle', text: ' ' + (T.proposalFor || 'for') + ' ' + projectLabel(pr.project, pr.project_name) }),
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
            var who = (pr.user && (pr.user.name || pr.user.user_id)) || '?';
            var label = String(T.withdrawProposalOf).split('{name}').join(who)
                .split('{project}').join(projectLabel(pr.project, pr.project_name));
            actions.push(QSControls.bin(label, function () {
                onWithdraw(pr.project, pr.user ? pr.user.user_id : null, T.proposalWithdrawnMsg,
                    String(T.withdrawProposalTitle).split('{name}').join(who).split('{project}').join(projectLabel(pr.project, pr.project_name)));
            }, { focusKey: 'proposal-withdraw:' + pr.project + '/' + (pr.user ? pr.user.user_id : '') }));
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

    // A notice's chip: its words by status. `approved` (your own join request, accepted) is
    // the one that is good news; dismissing it, or `role_changed` (someone else changed your
    // role), leaves the membership as it is. A project can hold both at once: one row each.
    var NOTICE_LABEL_KEYS = {
        refused: 'statusRefused',
        removed: 'statusRemoved',
        deleted: 'statusDeleted',
        approved: 'statusApproved',
        role_changed: 'statusRoleChanged',
    };

    /** A role notice's two roles: "from <old> to <new>". @returns {HTMLElement} */
    function _renderRoleChange(n) {
        return el('span', { class: 'members-row__chips' }, [
            el('span', { class: 'members-row__subtle', text: T.roleFrom || 'from' }),
            _renderRoleChip(n.from),
            el('span', { class: 'members-row__subtle', text: T.roleTo || 'to' }),
            _renderRoleChip(n.to),
        ]);
    }

    function _renderNoticeRow(n) {
        var statusLabel = T[NOTICE_LABEL_KEYS[n.status] || 'statusDeleted'];
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('span', { class: 'members-chip members-chip--' + n.status, text: statusLabel }),
                el('strong', { class: 'members-row__notice-name', text: projectLabel(n.project, n.project_name) }),
            ]),
        ];
        if (n.status === 'role_changed') main.push(_renderRoleChange(n));
        main.push(_renderMeta([
            n.by ? (T.changedBy || 'Changed by') + ' ' + (n.by.name || n.by.user_id) : null,
            n.at || null,
        ]));
        if (n.note) {
            main.push(el('span', { class: 'members-row__subtle', text: (T.reason || 'Reason') + ':' }));
            main.push(_renderNote(n.note));
        }
        return _renderRow(main, [
            _renderActionBtn(T.dismiss || 'Dismiss', ICON_CHECK, 'ghost', function () { onDismiss(n.project, n.status); }),
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

    // The notice named: a project can hold two at once (a member entry's two marks).
    function onDismiss(project, notice) {
        account('dismiss-notice', 'POST', { project: project, notice: notice }).then(function (res) {
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
        'member.already_exists': 'errAlreadyMember',
        'invitation.already_pending': 'errAlreadyInvited',
        'request.already_pending': 'errAlreadyRequested',
        'request.notice_pending': 'errNoticePending',
    };

    function onSendJoinRequest() {
        var projectInput = document.getElementById('request-project-id');
        var noteInput = document.getElementById('request-note');
        var btn = document.getElementById('btn-send-join-request');
        // The id as shown after the name ("Name #id"): with or without its #.
        // The server strips it too.
        var project = projectInput.value.trim().replace(/^#\s*/, '');
        var note = noteInput.value.trim();
        if (!project) { projectInput.focus(); return; }
        if (!note) { toast(T.noteRequired || 'The note is required.', 'warning'); noteInput.focus(); return; }

        btn.disabled = true;
        account('request-to-join', 'POST', { project: project, note: note }).then(function (res) {
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

    /** Read every list again and draw it: a promise of all drawn. The focus, when it was in the page, goes to the control that took its place. */
    function refreshAll() {
        var main = document.querySelector('.admin-main');
        var focusBack = main && main.contains(document.activeElement) ? window.QSDom.rememberFocus() : null;
        return Promise.all([
            api('listProjects', 'GET').then(function (res) {
                renderProjects((res && res.ok && res.data && res.data.data && res.data.data.projects) || []);
            }),
            account('invitations', 'GET').then(function (res) {
                var d = (res && res.ok && res.data && res.data.data) || {};
                renderInbox(d.invitations || []);
                renderRequests(d.requests || []);
                renderNotices(d.notices || []);
            }),
            account('proposals', 'GET').then(function (res) {
                renderProposals((res && res.ok && res.data && res.data.data && res.data.data.proposals) || []);
            }),
        ]).catch(function () { /* a failed read leaves its list as it was */ }).then(function () {
            if (focusBack) focusBack.restore();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sendBtn = document.getElementById('btn-send-join-request');
        if (sendBtn) sendBtn.addEventListener('click', onSendJoinRequest);
        refreshAll();
    });
})();
