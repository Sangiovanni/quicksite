/**
 * Project Members page — roster / queue / invite / propose /
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
 * Static structure lives in templates/pages/members.php, which hands the words
 * this file reads (QSPanelWords, each by its key's whole path).
 */
(function () {
    'use strict';

    var CFG = window.QS_MEMBERS_CONFIG || {};
    var el = window.QSDom.el;
    var svgIcon = window.QSDom.svgIcon;
    var clearNode = window.QSDom.clear;

    var ICON_CHECK = 'M20 6L9 17l-5-5';

    var state = {
        roster: [],
        queue: [],
        contacts: [],                           // this account's own, /admin/self/contacts
        joinPolicy: CFG.joinPolicy || null,     // emitted server-side for admin+
    };

    // Every setupFindUser instance registers a redraw here, so one contacts
    // change repaints the invite form and the propose form together instead of
    // each holding its own stale copy.
    var contactRenderers = [];

    function api(cmd, method, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.apiRequest !== 'function') {
            return Promise.reject(new Error(QSPanelWords.t('common.adminUnavailable')));
        }
        return admin.apiRequest(cmd, method, body);
    }

    function toast(message, type) {
        if (window.QuickSiteUtils && typeof window.QuickSiteUtils.showToast === 'function') {
            window.QuickSiteUtils.showToast(message, type || 'info');
        }
    }

    /** The server's own words for a refusal, else `word` (a panel word the caller names), else the panel's Error. */
    function serverMessage(res, word) {
        return (res && res.data && (res.data.message || res.data.error)) || word || QSPanelWords.t('common.error');
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
    // (a member vouches at most for a peer; the validator re-gates at
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
        return el('span', { class: 'members-role-chip members-role-chip--' + String(role || ''), text: role ? QuickSiteUtils.roleName(role) : '?' });
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
            wrap.appendChild(document.createTextNode(String(p)));
        });
        return wrap;
    }

    /** A row's action with its word: kind 'primary', 'ghost', or 'danger' (a ghost red on hover, as the bin is: Deny). */
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

    function _renderRow(mainChildren, actionButtons) {
        var main = el('div', { class: 'members-row__main' }, mainChildren);
        var actions = el('div', { class: 'members-row__actions' }, actionButtons);
        return el('div', { class: 'members-row' }, [main, actions]);
    }

    /**
     * A question in the panel's one confirm (QuickSiteUtils.confirm): its title, what it shows or asks (body), Cancel
     * and the verb. `act` runs on the verb with the question open: true closes it, {ok: false, message} keeps it open
     * showing those words, so a field it asks keeps what was typed. Answers whether it was done.
     */
    function ask(title, body, verb, danger, act, focus) {
        return QuickSiteUtils.confirm('', {
            title: title,
            body: body,
            confirmText: verb,
            type: danger ? 'danger' : 'info',
            focus: focus,
            action: act,
        });
    }

    /** A command's answer as a question's action wants it: true, or the server's words kept in the question. */
    function answered(res) {
        return res && res.ok ? true : { ok: false, message: serverMessage(res) };
    }

    function _renderRoleSelect(id, roles) {
        var sel = el('select', { class: 'admin-input', id: id });
        roles.forEach(function (r) {
            sel.appendChild(el('option', { value: r, text: QuickSiteUtils.roleName(r) }));
        });
        return sel;
    }

    function fillRoleSelect(selectEl, roles) {
        if (!selectEl) return;
        clearNode(selectEl);
        roles.forEach(function (r) {
            selectEl.appendChild(el('option', { value: r, text: QuickSiteUtils.roleName(r) }));
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
                isSelf ? el('span', { class: 'members-chip members-chip--you', text: QSPanelWords.t('members.roster.you') }) : null,
            ]),
            el('span', { class: 'members-row__chips' }, [_renderRoleChip(m.role)]),
            el('code', { class: 'members-row__id', text: m.user_id }),
        ];
        var actions = [];
        if (!isSelf && !m.is_owner && iOutrank(m.role)) {
            var who = m.name || m.user_id;
            actions.push(QSControls.edit(QSPanelWords.t('members.roster.changeRoleOf', { name: who }), function () { openChangeRoleModal(m); },
                { focusKey: 'member-role:' + m.user_id }));
            actions.push(QSControls.bin(QSPanelWords.t('members.roster.removeOf', { name: who }), function () { openRemoveModal(m); },
                { focusKey: 'member-remove:' + m.user_id }));
        }
        return _renderRow(main, actions);
    }

    function renderRoster() {
        var root = document.getElementById('members-roster');
        if (!root) return;
        var focusBack = root.contains(document.activeElement) ? window.QSDom.rememberFocus() : null;
        drawRoster(root);
        if (focusBack) focusBack.restore();
    }

    function drawRoster(root) {
        clearNode(root);
        setCount('members-roster-count', state.roster.length);
        if (!state.roster.length) {
            root.appendChild(_renderEmpty(QSPanelWords.t('members.roster.empty')));
            return;
        }
        state.roster.forEach(function (m) { root.appendChild(_renderMemberRow(m)); });
    }

    function openChangeRoleModal(m) {
        var sel = _renderRoleSelect('change-role-select', assignableRoles());
        sel.value = iOutrank(m.role) && rankOf(m.role) > 0 ? m.role : sel.value;
        ask(
            QSPanelWords.t('members.roster.changeRoleTitle', { name: m.name || m.user_id }),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'change-role-select', text: QSPanelWords.t('members.roster.newRoleLabel') }),
                    sel,
                ]),
            ],
            QSPanelWords.t('members.roster.apply'), false,
            async function () {
                var res = await api('changeMemberRole', 'POST', { user_id: m.user_id, role: sel.value });
                await refreshLists();
                if (!res || !res.ok) return answered(res);
                toast(QSPanelWords.t('members.roster.roleChangedMsg'), 'success');
                return true;
            },
            sel
        );
    }

    function openRemoveModal(m) {
        var noteInput = el('input', { type: 'text', class: 'admin-input', id: 'remove-note-input', maxlength: '500' });
        ask(
            QSPanelWords.t('members.roster.removeTitle', { name: m.name || m.user_id }),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'remove-note-input', text: QSPanelWords.t('members.roster.removeNoteLabel') }),
                    noteInput,
                ]),
            ],
            QSPanelWords.t('members.roster.remove'), true,
            async function () {
                var body = { user_id: m.user_id };
                var note = noteInput.value.trim();
                if (note) body.note = note;
                var res = await api('removeMember', 'POST', body);
                await refreshLists();
                notifyMembershipChange();
                if (!res || !res.ok) return answered(res);
                toast(QSPanelWords.t('members.roster.removedMsg'), 'success');
                return true;
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
            ? el('span', { class: 'members-chip members-chip--invite', text: QSPanelWords.t('members.queue.chipInvite') })
            : (isSelfRequest
                ? el('span', { class: 'members-chip members-chip--request', text: QSPanelWords.t('members.queue.chipRequest') })
                : el('span', { class: 'members-chip members-chip--proposal', text: QSPanelWords.t('members.queue.chipProposal') }));

        // Who sent it, in the words of its kind: an invitation, the person's own request, a member's proposal.
        var byKey = isInvite ? 'members.queue.invitedBy' : (isSelfRequest ? 'members.queue.askedBy' : 'members.queue.proposedBy');
        var byName = q.invited_by ? (q.invited_by.name || q.invited_by.user_id) : null;

        var main = [
            el('span', { class: 'members-row__title' }, [
                el('strong', { text: q.name || q.user_id }),
                kindChip,
            ]),
            el('span', { class: 'members-row__chips' }, [_renderRoleChip(q.role)]),
            _renderMeta([
                byName ? QSPanelWords.t(byKey, { name: byName }) : null,
                q.sponsored_by ? QSPanelWords.t('members.queue.proposedBy', { name: q.sponsored_by.name || q.sponsored_by.user_id }) : null,
                q.at || null,
            ]),
            el('code', { class: 'members-row__id', text: q.user_id }),
        ];
        if (q.note) main.push(_renderNote(q.note));

        var actions = [];
        if (iOutrank(q.role)) {
            if (isInvite) {
                actions.push(QSControls.bin(QSPanelWords.t('members.queue.cancelInviteOf', { name: q.name || q.user_id }), function () { openCancelInviteModal(q); },
                    { focusKey: 'invite-delete:' + q.user_id }));
            } else {
                actions.push(_renderActionBtn(QSPanelWords.t('members.queue.approve'), ICON_CHECK, 'primary', function () { openApproveModal(q); }));
                actions.push(_renderActionBtn(QSPanelWords.t('members.queue.deny'), null, 'danger', function () { openDenyModal(q); }));
            }
        }
        return _renderRow(main, actions);
    }

    function renderQueue() {
        var root = document.getElementById('members-queue');
        if (!root) return;
        var focusBack = root.contains(document.activeElement) ? window.QSDom.rememberFocus() : null;
        drawQueue(root);
        if (focusBack) focusBack.restore();
    }

    function drawQueue(root) {
        clearNode(root);
        setCount('members-queue-count', state.queue.length);
        if (!state.queue.length) {
            root.appendChild(_renderEmpty(QSPanelWords.t('members.queue.empty')));
            return;
        }
        state.queue.forEach(function (q) { root.appendChild(_renderQueueRow(q)); });
    }

    function openApproveModal(q) {
        // Role-at-approve: grant straight to a chosen role instead of
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
                el('label', { class: 'admin-label', for: 'approve-role-select', text: QSPanelWords.t('members.queue.approveRoleLabel') }),
                roleSelect,
            ]),
        ];
        if (q.note) body.push(_renderNote(q.note));
        ask(
            QSPanelWords.t('members.queue.approveTitle'), body,
            QSPanelWords.t('members.queue.approve'), false,
            async function () {
                var res = await api('approveJoinRequest', 'POST', { user_id: q.user_id, role: roleSelect.value });
                await refreshLists();
                notifyMembershipChange();
                if (!res || !res.ok) return answered(res);
                var converted = res.data && res.data.data && res.data.data.converted_to_invitation;
                toast(QSPanelWords.t(converted ? 'members.queue.convertedMsg' : 'members.queue.approvedMsg'), 'success');
                return true;
            },
            roleSelect
        );
    }

    function openDenyModal(q) {
        var noteInput = el('textarea', { class: 'admin-input', id: 'deny-note-input', rows: '3', maxlength: '500' });
        ask(
            QSPanelWords.t('members.queue.denyTitle', { name: q.name || q.user_id }),
            [
                el('div', { class: 'admin-form-group' }, [
                    el('label', { class: 'admin-label', for: 'deny-note-input', text: QSPanelWords.t('members.queue.denyNoteLabel') }),
                    noteInput,
                ]),
            ],
            QSPanelWords.t('members.queue.deny'), true,
            async function () {
                var note = noteInput.value.trim();
                if (!note) {
                    noteInput.focus();
                    return { ok: false, message: QSPanelWords.t('members.queue.denyNoteRequired') };
                }
                var res = await api('denyJoinRequest', 'POST', { user_id: q.user_id, note: note });
                await refreshLists();
                notifyMembershipChange();
                if (!res || !res.ok) return answered(res);
                toast(QSPanelWords.t('members.queue.deniedMsg'), 'success');
                return true;
            },
            noteInput
        );
    }

    function openCancelInviteModal(q) {
        ask(
            QSPanelWords.t('members.queue.withdrawInviteTitle', { name: q.name || q.user_id }),
            [el('p', {}, [el('strong', { text: q.name || q.user_id }), ' — ', _renderRoleChip(q.role)])],
            QSPanelWords.t('members.queue.withdrawInvite'), true,
            async function () {
                var res = await api('cancelInvitation', 'POST', { user_id: q.user_id });
                await refreshLists();
                notifyMembershipChange();
                if (!res || !res.ok) return answered(res);
                toast(QSPanelWords.t('members.queue.cancelledMsg'), 'success');
                return true;
            }
        );
    }

    // ============================================================
    // User-lookup picker (shared by invite + propose forms)
    // ============================================================

    function _renderMatchRow(match, onPick) {
        return el('div', { class: 'members-match-row' }, [
            el('span', { class: 'members-row__title' }, [el('strong', { text: match.name })]),
            el('code', { class: 'members-row__id', text: match.user_id }),
            _renderActionBtn(QSPanelWords.t('members.invite.select'), ICON_CHECK, 'ghost', function () { onPick(match); }),
        ]);
    }

    // ============================================================
    // Contacts — people this account has already looked up
    //
    // A shortcut past the typing, never a replacement for the lookup: somebody
    // who is not already here is still found by their exact public name, the
    // same way as before.
    //
    // ⚠ The list only ever contains people the account holder has already found
    // and then actually named to a project. Nothing here searches, browses or
    // resolves an account — the name shown is the one that was stored at the
    // time, replayed verbatim. See secure/admin/functions/contacts.php for why
    // that construction, and not a permission check, is what keeps this from
    // being a directory.
    // ============================================================

    function selfRequest(route, method, body) {
        var admin = window.QuickSiteAdmin;
        if (!admin || typeof admin.accountRequest !== 'function') {
            return Promise.reject(new Error(QSPanelWords.t('common.adminUnavailable')));
        }
        return admin.accountRequest(route, method, body);
    }

    function loadContacts() {
        return selfRequest('contacts', 'GET').then(function (res) {
            state.contacts = (res && res.ok && res.data && res.data.data && res.data.data.contacts) || [];
            contactRenderers.forEach(function (redraw) { redraw(); });
        });
    }

    /**
     * Record one person on the caller's list, after they were successfully
     * named to a project. Re-adding refreshes the stored display name from the
     * lookup that just succeeded — the only way a name here is brought up to
     * date.
     */
    function rememberContact(person) {
        if (!person || !person.user_id) return;
        selfRequest('add-contact', 'POST', { user_id: person.user_id, name: person.name })
            .then(function (res) {
                if (res && res.ok) { loadContacts(); return; }
                // The membership change already succeeded — this is the list not
                // being updated, which is worth a word but is not a failure of
                // what the operator asked for.
                toast(QSPanelWords.t(res && res.status === 409 ? 'members.contacts.full' : 'members.contacts.saveFailed'), 'warning');
            });
    }

    /** Forget a contact, asked first: a refusal stays in the question. Answers whether it was forgotten. */
    function forgetContact(contact) {
        return ask(QSPanelWords.t('members.contacts.forgetTitle', { name: contact.name || contact.user_id }),
            [el('p', { text: QSPanelWords.t('members.contacts.forgetText') })],
            QSPanelWords.t('members.contacts.forget'), true,
            async function () {
                var res = await selfRequest('remove-contact', 'POST', { user_id: contact.user_id });
                if (!res || !res.ok) return { ok: false, message: serverMessage(res, QSPanelWords.t('members.contacts.forgetFailed')) };
                toast(QSPanelWords.t('members.contacts.forgotten'), 'success');
                loadContacts();
                return true;
            });
    }

    /** The contact this user id names on the caller's list, or null. */
    function contactOf(userId) {
        for (var i = 0; i < state.contacts.length; i++) {
            if (state.contacts[i].user_id === userId) return state.contacts[i];
        }
        return null;
    }

    /**
     * An invitation or a proposal answered that no account has this id: a contact who deleted their account
     * before a deletion took accounts off every list, or one written by hand. The panel forgets them, and says
     * so in place of the server's message. Somebody found by a search, not on the list, is only refused.
     */
    function forgetGoneContact(res, picked) {
        if (!res || res.status !== 404 || !res.data || res.data.code !== 'user.not_found' || !picked || !contactOf(picked.user_id)) {
            return false;
        }
        selfRequest('remove-contact', 'POST', { user_id: picked.user_id }).then(function () { loadContacts(); });
        toast(QSPanelWords.t('members.contacts.gone'), 'warning');
        return true;
    }

    /** One person of the list in its dropdown: the name, with the id the dropdown shows under it and searches too. */
    function _renderContactOption(contact) {
        return el('option', { value: contact.user_id, text: contact.name, 'data-description': contact.user_id });
    }

    function _renderPickedRow(match) {
        return el('div', { class: 'members-match-row members-match-row--picked' }, [
            el('span', { class: 'members-row__title' }, [
                el('span', { class: 'members-chip members-chip--picked', text: QSPanelWords.t('members.invite.selected') }),
                el('strong', { text: match.name }),
            ]),
            el('code', { class: 'members-row__id', text: match.user_id }),
        ]);
    }

    /**
     * Wire one user-lookup flow. Returns { getPicked, reset }.
     * prefix: 'invite' | 'propose' (matches the template element ids).
     */
    function setupFindUser(prefix, sendBtn) {
        var nameInput = document.getElementById(prefix + '-find-name');
        var findBtn = document.getElementById('btn-' + prefix + '-find');
        var results = document.getElementById(prefix + '-find-results');
        var contactsBlock = document.getElementById(prefix + '-contacts');
        var contactsSelect = document.getElementById(prefix + '-contacts-select');
        var forgetBtn = document.getElementById('btn-' + prefix + '-contact-forget');
        var contactsPicker = null;
        var picked = null;

        if (!nameInput || !findBtn || !results) return { getPicked: function () { return null; }, reset: function () {} };

        /** The dropdown shows the picked person when they are on the list, else nobody; Forget acts on that one. */
        function showPickedInContacts() {
            if (!contactsSelect) return;
            var contact = picked ? contactOf(picked.user_id) : null;
            contactsSelect.value = contact ? contact.user_id : '';
            if (contactsPicker) contactsPicker.refresh();
            if (forgetBtn) forgetBtn.disabled = !contact;
        }

        function pick(match) {
            picked = match;
            clearNode(results);
            results.appendChild(_renderPickedRow(match));
            if (sendBtn) sendBtn.disabled = false;
            showPickedInContacts();
        }

        // The contacts shortcut: one searchable dropdown of the people on the
        // list, a name each with its id under it. Hidden entirely while the list
        // is empty: a new account has nobody on it, and an empty panel above the
        // search box is noise on the one page where the search box is the whole point.
        function renderContacts() {
            if (!contactsBlock || !contactsSelect) return;
            clearNode(contactsSelect);
            if (!state.contacts.length) {
                contactsBlock.hidden = true;
                return;
            }
            contactsBlock.hidden = false;
            contactsSelect.appendChild(el('option', { value: '' }));
            state.contacts.forEach(function (c) { contactsSelect.appendChild(_renderContactOption(c)); });
            if (!contactsPicker && window.QSSearchableSelect) {
                contactsPicker = new window.QSSearchableSelect(contactsSelect, { placeholder: QSPanelWords.t('members.contacts.pick') });
            }
            showPickedInContacts();
        }
        if (contactsSelect) {
            contactsSelect.addEventListener('change', function () {
                var contact = contactOf(contactsSelect.value);
                if (contact) pick(contact);
            });
        }
        if (forgetBtn && contactsSelect) {
            forgetBtn.addEventListener('click', async function () {
                var contact = contactOf(contactsSelect.value);
                if (!contact) return;
                if (!(await forgetContact(contact))) return;
                if (picked && picked.user_id === contact.user_id) {
                    picked = null;
                    clearNode(results);
                    if (sendBtn) sendBtn.disabled = true;
                }
            });
        }
        contactRenderers.push(renderContacts);
        renderContacts();

        function search() {
            var name = nameInput.value.trim();
            picked = null;
            if (sendBtn) sendBtn.disabled = true;
            if (!name) {
                toast(QSPanelWords.t('members.invite.nameRequired'), 'warning');
                nameInput.focus();
                return;
            }
            findBtn.disabled = true;
            // The directory lookup is NOT a command — it identifies a
            // person, it does not develop a project. Same response shape.
            window.QuickSiteAdmin.accountRequest('find-user', 'POST', { name: name }).then(function (res) {
                findBtn.disabled = false;
                clearNode(results);
                var matches = (res && res.ok && res.data && res.data.data && res.data.data.matches) || [];
                if (!matches.length) {
                    results.appendChild(_renderEmpty(QSPanelWords.t('members.invite.noMatches')));
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
                showPickedInContacts();
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
            if (!picked) { toast(QSPanelWords.t('members.invite.pickRequired'), 'warning'); return; }
            var role = document.getElementById('invite-role').value;
            var note = document.getElementById('invite-note').value.trim();
            var body = { user_id: picked.user_id, role: role };
            if (note) body.note = note;
            sendBtn.disabled = true;
            api('inviteMember', 'POST', body).then(function (res) {
                if (res && res.ok) {
                    toast(QSPanelWords.t('members.invite.sentMsg'), 'success');
                    // Recorded on a SUCCESSFUL offer, not on a successful search:
                    // a contact is somebody you actually brought onto a project,
                    // which is the person you will want again on the next one. A
                    // search that went nowhere is a search, not a relationship.
                    rememberContact(picked);
                    finder.reset();
                    document.getElementById('invite-note').value = '';
                    refreshLists();
                    notifyMembershipChange();
                } else if (forgetGoneContact(res, picked)) {
                    finder.reset();
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
            if (!picked) { toast(QSPanelWords.t('members.invite.pickRequired'), 'warning'); return; }
            var note = document.getElementById('propose-note').value.trim();
            if (!note) {
                toast(QSPanelWords.t('members.propose.vouchRequired'), 'warning');
                document.getElementById('propose-note').focus();
                return;
            }
            var role = document.getElementById('propose-role').value;
            sendBtn.disabled = true;
            api('proposeMember', 'POST', { user_id: picked.user_id, role: role, note: note }).then(function (res) {
                if (res && res.ok) {
                    toast(QSPanelWords.t('members.propose.sentMsg'), 'success');
                    // Same rule as invite above, and it has to be: an admin who
                    // can only propose would otherwise never build a list.
                    rememberContact(picked);
                    finder.reset();
                    document.getElementById('propose-note').value = '';
                    refreshLists();
                    notifyMembershipChange();
                } else if (forgetGoneContact(res, picked)) {
                    finder.reset();
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
        valueEl.textContent = QSPanelWords.t(policy === 'open' ? 'members.policy.open' : 'members.policy.closed');
        valueEl.className = 'members-policy-value members-policy-value--' + policy;
        toggleBtn.disabled = false;

        if (advisory && advisoryText) {
            var showAdvisory = policy === 'open';
            advisory.hidden = !showAdvisory;
            if (showAdvisory) advisoryText.textContent = QSPanelWords.t('members.policy.advisory');
        }
    }

    function applyPolicy(target) {
        var toggleBtn = document.getElementById('btn-toggle-policy');
        if (toggleBtn) toggleBtn.disabled = true;
        api('setJoinPolicy', 'POST', { policy: target }).then(function (res) {
            if (res && res.ok) {
                state.joinPolicy = (res.data && res.data.data && res.data.data.join_policy) || target;
                toast(QSPanelWords.t('members.policy.savedMsg'), 'success');
                // The server attaches an advisory note when the policy it set is
                // open — surface its exact wording too.
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
            if (target === 'open') {
                ask(
                    QSPanelWords.t('members.policy.confirmOpenTitle'),
                    [el('p', { text: QSPanelWords.t('members.policy.confirmOpenPrivate') })],
                    QSPanelWords.t('members.policy.confirmOpenSubmit'), true,
                    function () { return true; }
                ).then(function (yes) { if (yes) applyPolicy(target); });
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
            memberSel.appendChild(el('option', { value: m.user_id, text: (m.name || m.user_id) + ' (' + (m.role ? QuickSiteUtils.roleName(m.role) : '?') + ')' }));
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
            if (!userId) { toast(QSPanelWords.t('members.transfer.memberRequired'), 'warning'); return; }
            if (!confirmChk || !confirmChk.checked) { toast(QSPanelWords.t('members.transfer.confirmRequired'), 'warning'); return; }

            var picked = null;
            state.roster.forEach(function (m) { if (m.user_id === userId) picked = m; });
            var bodyText = QSPanelWords.t('members.transfer.modalBody', { name: (picked && picked.name) || userId, role: roleSel.value });

            ask(
                QSPanelWords.t('members.transfer.modalTitle'),
                [el('p', { text: bodyText })],
                QSPanelWords.t('members.transfer.submit'), true,
                async function () {
                    var res = await api('transferOwnership', 'POST', { user_id: userId, confirm: true, old_owner_role: roleSel.value });
                    if (!res || !res.ok) return answered(res);
                    toast(QSPanelWords.t('members.transfer.doneMsg'), 'success');
                    // Roles changed radically (owner → old_owner_role):
                    // reload so the server re-renders the page for the
                    // new reality (sections, banner, pickers). The reload
                    // recomputes the nav badge on its own — do NOT fire
                    // notifyMembershipChange() here, or its in-flight
                    // count fetches get cancelled by the navigation and
                    // log a spurious NetworkError.
                    window.location.href = window.location.pathname + '?t=' + Date.now();
                    return true;
                }
            );
        });
    }

    // ============================================================
    // Load + init
    // ============================================================

    // ============================================================
    // Requests waiting in your other projects
    // ============================================================

    /** Makes `project` the edited one and reopens this page on it; a refusal says so and stays here. */
    function switchToProject(project, button) {
        button.disabled = true;
        window.QuickSiteAdmin.setSelectedProject(project).then(function (res) {
            if (res && res.ok) {
                window.location.href = window.location.pathname + '?t=' + Date.now();
                return;
            }
            toast(serverMessage(res, QSPanelWords.t('members.queue.elsewhereSwitchFailed')), 'error');
            button.disabled = false;
        }, function () {
            toast(QSPanelWords.t('members.queue.elsewhereSwitchFailed'), 'error');
            button.disabled = false;
        });
    }

    /** One project of the notice: its number, its name and the way to it. @returns {HTMLElement} */
    function _renderElsewhereRow(p) {
        var label = window.QuickSiteAdmin && typeof window.QuickSiteAdmin.projectLabel === 'function'
            ? window.QuickSiteAdmin.projectLabel(p.project, p.name)
            : p.project;
        var go = el('button', {
            class: 'admin-btn admin-btn--primary',
            type: 'button',
            onclick: function () { switchToProject(p.project, go); },
        }, [QSPanelWords.t('members.queue.elsewhereSwitch')]);
        return el('div', { class: 'members-elsewhere__row' }, [
            el('span', { class: 'badge badge--warning', text: String(p.count) }),
            el('span', { class: 'members-elsewhere__name', text: label }),
            go,
        ]);
    }

    /**
     * A notice for each project, other than the edited one, where a join request waits for this
     * account: the menu's count covers every project it runs, the page one. Nothing when none
     * waits. The numbers are members-badge.js's, read once per change.
     */
    function renderElsewhere(counts) {
        var box = document.getElementById('members-elsewhere');
        if (!box) return;
        var others = ((counts && counts.adjudicationByProject) || []).filter(function (p) {
            return p.project !== CFG.project;
        });
        clearNode(box);
        box.hidden = !others.length;
        if (!others.length) return;
        box.appendChild(el('strong', { class: 'members-elsewhere__title', text: QSPanelWords.t('members.queue.elsewhereTitle') }));
        others.forEach(function (p) { box.appendChild(_renderElsewhereRow(p)); });
    }

    /** Read the roster and the queue again and draw them: a promise of both drawn. */
    function refreshLists() {
        var reads = [api('getProjectRoster', 'GET').then(function (res) {
            state.roster = (res && res.ok && res.data && res.data.data && res.data.data.members) || [];
            renderRoster();
            fillTransferSelectors();
        })];
        if (document.getElementById('members-queue')) {
            reads.push(api('listMembers', 'GET').then(function (res) {
                var d = (res && res.ok && res.data && res.data.data) || {};
                state.queue = d.invitations || [];
                renderQueue();
                renderPolicy();
            }));
        }
        return Promise.all(reads).catch(function () { /* a failed read leaves the lists as they were */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupInviteForm();
        setupProposeForm();
        setupPolicyToggle();
        setupTransfer();
        refreshLists();
        // After the two forms have registered their redraws, so one fetch fills
        // both. Harmless when neither form rendered — the list stays unused.
        loadContacts();
        // members-badge.js has run by now (a script at the end of the body); it recomputes,
        // and says so, after every change to a membership.
        if (window.QSMembershipCounts) window.QSMembershipCounts.then(renderElsewhere);
        window.addEventListener('quicksite:membership-counts-updated', function (e) { renderElsewhere(e.detail); });
    });
})();
