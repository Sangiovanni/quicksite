<?php
/**
 * The panel's menu: ONE definition, read by the menu (layout.php) and by the chooser that asks an account
 * which mode it wants (templates/partials/mode-chooser.php).
 *
 * Each entry names its page, its words, its icon, the commands a role must hold for the menu to show it
 * (`requires`, the same `data-requires-command` filter the panel has always run once permissions load),
 * a deployer's switch that can take it off this installation (`offered`), and whether the simple menu
 * shows it (`simple`). An entry without `simple` is advanced: a page added to the menu later stays out of
 * the simple menu until this definition names it.
 *
 * ⚠ THE MODE IS NOT A PERMISSION, AND NEITHER IS A MENU ENTRY. A page left out of the simple menu, or out of
 * the menu altogether, opens at its address for anyone whose role allows it: the router's page permissions
 * (AdminRouter::PAGE_PERMISSIONS) and each command's own check decide that, and nothing here is read by them.
 * The deployer's switches are the same kind of thing (see consolePolicy.php): they remove reach, not access.
 *
 * Groups: a group the menu would draw with no entry left (after the role and the mode) is not drawn, and a
 * group with one entry left is drawn as that entry, a plain link (admin.js, applyMenuGroups()). A group's
 * `active` lists the pages that light it.
 */

require_once SECURE_FOLDER_PATH . '/admin/functions/panelState.php';

if (!function_exists('qs_admin_menu')) {
    /**
     * The menu, in its order. Words are translation keys with their English, as `__admin()` takes them; icons
     * are the inner markup of a 24 × 24 stroked SVG.
     *
     * @return list<array<string, mixed>>
     */
    function qs_admin_menu(): array {
        return [
            ['page' => 'dashboard', 'label' => ['nav.dashboard', null], 'simple' => true,
             'icon' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],

            // Build: the toggle opens the Visual Editor. The editor opens for every role, read-only for a viewer,
            // so it needs only what reading a page needs; every other entry also needs editStructure, which
            // the whole group asked for before the Visual Editor had a check of its own.
            ['group' => 'build', 'page' => 'preview', 'label' => ['nav.build', null],
             'active' => ['workflows', 'command', 'history', 'preview', 'apis', 'builds'],
             'icon' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
             'entries' => [
                ['page' => 'preview', 'label' => ['nav.visualEditor', null], 'requires' => 'getStructure', 'simple' => true,
                 'icon' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'],
                // An installation can decline to offer the command console (console.php; absent means it is
                // offered). Not drawn at all when it is off: the role filter only hides what it finds.
                ['page' => 'command', 'label' => ['nav.commands', null], 'requires' => 'editStructure', 'offered' => 'console',
                 'icon' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'],
                // Command history: its own page, not gated on the console (the trail is independent of the
                // runner). Admin and owner only; the page itself is gated on the server.
                ['page' => 'history', 'label' => ['nav.history', 'History'], 'requires' => 'editStructure,getCommandHistory',
                 'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
                // API Endpoints sit under Build, not Settings: an authoring surface used during build.
                ['page' => 'apis', 'label' => ['nav.apiEndpoints', null], 'requires' => 'editStructure,listApiEndpoints',
                 'icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>'],
                // Builds: since deploy left the engine, a build is how a beginner gets the site out.
                ['page' => 'builds', 'label' => ['nav.builds', 'Builds'], 'requires' => 'editStructure,getBuild', 'simple' => true,
                 'icon' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>'],
             ]],

            ['page' => 'media', 'label' => ['nav.assets', 'Media'], 'requires' => 'listAssets', 'simple' => true,
             'icon' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'],

            ['page' => 'sitemap', 'label' => ['nav.sitemap', null], 'requires' => 'getSiteMap', 'simple' => true,
             'icon' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],

            // The OAuth providers page, the panel's only authentication surface.
            ['page' => 'oauth-providers', 'label' => ['nav.authentication', null], 'requires' => 'listOAuthProviders',
             'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],

            // Members: My Memberships works for every account, a member of nothing included (its inbox), so the
            // toggle opens it; Project members needs a member rank of the edited project. Each carries its count.
            ['group' => 'members', 'page' => 'memberships', 'label' => ['nav.members', 'Members'], 'badge' => 'members-nav-badge',
             'active' => ['memberships', 'members'],
             'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
             'entries' => [
                ['page' => 'memberships', 'label' => ['nav.myMemberships', 'My memberships'], 'badge' => 'members-nav-badge-mine', 'simple' => true,
                 'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
                ['page' => 'members', 'label' => ['nav.projectMembers', 'Project members'], 'badge' => 'members-nav-badge-queue', 'requires' => 'proposeMember', 'simple' => true,
                 'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
             ]],

            // Compliance: the data layer (Storage) and the data sharing (Privacy).
            ['group' => 'compliance', 'page' => 'storage', 'label' => ['nav.compliance', 'Compliance'],
             'active' => ['storage', 'privacy'],
             'icon' => '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>',
             'entries' => [
                ['page' => 'storage', 'label' => ['nav.storage', 'Storage'], 'requires' => 'listStorageItems',
                 'icon' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>'],
                ['page' => 'privacy', 'label' => ['nav.privacy', 'Privacy'], 'requires' => 'listStorageItems,getPrivacyStatus',
                 'icon' => '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>'],
             ]],

            ['page' => 'optimize', 'label' => ['nav.optimize', null], 'requires' => 'getStyles',
             'icon' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],

            // Settings: no check on the group; the entries carry their own where they need one. AI connections
            // stays in simple because the editor's Workflows need a key.
            ['group' => 'settings', 'page' => 'settings', 'label' => ['nav.settings', null],
             'active' => ['settings', 'ai-settings', 'ai-connections', 'embed-security'],
             'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
             'entries' => [
                ['page' => 'settings', 'label' => ['nav.projectSettings', null], 'simple' => true,
                 'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4"/>'],
                ['page' => 'ai-connections', 'label' => ['nav.ai', null], 'active' => ['ai-connections', 'ai-settings'], 'simple' => true,
                 'icon' => '<path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/>'],
                // An installation can decline to show it (embed-security.php; absent means shown).
                ['page' => 'embed-security', 'label' => ['nav.embedSecurity', null], 'requires' => 'getIframeSandbox', 'offered' => 'embed-security',
                 'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
             ]],
        ];
    }
}

if (!function_exists('qs_admin_menu_offered')) {
    /**
     * Does this installation offer the page a deployer's switch governs? An entry with no switch is offered.
     * ⚠ Reach, not access: see consolePolicy.php.
     */
    function qs_admin_menu_offered(array $entry): bool {
        $switch = $entry['offered'] ?? null;
        if ($switch === null) return true;
        if ($switch === 'console') {
            require_once SECURE_FOLDER_PATH . '/src/functions/consolePolicy.php';
            return qs_console_allowed();
        }
        if ($switch === 'embed-security') {
            require_once SECURE_FOLDER_PATH . '/src/functions/embedSecurityPolicy.php';
            return qs_embed_security_shown();
        }
        return false;
    }
}

if (!function_exists('qs_admin_menu_drawn')) {
    /**
     * The menu as this installation draws it: every entry its deployer offers, in order, a group with none left
     * dropped. Each entry and group gains `simple` (a group is simple when one of its entries is). Nothing
     * here reads the account: the role filter runs in the browser, and the mode is a class on the page, so
     * the same menu serves both modes and a switch needs no reload.
     *
     * @return list<array<string, mixed>>
     */
    function qs_admin_menu_drawn(): array {
        $out = [];
        foreach (qs_admin_menu() as $item) {
            if (!isset($item['entries'])) {
                if (qs_admin_menu_offered($item)) $out[] = $item + ['simple' => false];
                continue;
            }
            $entries = [];
            foreach ($item['entries'] as $entry) {
                if (qs_admin_menu_offered($entry)) $entries[] = $entry + ['simple' => false];
            }
            if ($entries === []) continue;
            $item['entries'] = $entries;
            $item['simple'] = (bool) array_filter($entries, static fn($e) => $e['simple']);
            $out[] = $item;
        }
        return $out;
    }
}

if (!function_exists('qs_admin_menu_page_is_advanced')) {
    /**
     * Is $page one the advanced menu reaches and the simple one does not (Commands, History, API Endpoints, …)? Such a page,
     * opened in simple from its address or a link, says at its top that it lives in the Advanced menu (layout.php). A page in no
     * menu at all (My account, a 404) is not one, nor is a page its setup switch took off the installation.
     */
    function qs_admin_menu_page_is_advanced(string $page): bool {
        $inMenu = false;
        foreach (qs_admin_menu_pages() as $entry) {
            if ($entry['page'] !== $page) continue;
            if ($entry['simple']) return false;
            $inMenu = true;
        }
        return $inMenu;
    }
}

if (!function_exists('qs_admin_menu_pages')) {
    /**
     * The pages the menu reaches, in its order: every link and every group's entries, with what the role filter
     * reads. The chooser's two lists are these, simple and not, so they never name a page the menu does not.
     *
     * @return list<array{page:string, label:array, requires:string, simple:bool}>
     */
    function qs_admin_menu_pages(): array {
        $pages = [];
        foreach (qs_admin_menu_drawn() as $item) {
            foreach ($item['entries'] ?? [$item] as $entry) {
                $pages[] = ['page' => $entry['page'], 'label' => $entry['label'],
                            'requires' => (string) ($entry['requires'] ?? ''), 'simple' => $entry['simple']];
            }
        }
        return $pages;
    }
}
