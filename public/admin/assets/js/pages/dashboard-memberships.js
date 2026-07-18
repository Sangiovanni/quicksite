/**
 * Dashboard memberships card (C8 8.3c) — fills the six stat values whenever
 * core/members-badge.js publishes fresh counts (the
 * 'quicksite:membership-counts-updated' event).
 *
 * Purely event-driven: this script parses BEFORE members-badge.js (template
 * vs bottom-of-body), so the badge module's first computation always finds
 * this listener registered — no load-order race.
 *
 * Deliberately its OWN file: dashboard.js predates the HTML-in-JS hygiene
 * rule and must not grow; this card's static structure lives in
 * templates/pages/dashboard.php, so this script only sets text values.
 */
(function () {
    'use strict';

    function setStat(id, value, highlight) {
        var elValue = document.getElementById(id);
        if (!elValue) return;
        elValue.textContent = String(value);
        var stat = document.getElementById(id + '-stat');
        if (stat) stat.classList.toggle('dashboard-memberships__stat--attention', !!highlight && value > 0);
    }

    function paint(counts) {
        if (!counts) return; // unauthenticated / failed load: leave the dashes
        setStat('dash-mem-projects', counts.projects);
        setStat('dash-mem-owned', counts.owned);
        setStat('dash-mem-invitations', counts.invitations, true);
        setStat('dash-mem-requests', counts.myRequests);
        setStat('dash-mem-notices', counts.notices, true);
        setStat('dash-mem-queue', counts.adjudication, true);
    }

    window.addEventListener('quicksite:membership-counts-updated', function (e) {
        paint(e.detail);
    });
})();
