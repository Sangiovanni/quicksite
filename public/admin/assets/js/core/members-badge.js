/**
 * Membership counts + nav badge (C8 8.3c).
 *
 * Computes, asynchronously, the numbers the Members nav badge and the
 * dashboard memberships card show — derived ENTIRELY from existing reads
 * (no new storage, no polling):
 *   - awaiting me: my pending invitations + undismissed notices
 *     (one listMyInvitations call);
 *   - awaiting my adjudication: direction:'request' rows in the queue of each
 *     project where I am admin/owner (listProjects → listMembers per project).
 *
 * Publishes the result two ways: window.QSMembershipCounts (a Promise of the
 * counts object) and a 'quicksite:membership-counts-updated' window event
 * (detail = counts) consumers subscribe to (dashboard-memberships.js).
 * Recomputes on the 'quicksite:memberships-changed' event the membership
 * pages dispatch after successful mutations.
 *
 * Uses QuickSiteAPI directly (loaded just before this file) — QuickSiteAdmin
 * (admin.js) does not exist yet at parse time. The nav badge element sits in
 * the header, parsed long before this bottom-of-body script, so painting at
 * parse time is safe.
 */
(function () {
    'use strict';

    function compute() {
        var API = window.QuickSiteAPI;
        if (!API || !API.isAuthenticated()) {
            return Promise.resolve(null);
        }

        var counts = {
            projects: 0,
            owned: 0,
            invitations: 0,
            myRequests: 0,
            notices: 0,
            adjudication: 0,
            awaitingMe: 0,
            total: 0,
        };

        var inboxP = API.request('listMyInvitations', 'GET').then(function (res) {
            var d = (res && res.ok && res.data && res.data.data) || {};
            counts.invitations = d.invitation_count || 0;
            counts.myRequests = d.request_count || 0;
            counts.notices = d.notice_count || 0;
        });

        var adjudicationP = API.request('listProjects', 'GET').then(function (res) {
            var projects = (res && res.ok && res.data && res.data.data && res.data.data.projects) || [];
            counts.projects = projects.length;
            var adminProjects = [];
            projects.forEach(function (p) {
                if (p.my_role === 'owner') counts.owned++;
                if (p.my_role === 'owner' || p.my_role === 'admin') adminProjects.push(p.name);
            });
            return Promise.all(adminProjects.map(function (name) {
                return API.request('listMembers', 'GET', null, [], {}, false, { project: name }).then(function (r) {
                    var invs = (r && r.ok && r.data && r.data.data && r.data.data.invitations) || [];
                    invs.forEach(function (inv) {
                        if (inv.direction === 'request') counts.adjudication++;
                    });
                });
            }));
        });

        return Promise.all([inboxP, adjudicationP]).then(function () {
            counts.awaitingMe = counts.invitations + counts.notices;
            counts.total = counts.awaitingMe + counts.adjudication;
            return counts;
        }).catch(function () {
            return null; // a dead session / network hiccup never breaks the page
        });
    }

    function paintBadge(counts) {
        var badge = document.getElementById('members-nav-badge');
        if (!badge) return;
        var n = counts ? counts.total : 0;
        // Always refresh the text (never leave a stale number behind) and let the
        // hidden attribute — now backed by a [hidden] CSS guard — do the hiding.
        badge.textContent = String(n);
        badge.hidden = (n === 0);
    }

    function refresh() {
        var p = compute();
        window.QSMembershipCounts = p;
        p.then(function (counts) {
            paintBadge(counts);
            window.dispatchEvent(new CustomEvent('quicksite:membership-counts-updated', { detail: counts }));
        });
    }

    refresh();
    window.addEventListener('quicksite:memberships-changed', refresh);
})();
