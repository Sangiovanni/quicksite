/**
 * Membership counts + nav badge.
 *
 * Computes, asynchronously, the numbers the Members nav badges and the
 * dashboard memberships card show — derived ENTIRELY from existing reads
 * (no new storage, no polling):
 *   - awaiting me: my pending invitations + undismissed notices
 *     (one /admin/self/invitations call);
 *   - awaiting my adjudication: direction:'request' rows in the queue of each
 *     project where I am admin/owner (listProjects → listMembers per project),
 *     in total and per project (adjudicationByProject: the Project members page
 *     names the projects other than the edited one where a request waits).
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
            adjudicationByProject: [],   // { project, name, count } for each project where a request waits
            awaitingMe: 0,
            total: 0,
        };

        // The membership inbox is an ACCOUNT surface, not a command.
        var inboxP = API.accountRequest('invitations', 'GET').then(function (res) {
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
                if (p.my_role === 'owner' || p.my_role === 'admin') adminProjects.push(p);
            });
            return Promise.all(adminProjects.map(function (p) {
                return API.request('listMembers', 'GET', null, [], {}, false, { project: p.project }).then(function (r) {
                    var invs = (r && r.ok && r.data && r.data.data && r.data.data.invitations) || [];
                    var n = 0;
                    invs.forEach(function (inv) {
                        if (inv.direction === 'request') n++;
                    });
                    counts.adjudication += n;
                    if (n > 0) counts.adjudicationByProject.push({ project: p.project, name: p.name, count: n });
                });
            })).then(function () {
                // In the order listProjects gave, whatever order the answers came in.
                var order = adminProjects.map(function (p) { return p.project; });
                counts.adjudicationByProject.sort(function (a, b) { return order.indexOf(a.project) - order.indexOf(b.project); });
            });
        });

        return Promise.all([inboxP, adjudicationP]).then(function () {
            counts.awaitingMe = counts.invitations + counts.notices;
            counts.total = counts.awaitingMe + counts.adjudication;
            return counts;
        }).catch(function () {
            return null; // a dead session / network hiccup never breaks the page
        });
    }

    // Always refresh the text (never leave a stale number behind) and let the
    // hidden attribute — backed by a [hidden] CSS guard — do the hiding.
    function paintOne(id, n) {
        var badge = document.getElementById(id);
        if (!badge) return;
        badge.textContent = String(n);
        badge.hidden = (n === 0);
    }

    // The Members group holds the total; each of its entries holds its own share, so the
    // group says something waits and the entry says where: My memberships what waits for
    // me, Project members the requests waiting for my validation.
    function paintBadge(counts) {
        var n = counts ? counts.total : 0;
        paintOne('members-nav-badge', n);
        paintOne('members-nav-badge-mine', counts ? counts.awaitingMe : 0);
        paintOne('members-nav-badge-queue', counts ? counts.adjudication : 0);
        // On a phone the badges sit inside the closed panel, so the menu button says it too.
        var dot = document.getElementById('admin-header-menu-dot');
        if (dot) dot.hidden = (n === 0);
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
