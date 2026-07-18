/**
 * Project Members page (C8 8.3c) — roster / queue / invite / propose /
 * join-policy / transfer for the EDITED project.
 *
 * Reads: getProjectRoster (every member rank) + listMembers (admin/owner —
 * the pending queue). Writes: inviteMember / cancelInvitation /
 * changeMemberRole / removeMember / approveJoinRequest / denyJoinRequest /
 * proposeMember / setJoinPolicy / transferOwnership via
 * QuickSiteAdmin.apiRequest (project-marker transport). Client-side rank
 * gating mirrors canManageRole (strictly-below) for honest buttons only —
 * the server re-checks every rule in-lock.
 *
 * Built with QSDom.el + named _render* helpers (one Element each) per the
 * CLAUDE.md HTML-in-JS hygiene rule. No innerHTML string-glueing.
 * Static structure lives in templates/pages/members.php.
 */
(function () {
    'use strict';

    var CFG = window.QS_MEMBERS_CONFIG || {};
    var T = window.QS_MEMBERS_I18N || {};
    var el = window.QSDom.el;
    var svgIcon = window.QSDom.svgIcon;
    var clearNode = window.QSDom.clear;

    var ICON_CHECK = 'M20 6L9 17l-5-5';
    var ICON_X = 'M18 6L6 18M6 6l12 12';
    var ICON_EDIT = 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z';
    var ICON_TRASH = 'M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2';

    var state = {
        roster: [],
        queue: [],
        joinPolicy: CFG.joinPolicy || null,     // emitted server-side for admin+
        visibility: CFG.visibility || null,
    };

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

    function rankOf(role) {
        return (CFG.roleRanks && CFG.roleRanks[role]) || 0;
    }

    // canManageRole mirror: strictly below my rank (and never the owner role).
    function iOutrank(role) {
        var r = rankOf(role);
        return r > 0 && (CFG.myRank || 0) > r;
    }

    // Roles I may offer/assign: strictly below my own rank.
    function assignableRoles() {
        var out = [];
        for (var name in (CFG.roleRanks || {})) {
            if (iOutrank(name)) out.push(name);
        }
        out.sort(function (a, b) { return rankOf(b) - rankOf(a); });
        return out;
    }

    // Roles a proposal may suggest: below owner AND no higher than MY own rank
    // (C8 8.3c — a member vouches at most for a peer; the validator re-gates at
    // approve). `<= myRank` also lets a viewer propose a viewer.
    function proposableRoles() {
        var out = [];
        for (var name in (CFG.roleRanks || {})) {
            var r = rankOf(name);
            if (r > 0 && r < 6 && r <= (CFG.myRank || 0)) out.push(name);
        }
        out.sort(function (a, b) { return rankOf(b) - rankOf(a); });
        return out;
    }

    // Every role below owner (rank 1–5) — the departing-owner role choices for
    // transferOwnership (independent of the sponsor cap above).
    function rolesBelowOwner() {
        var out = [];
        for (var name in (CFG.roleRanks || {})) {
            var r = rankOf(name);
            if (r > 0 && r < 6) out.push(name);
        }
        out.sort(function (a, b) { return rankOf(b) - rankOf(a); });
        return out;
    }

    // ============================================================
    // Shared pieces
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
            wrap.appendChild(document.createTextNode(String(p)));
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

    function _renderModal(title, bodyNodes, confirmLabel, danger, onConfirm) {
        var root = document.getElementById('members-modal-root');
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

    function _renderRoleSelect(id, roles) {
        var sel = el('select', { class: 'admin-input admin-input--select', id: id });
        roles.forEach(function (r) {
            sel.appendChild(el('option', { value: r, text: r }));
        });
        return sel;
    }

    function fillRoleSelect(selectEl, roles) {
        if (!selectEl) return;
        clearNode(selectEl);
        roles.forEach(function (r) {
            selectEl.appendChild(el('option', { value: r, text: r }));
        });
    }

    function setCount(chipId, n) {
        var chip = document.getElementById(chipId);
        if (!chip) return;
        chip.textContent = String(n);
        chip.hidden = n === 0;
    }

    // ============================================================
    // Roster
    // ============================================================

    function _renderMemberRow(m) {
        var isSelf = m.user_id === CFG.myUserId;
        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: m.name || '?' }),
                isSelf ? el('span', { class: 'members-chip members-chip--you', text: T.you || 'you' }) : null,
            ]),
            el('span', { class: 'members-row__chips' }, [_renderRoleChip(m.role)]),
            el('code', { class: 'members-row__id', text: m.user_id }),
        ];
        var actions = [];
        if (!isSelf && !m.is_owner && iOutrank(m.role)) {
            actions.push(_renderActionBtn(T.changeRole || 'Change role', ICON_EDIT, 'ghost', function () { openChangeRoleModal(m); }));
            actions.push(_renderActionBtn(T.remove || 'Remove', ICON_TRASH, 'danger', function () { openRemoveModal(m); }));
        }
        return _renderRow(main, actions);
    }

    function renderRoster() {
        var root = document.getElementById('members-roster');
        if (!root) return;
        clearNode(root);
        setCount('members-roster-count', state.roster.length);
        if (!state.roster.length) {
            root.appendChild(_renderEmpty(T.rosterEmpty || 'No members.'));
            return;
        }
        state.roster.forEach(function (m) { root.appendChild(_renderMemberRow(m)); });
    }

    function openChangeRoleModal(m) {
        var sel = _renderRoleSelect('change-role-select', assignableRoles());
        sel.value = iOutrank(m.role) && rankOf(m.role) > 0 ? m.role : sel.value;
        _renderModal(
            (T.changeRoleTitle || 'Change role') + ' — ' + (m.name || m.user_id),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'change-role-select', text: T.newRoleLabel || 'New role' }),
                    sel,
                ]),
            ],
            T.apply || 'Apply', false,
            function (close) {
                api('changeMemberRole', 'POST', { user_id: m.user_id, role: sel.value }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.roleChangedMsg || 'Role updated', 'success');
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                    refreshLists();
                });
            }
        );
    }

    function openRemoveModal(m) {
        var noteInput = el('input', { type: 'text', class: 'admin-input', id: 'remove-note-input', maxlength: '500' });
        _renderModal(
            (T.removeTitle || 'Remove member') + ' — ' + (m.name || m.user_id),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'remove-note-input', text: T.removeNoteLabel || 'Reason (optional)' }),
                    noteInput,
                ]),
            ],
            T.remove || 'Remove', true,
            function (close) {
                var body = { user_id: m.user_id };
                var note = noteInput.value.trim();
                if (note) body.note = note;
                api('removeMember', 'POST', body).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.removedMsg || 'Member removed', 'success');
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                    refreshLists();
                    notifyMembershipChange();
                });
            }
        );
    }

    // ============================================================
    // Pending queue (admin/owner)
    // ============================================================

    function _renderQueueRow(q) {
        var isInvite = q.direction === 'invite';
        var isSelfRequest = !isInvite && q.invited_by && q.invited_by.user_id === q.user_id;
        var kindChip = isInvite
            ? el('span', { class: 'members-chip members-chip--invite', text: T.chipInvite || 'invitation' })
            : (isSelfRequest
                ? el('span', { class: 'members-chip members-chip--request', text: T.chipRequest || 'join request' })
                : el('span', { class: 'members-chip members-chip--proposal', text: T.chipProposal || 'proposal' }));

        var byLabel = isInvite ? (T.invitedBy || 'invited by') : (isSelfRequest ? (T.askedBy || 'asked by') : (T.proposedBy || 'proposed by'));
        var byName = q.invited_by ? (q.invited_by.name || q.invited_by.user_id) : null;

        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: q.name || q.user_id }),
                kindChip,
            ]),
            el('span', { class: 'members-row__chips' }, [_renderRoleChip(q.role)]),
            _renderMeta([
                byName ? byLabel + ' ' + byName : null,
                q.sponsored_by ? (T.proposedBy || 'proposed by') + ' ' + (q.sponsored_by.name || q.sponsored_by.user_id) : null,
                q.at || null,
            ]),
            el('code', { class: 'members-row__id', text: q.user_id }),
        ];
        if (q.note) main.push(_renderNote(q.note));

        var actions = [];
        if (iOutrank(q.role)) {
            if (isInvite) {
                actions.push(_renderActionBtn(T.cancelInvite || 'Cancel', ICON_X, 'ghost', function () { openCancelInviteModal(q); }));
            } else {
                actions.push(_renderActionBtn(T.approve || 'Approve', ICON_CHECK, 'primary', function () { openApproveModal(q); }));
                actions.push(_renderActionBtn(T.deny || 'Deny', ICON_X, 'danger', function () { openDenyModal(q); }));
            }
        }
        return _renderRow(main, actions);
    }

    function renderQueue() {
        var root = document.getElementById('members-queue');
        if (!root) return;
        clearNode(root);
        setCount('members-queue-count', state.queue.length);
        if (!state.queue.length) {
            root.appendChild(_renderEmpty(T.queueEmpty || 'Nothing pending.'));
            return;
        }
        state.queue.forEach(function (q) { root.appendChild(_renderQueueRow(q)); });
    }

    function openApproveModal(q) {
        // Role-at-approve (C8 8.3c): grant straight to a chosen role instead of
        // approve-then-changeMemberRole. Options = strictly below my rank; the
        // default is the requested/proposed role (which the approve button
        // already required me to outrank), so leaving it untouched = the old
        // behaviour. The server re-checks the rank in-lock against the granted role.
        var roles = assignableRoles();
        var roleSelect = _renderRoleSelect('approve-role-select', roles);
        if (roles.indexOf(q.role) !== -1) roleSelect.value = q.role;

        var body = [
            el('p', {}, [el('strong', { text: q.name || q.user_id })]),
            el('div', { class: 'admin-form-group' }, [
                el('label', { class: 'admin-label', for: 'approve-role-select', text: T.approveRoleLabel || 'Role to grant' }),
                roleSelect,
            ]),
        ];
        if (q.note) body.push(_renderNote(q.note));
        _renderModal(
            T.approveTitle || 'Approve this request?', body,
            T.approve || 'Approve', false,
            function (close) {
                api('approveJoinRequest', 'POST', { user_id: q.user_id, role: roleSelect.value }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        var converted = res.data && res.data.data && res.data.data.converted_to_invitation;
                        toast(converted ? (T.convertedMsg || 'Invitation sent') : (T.approvedMsg || 'Approved'), 'success');
                    } else {
                        toast(serverMessage(res), res && res.status === 409 ? 'warning' : 'error');
                    }
                    refreshLists();
                    notifyMembershipChange();
                });
            }
        );
    }

    function openDenyModal(q) {
        var noteInput = el('textarea', { class: 'admin-input', id: 'deny-note-input', rows: '3', maxlength: '500' });
        _renderModal(
            (T.denyTitle || 'Deny this request') + ' — ' + (q.name || q.user_id),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'deny-note-input', text: T.denyNoteLabel || 'Reason (required)' }),
                    noteInput,
                ]),
            ],
            T.deny || 'Deny', true,
            function (close, btn) {
                var note = noteInput.value.trim();
                if (!note) {
                    btn.disabled = false;
                    toast(T.denyNoteRequired || 'The refusal reason is required.', 'warning');
                    noteInput.focus();
                    return;
                }
                api('denyJoinRequest', 'POST', { user_id: q.user_id, note: note }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.deniedMsg || 'Request denied', 'success');
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                    refreshLists();
                    notifyMembershipChange();
                });
            }
        );
    }

    function openCancelInviteModal(q) {
        _renderModal(
            T.cancelTitle || 'Cancel this invitation?',
            [el('p', {}, [el('strong', { text: q.name || q.user_id }), ' — ', _renderRoleChip(q.role)])],
            T.cancelInvite || 'Cancel', true,
            function (close) {
                api('cancelInvitation', 'POST', { user_id: q.user_id }).then(function (res) {
                    close();
                    if (res && res.ok) {
                        toast(T.cancelledMsg || 'Invitation cancelled', 'success');
                    } else {
                        toast(serverMessage(res), 'error');
                    }
                    refreshLists();
                    notifyMembershipChange();
                });
            }
        );
    }

    // ============================================================
    // findUser picker (shared by invite + propose forms)
    // ============================================================

    function _renderMatchRow(match, onPick) {
        return el('div', { class: 'members-match-row' }, [
            el('span', { class: 'members-row__title' }, [el('strong', { text: match.name })]),
            el('code', { class: 'members-row__id', text: match.user_id }),
            _renderActionBtn(T.select || 'Select', ICON_CHECK, 'ghost', function () { onPick(match); }),
        ]);
    }

    function _renderPickedRow(match) {
        return el('div', { class: 'members-match-row members-match-row--picked' }, [
            el('span', { class: 'members-row__title' }, [
                el('span', { class: 'members-chip members-chip--picked', text: T.selected || 'Selected' }),
                el('strong', { text: match.name }),
            ]),
            el('code', { class: 'members-row__id', text: match.user_id }),
        ]);
    }

    /**
     * Wire one findUser flow. Returns { getPicked, reset }.
     * prefix: 'invite' | 'propose' (matches the template element ids).
     */
    function setupFindUser(prefix, sendBtn) {
        var nameInput = document.getElementById(prefix + '-find-name');
        var findBtn = document.getElementById('btn-' + prefix + '-find');
        var results = document.getElementById(prefix + '-find-results');
        var picked = null;

        if (!nameInput || !findBtn || !results) return { getPicked: function () { return null; }, reset: function () {} };

        function pick(match) {
            picked = match;
            clearNode(results);
            results.appendChild(_renderPickedRow(match));
            if (sendBtn) sendBtn.disabled = false;
        }

        function search() {
            var name = nameInput.value.trim();
            picked = null;
            if (sendBtn) sendBtn.disabled = true;
            if (!name) {
                toast(T.nameRequired || 'Type the exact public name first.', 'warning');
                nameInput.focus();
                return;
            }
            findBtn.disabled = true;
            api('findUser', 'POST', { name: name }).then(function (res) {
                findBtn.disabled = false;
                clearNode(results);
                var matches = (res && res.ok && res.data && res.data.data && res.data.data.matches) || [];
                if (!matches.length) {
                    results.appendChild(_renderEmpty(T.searchNoMatch || 'No account with this exact public name.'));
                    return;
                }
                if (matches.length === 1) {
                    pick(matches[0]);
                    return;
                }
                matches.forEach(function (m) { results.appendChild(_renderMatchRow(m, pick)); });
            });
        }

        findBtn.addEventListener('click', search);
        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); search(); }
        });

        return {
            getPicked: function () { return picked; },
            reset: function () {
                picked = null;
                nameInput.value = '';
                clearNode(results);
                if (sendBtn) sendBtn.disabled = true;
            },
        };
    }

    // ============================================================
    // Invite + propose submits
    // ============================================================

    function setupInviteForm() {
        var sendBtn = document.getElementById('btn-send-invite');
        if (!sendBtn) return;
        var finder = setupFindUser('invite', sendBtn);
        fillRoleSelect(document.getElementById('invite-role'), assignableRoles());

        sendBtn.addEventListener('click', function () {
            var picked = finder.getPicked();
            if (!picked) { toast(T.pickRequired || 'Search and select a person first.', 'warning'); return; }
            var role = document.getElementById('invite-role').value;
            var note = document.getElementById('invite-note').value.trim();
            var body = { user_id: picked.user_id, role: role };
            if (note) body.note = note;
            sendBtn.disabled = true;
            api('inviteMember', 'POST', body).then(function (res) {
                if (res && res.ok) {
                    toast(T.inviteSentMsg || 'Invitation sent', 'success');
                    finder.reset();
                    document.getElementById('invite-note').value = '';
                    refreshLists();
                    notifyMembershipChange();
                } else {
                    sendBtn.disabled = false;
                    toast(serverMessage(res), res && res.status === 409 ? 'warning' : 'error');
                }
            });
        });
    }

    function setupProposeForm() {
        var sendBtn = document.getElementById('btn-send-propose');
        if (!sendBtn) return;
        var finder = setupFindUser('propose', sendBtn);
        fillRoleSelect(document.getElementById('propose-role'), proposableRoles());

        sendBtn.addEventListener('click', function () {
            var picked = finder.getPicked();
            if (!picked) { toast(T.pickRequired || 'Search and select a person first.', 'warning'); return; }
            var note = document.getElementById('propose-note').value.trim();
            if (!note) {
                toast(T.vouchRequired || 'The vouch note is required.', 'warning');
                document.getElementById('propose-note').focus();
                return;
            }
            var role = document.getElementById('propose-role').value;
            sendBtn.disabled = true;
            api('proposeMember', 'POST', { user_id: picked.user_id, role: role, note: note }).then(function (res) {
                if (res && res.ok) {
                    toast(T.proposeSentMsg || 'Proposal recorded', 'success');
                    finder.reset();
                    document.getElementById('propose-note').value = '';
                    refreshLists();
                    notifyMembershipChange();
                } else {
                    sendBtn.disabled = false;
                    toast(serverMessage(res), res && res.status === 409 ? 'warning' : 'error');
                }
            });
        });
    }

    // ============================================================
    // Join policy (admin/owner)
    // ============================================================

    function renderPolicy() {
        var valueEl = document.getElementById('members-policy-value');
        var toggleBtn = document.getElementById('btn-toggle-policy');
        var advisory = document.getElementById('members-policy-advisory');
        var advisoryText = document.getElementById('members-policy-advisory-text');
        if (!valueEl || !toggleBtn) return;

        var policy = state.joinPolicy || 'closed';
        valueEl.textContent = policy === 'open' ? (T.policyOpen || 'open') : (T.policyClosed || 'closed');
        valueEl.className = 'members-policy-value members-policy-value--' + policy;
        toggleBtn.disabled = false;

        if (advisory && advisoryText) {
            var showAdvisory = policy === 'open' && state.visibility === 'private';
            advisory.hidden = !showAdvisory;
            if (showAdvisory) advisoryText.textContent = T.policyAdvisory || '';
        }
    }

    function applyPolicy(target) {
        var toggleBtn = document.getElementById('btn-toggle-policy');
        if (toggleBtn) toggleBtn.disabled = true;
        api('setJoinPolicy', 'POST', { policy: target }).then(function (res) {
            if (res && res.ok) {
                state.joinPolicy = (res.data && res.data.data && res.data.data.join_policy) || target;
                toast(T.policySavedMsg || 'Join policy updated', 'success');
                // The server attaches an advisory note when private+open became
                // active — surface its exact wording too.
                var note = res.data && res.data.data && res.data.data.note;
                if (note) toast(note, 'warning');
            } else {
                toast(serverMessage(res), 'error');
            }
            renderPolicy();
        });
    }

    function setupPolicyToggle() {
        var toggleBtn = document.getElementById('btn-toggle-policy');
        if (!toggleBtn) return;
        toggleBtn.addEventListener('click', function () {
            var target = (state.joinPolicy || 'closed') === 'open' ? 'closed' : 'open';
            if (target === 'open' && state.visibility === 'private') {
                _renderModal(
                    T.policyTitle || (T.policyOpen || 'open'),
                    [el('p', { text: T.policyConfirmOpenPrivate || 'Open the join policy on a private project?' })],
                    T.policyOpen || 'open', true,
                    function (close) {
                        close();
                        applyPolicy(target);
                    }
                );
                return;
            }
            applyPolicy(target);
        });
        renderPolicy();
    }

    // ============================================================
    // Transfer ownership (owner)
    // ============================================================

    function fillTransferSelectors() {
        var memberSel = document.getElementById('transfer-member');
        var roleSel = document.getElementById('transfer-old-role');
        if (!memberSel || !roleSel) return;

        clearNode(memberSel);
        state.roster.forEach(function (m) {
            if (m.user_id === CFG.myUserId || m.is_owner) return;
            memberSel.appendChild(el('option', { value: m.user_id, text: (m.name || m.user_id) + ' (' + (m.role || '?') + ')' }));
        });
        fillRoleSelect(roleSel, rolesBelowOwner());
        roleSel.value = 'admin';
    }

    function setupTransfer() {
        var btn = document.getElementById('btn-transfer-ownership');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var memberSel = document.getElementById('transfer-member');
            var roleSel = document.getElementById('transfer-old-role');
            var confirmChk = document.getElementById('transfer-confirm');
            var userId = memberSel && memberSel.value;
            if (!userId) { toast(T.transferMemberRequired || 'Pick the new owner first.', 'warning'); return; }
            if (!confirmChk || !confirmChk.checked) { toast(T.transferConfirmRequired || 'Tick the confirmation first.', 'warning'); return; }

            var picked = null;
            state.roster.forEach(function (m) { if (m.user_id === userId) picked = m; });
            var bodyText = (T.transferBody || 'The project is handed to {name}. Your role becomes {role}.')
                .replace('{name}', (picked && picked.name) || userId)
                .replace('{role}', roleSel.value);

            _renderModal(
                T.transferTitle || 'Transfer ownership — final confirmation',
                [el('p', { text: bodyText })],
                T.transferBtn || 'Transfer ownership', true,
                function (close) {
                    api('transferOwnership', 'POST', { user_id: userId, confirm: true, old_owner_role: roleSel.value }).then(function (res) {
                        close();
                        if (res && res.ok) {
                            toast(T.transferredMsg || 'Ownership transferred', 'success');
                            // Roles changed radically (owner → old_owner_role):
                            // reload so the server re-renders the page for the
                            // new reality (sections, banner, pickers). The reload
                            // recomputes the nav badge on its own — do NOT fire
                            // notifyMembershipChange() here, or its in-flight
                            // count fetches get cancelled by the navigation and
                            // log a spurious NetworkError.
                            window.location.href = window.location.pathname + '?t=' + Date.now();
                        } else {
                            toast(serverMessage(res), 'error');
                        }
                    });
                }
            );
        });
    }

    // ============================================================
    // Load + init
    // ============================================================

    function refreshLists() {
        api('getProjectRoster', 'GET').then(function (res) {
            state.roster = (res && res.ok && res.data && res.data.data && res.data.data.members) || [];
            renderRoster();
            fillTransferSelectors();
        });
        if (document.getElementById('members-queue')) {
            api('listMembers', 'GET').then(function (res) {
                var d = (res && res.ok && res.data && res.data.data) || {};
                state.queue = d.invitations || [];
                if (d.visibility) state.visibility = d.visibility;
                renderQueue();
                renderPolicy();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupInviteForm();
        setupProposeForm();
        setupPolicyToggle();
        setupTransfer();
        refreshLists();
    });
})();
