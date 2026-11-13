<?php
/**
 * Admin Panel Base Layout
 * 
 * Main template wrapper for all admin pages.
 * Uses the site's CSS variables but with darker admin theme.
 */

// qs_inline_script_json(), the encoder for a value in an inline script.
require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php';
require_once SECURE_FOLDER_PATH . '/src/functions/projectLanguage.php'; // qs_project_default_language

// Base URL (needed early for asset helper)
$baseUrl = rtrim(BASE_URL, '/');

// Asset version for cache-busting (bump on release or use filemtime for dev)
$assetVersion = '1.0.0';

// Helper to get versioned asset URL
$versionedAsset = function($path) use ($baseUrl, $assetVersion) {
    // In development, use filemtime for instant cache invalidation
    $fullPath = ADMIN_ASSET_ROOT . '/admin/assets' . $path;
    if (file_exists($fullPath)) {
        $version = filemtime($fullPath);
    } else {
        $version = $assetVersion;
    }
    return $baseUrl . '/admin/assets' . $path . '?v=' . $version;
};

// login + register + setup are the unauthenticated AUTH pages: minimal centered
// chrome, no nav/config/JS boot. Register and setup MUST be here — the full
// chrome flushes output before the page template runs, which would break their
// POST-success redirect (headers already sent); the slim auth chrome stays
// inside the output buffer, same as login's own POST redirect relies on.
$isLoginPage = in_array($router->getPage(), ['login', 'register', 'setup'], true);
$isPreviewPage = $router->getPage() === 'preview';
$currentPage = $router->getPage();

// Client transport wiring. The admin client must (a) know which
// commands are project-scoped (to prepend the '/management/p/<id>/' marker) and
// (b) know which project it is working with. Both come from the authoritative
// server source: the scope set from categories.php, and the EDITED project
// (getCurrentProject = the caller's own selected_project) — the same project the
// preview iframe + the dashboard use, so editor/preview/badge/dashboard all agree.
// Emitted into QUICKSITE_CONFIG below + used for the badge. currentProject is a UX
// default only — the server re-authorizes every call.
require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
$globalCommands = getGlobalCommands();
$currentProject = $isLoginPage ? null : $router->getCurrentProject();   // the project you EDIT (selected_project)

// The header picker lists the caller's memberships so they can switch what they edit.
// "Back to site" + the preview always target /p/<id>/ — every project is reached that way.
// A PRIVATE project's /p/<id>/ preview iframe is a plain browser navigation with
// no headers of its own; it authenticates on the session cookie, which the
// browser already sends because the panel and surface B share an origin and the
// cookie's path is '/'. Nothing extra is emitted here.
$myProjectIds = [];
$starredProjects = [];
// The account's mode (panel state, users.php `panel_mode`): null until it picks one. A display preference and
// nothing else: it decides which menu entries are drawn (and shows a line on a page of the advanced menu opened in
// simple); no permission and no command reads it.
$panelMode = null;
require_once SECURE_FOLDER_PATH . '/src/functions/projectIdentity.php'; // qs_project_label (the picker)
// Does THIS account see operator notices? (secure/management/config/operator.php,
// written at first run.) A DISPLAY PREFERENCE AND NOTHING ELSE: it gates
// whether the update banner renders, and no other decision anywhere reads it.
// The update check (/admin/api's update-check arm) answers any authenticated
// caller, so this neither grants nor withholds any capability — which is what
// keeps it from becoming an installation-wide principal, a role the authority
// model does not have.
// DEFAULT-ON-ABSENT: qs_operator_ids() reads a missing or malformed file as
// "nobody", never "everybody".
$isOperator = false;
if (!$isLoginPage) {
    require_once SECURE_FOLDER_PATH . '/src/functions/firstRun.php';
    $__pkAuth = qs_session_auth();
    if (!empty($__pkAuth['valid'])) {
        // The header's project list reads the account's users.php copy of its memberships (fast);
        // the dashboard lists every project from its own members.json (the authority). So
        // the dashboard's load repairs the copy first, at most once in
        // QS_MEMBERSHIP_REPAIR_TTL unless a membership changes, and the list drawn below
        // shows the repaired copy.
        if ($currentPage === 'dashboard') {
            $__pkRepair = qs_membership_repair_account((string)$__pkAuth['userId']);
            $__pkFresh = $__pkRepair['written'] ? (loadUsersConfig()['users'][$__pkAuth['userId']] ?? null) : null;
            if (is_array($__pkFresh)) {
                $__pkFresh['id'] = $__pkAuth['userId'];
                $__pkAuth['user'] = $__pkFresh;
            }
        }
        $myProjectIds = getUserProjectIds($__pkAuth['user']);
        // The picker shows each project as "Name #id", sorted by name, then id:
        // two projects may share a name, the id tells them apart.
        usort($myProjectIds, static function (string $a, string $b): int {
            return [mb_strtolower(qs_project_display_name($a), 'UTF-8'), $a] <=> [mb_strtolower(qs_project_display_name($b), 'UTF-8'), $b];
        });
        // The projects the account starred come first in every project list; the page
        // orders its lists (QuickSiteAdmin.orderProjectSelect) from this list.
        require_once SECURE_FOLDER_PATH . '/admin/functions/panelState.php';
        $starredProjects = qs_admin_starred_projects($__pkAuth['user']);
        $panelMode = qs_admin_panel_mode($__pkAuth['user']);
        $__pkUserId = (string)($__pkAuth['user']['id'] ?? ($__pkAuth['userId'] ?? ''));
        $isOperator = $__pkUserId !== '' && in_array($__pkUserId, qs_operator_ids(), true);
    }
}

// The write rate as it stands for this account (quota.php): every window with what
// is left of it, the batch runner's patience, and the commands that never count.
// The header shows it whenever a window is set; each counted command's answer
// updates it from then on. No window = the rate is off and nothing is shown.
$writeRatePanel = null;
if (!$isLoginPage && !empty($__pkUserId)) {
    require_once SECURE_FOLDER_PATH . '/src/functions/quota.php';
    $writeRatePanel = qs_write_rate_panel($__pkUserId);
}

// Get page title from translations or fallback to capitalized page name
$pageTitle = $lang->has('nav.' . $currentPage) 
    ? __admin('nav.' . $currentPage) 
    : ucfirst($currentPage);

// Get available admin languages and current language
$adminLang = AdminTranslation::getInstance();
$availableLangs = $adminLang->getAvailableLanguages();
$currentLang = $adminLang->getCurrentLanguage();

// Language display names
$langNames = [
    'en' => 'English',
    'fr' => 'Français',
    'es' => 'Español',
    'de' => 'Deutsch',
    'it' => 'Italiano',
    'pt' => 'Português',
    'nl' => 'Nederlands',
    'ja' => '日本語',
    'zh' => '中文',
    'ko' => '한국어'
];
?>
<!DOCTYPE html>
<html lang="<?= adminEscape($lang->getCurrentLanguage()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __admin('common.appName') ?> - <?= adminEscape($pageTitle) ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="64x64" href="<?= $baseUrl ?>/admin/assets/images/favicon.png">
    <link rel="icon" type="image/svg+xml" sizes="any" href="<?= $baseUrl ?>/admin/assets/images/logo.svg">
    
    <!-- Early language redirect from localStorage -->
    <script>
    (function() {
        var stored = localStorage.getItem('quicksite_admin_lang');
        // json_encode, not hand-written quotes: this is a JS string literal, and
        // the value ultimately comes from ?lang=. Its shape is gated now
        // (AdminTranslation::isValidLanguage), so nothing can break
        // out — this is the escaping that makes that a belt rather than the only
        // brace, and it is the correct escaper for the context either way.
        var current = <?= json_encode($currentLang) ?>;
        var url = new URL(window.location.href);
        // Only redirect if stored lang differs and we're not already setting it via URL
        if (stored && stored !== current && !url.searchParams.has('lang')) {
            url.searchParams.set('lang', stored);
            window.location.replace(url.toString());
        }
    })();
    </script>
    
    <!-- Admin-specific styles -->
    <link rel="stylesheet" href="<?= $versionedAsset('/admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/tag-examples.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/oauth-admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/storage-admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/privacy-admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/members-admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/builds-admin.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/searchable-select.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/bubble.css') ?>">
    <link rel="stylesheet" href="<?= $versionedAsset('/css/preview-ai-tools.css') ?>">
    <!-- The three views: every media query of the panel. Stays the last stylesheet. -->
    <link rel="stylesheet" href="<?= $versionedAsset('/css/responsive.css') ?>">
    <!-- Storage key registry — must load before any page script that references QuickSiteStorageKeys -->
    <script src="<?= $versionedAsset('/js/core/storage-keys.js') ?>"></script>
    <!-- Shared DOM factory — must load before any page script that references QSDom -->
    <script src="<?= $versionedAsset('/js/core/dom.js') ?>"></script>
    <!-- Reusable searchable combobox. Wraps a native <select> — page scripts construct QSSearchableSelect after locating their select element. -->
    <script src="<?= $versionedAsset('/js/core/searchable-select.js') ?>"></script>
    <!-- The panel's hover bubble: every title shows through it. Listens on the document; builds its element on first use. -->
    <script src="<?= $versionedAsset('/js/core/bubble.js') ?>"></script>
</head>
<?php
// The simple menu until the account picks advanced: an account that has not chosen sees the simple menu
// behind the chooser. The class only hides menu entries (panelMenu.php); it is no permission.
$panelSimple = !$isLoginPage && $panelMode !== 'advanced';
?>
<body class="admin-body<?= $isLoginPage ? ' admin-body--login' : '' ?><?= $panelSimple ? ' admin-body--simple' : '' ?>" data-page="<?= adminEscape($currentPage) ?>">
    
    <?php if (!$isLoginPage): ?>
    <!-- Admin Header -->
    <header class="admin-header">
        <div class="admin-header__brand">
            <a href="<?= $router->url('dashboard') ?>" class="admin-header__logo">
                <img class="admin-header__mark" src="<?= $baseUrl ?>/admin/assets/images/logo.svg" alt="" width="28" height="26">
                <img class="admin-header__wordmark" src="<?= $baseUrl ?>/admin/assets/images/quicksite.svg" alt="<?= __admin('common.appName') ?>" width="76" height="24">
            </a>
        </div>
        
        <?php
            // The menu: one definition (panelMenu.php), which the chooser's lists read too. Every entry its
            // deployer offers is drawn in both modes; simple hides the advanced ones by the page's class, so the
            // first frame is right and a switch needs no reload. The role filter runs once permissions load
            // (data-requires-command), and admin.js then draws a group with one entry left as that entry.
            require_once SECURE_FOLDER_PATH . '/admin/functions/panelMenu.php';
            $navMenu = qs_admin_menu_drawn();
            $navWords = static function (array $label): string {
                return $label[1] === null ? __admin($label[0]) : __admin($label[0], $label[1]);
            };
            $navIcon = static function (string $inner): string {
                return '<svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $inner . '</svg>';
            };
            $navBadge = static function (?string $id): string {
                return $id === null ? '' : '<span class="admin-nav__count-badge" id="' . adminAttr($id) . '" hidden>0</span>';
            };
            // One link of the menu, at the top level or inside a group's dropdown.
            $navLink = static function (array $entry) use ($router, $currentPage, $navWords, $navIcon, $navBadge): string {
                $active = in_array($currentPage, $entry['active'] ?? [$entry['page']], true);
                return '<a href="' . adminAttr($router->url($entry['page'])) . '" class="admin-nav__link' . ($active ? ' admin-nav__link--active' : '') . '"'
                    . (($entry['requires'] ?? '') !== '' ? ' data-requires-command="' . adminAttr($entry['requires']) . '"' : '')
                    . ($entry['simple'] ? '' : ' data-menu-advanced')
                    . '>' . $navIcon($entry['icon']) . '<span>' . $navWords($entry['label']) . '</span>' . $navBadge($entry['badge'] ?? null) . '</a>';
            };

            // A group's arrow, a button of its own beside the word, which stays a link to its page: a touch
            // and a key have no hover to open a group with. Its dropdown is admin-nav-menu-<group>.
            $navGroupArrow = static function (string $group, string $name): string {
                $label = __admin('nav.groupMenu', 'Menu:') . ' ' . $name;
                return '<button type="button" class="admin-nav__group-arrow-btn" aria-expanded="false"'
                    . ' aria-controls="admin-nav-menu-' . adminAttr($group) . '" aria-label="' . adminAttr($label) . '">'
                    . '<svg class="admin-nav__group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
                    . '<polyline points="6 9 12 15 18 9"/></svg></button>';
            };
        ?>
        <!-- The actions, on band one, and on a phone's row: there the write rate shows only what is left,
             the project as much of its name as the row leaves, Back to Site its icon. -->
        <div class="admin-header__actions">
            <?php
            // EDITED-project picker: which project this user is authoring right now
            // (= getCurrentProject = their per-user selected_project). Changing it writes
            // that pointer through /admin/state — see the handler at the bottom of this file.
            // Single-project users see a plain badge; multi-project users get the dropdown.
            $editingProject = $currentProject;
            ?>
            <?php if (!empty($writeRatePanel['windows'])): ?>
            <span id="admin-write-rate" class="admin-btn admin-btn--ghost admin-write-rate" tabindex="0" aria-live="polite">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 14l4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>
                </svg>
                <span class="admin-write-rate__figure"></span>
                <span class="admin-write-rate__left"></span>
            </span>
            <?php endif; ?>
            <?php if (!empty($myProjectIds) && count($myProjectIds) > 1): ?>
            <label class="admin-btn admin-btn--ghost admin-project-badge admin-project-picker" title="<?= __admin('common.activeProject', 'Active project') ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                <select id="admin-project-picker" aria-label="<?= __admin('common.activeProject', 'Active project') ?>" style="background:transparent;border:none;color:inherit;font:inherit;cursor:pointer;outline:none;padding:0 2px;max-width:16rem;">
                    <?php foreach ($myProjectIds as $pid): ?>
                    <option value="<?= adminEscape($pid) ?>"<?= $pid === $editingProject ? ' selected' : '' ?>><?= adminEscape(qs_project_label($pid)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php elseif ($editingProject): ?>
            <a href="<?= $router->url('dashboard') ?>" class="admin-btn admin-btn--ghost admin-project-badge" title="<?= __admin('common.activeProject', 'Active project') ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                <span><?= adminEscape(qs_project_label((string)$editingProject)) ?></span>
            </a>
            <?php endif; ?>
            <?php
            // "Back to site" opens the project you're EDITING at its own /p/<id>/ view,
            // which resolves that project's default language itself.
            //
            // Hidden outright when there is no edited project. It used to fall back
            // to the install base on the reasoning that "the web root is free, it belongs
            // to whatever the deployment put there" — but on a default deployment nothing
            // is there, so an account with no membership got Apache's own 403 in a new
            // tab. There is no site to go back to; the honest UI is no button.
            if ($editingProject):
                $siteUrl = $router->projectSiteBase() . '/';
            ?>
            <a href="<?= $siteUrl ?>" id="back-to-site-btn" class="admin-btn admin-btn--ghost admin-header__back" target="_blank"
               title="<?= adminAttr(__admin('common.backToSite')) ?>">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                <span><?= __admin('common.backToSite') ?></span>
            </a>
            <?php endif; ?>
        </div>

        <!-- The account menu. Your name and role open it. On a phone the row has no room for them, so its
             first line says them, copied from the badge when the badge is written. -->
        <div class="admin-account">
            <button type="button"
                    class="admin-user-badge<?= $currentPage === 'account' ? ' admin-user-badge--active' : '' ?>"
                    id="admin-user-badge"
                    aria-expanded="false"
                    aria-controls="admin-account-menu"
                    aria-label="<?= adminAttr(__admin('nav.accountMenu', 'Account menu')) ?>">
                <svg class="admin-user-badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span class="admin-user-badge__name" id="admin-user-name">...</span>
                <span class="admin-user-badge__role" id="admin-user-role"></span>
                <svg class="admin-nav__group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="admin-account__menu" id="admin-account-menu">
                <div class="admin-account__who">
                    <span class="admin-user-badge__name" id="admin-account-name"></span>
                    <span class="admin-user-badge__role" id="admin-account-role"></span>
                </div>
                <a href="<?= adminAttr($router->url('account')) ?>"
                   class="admin-nav__link<?= $currentPage === 'account' ? ' admin-nav__link--active' : '' ?>"
                   title="<?= adminAttr(__admin('account.navHint', 'Your account settings')) ?>">
                    <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span><?= __admin('nav.account') ?></span>
                </a>

                <!-- The panel's language, the current one marked; the script below answers any [data-lang] click. -->
                <div class="admin-account__lang">
                    <span class="admin-account__lang-label"><?= __admin('footer.language') ?>:</span>
                    <?php foreach ($availableLangs as $langCode): ?>
                        <?php if ($langCode === $currentLang): ?>
                            <span class="admin-account__lang-btn admin-account__lang-btn--active"><?= $langNames[$langCode] ?? strtoupper($langCode) ?></span>
                        <?php else: ?>
                            <button type="button" class="admin-account__lang-btn" data-lang="<?= $langCode ?>"><?= $langNames[$langCode] ?? strtoupper($langCode) ?></button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- The menu the account chose, the current one marked (none before the first pick): simple shows the
                     pages a beginner needs, advanced every page. It hides menu entries and grants or refuses
                     nothing. js/core/panel-mode.js answers a click, at once and saved. -->
                <div class="admin-account__mode" id="admin-account-mode" role="group" aria-labelledby="admin-account-mode-label">
                    <span class="admin-account__mode-label" id="admin-account-mode-label"><?= __admin('nav.mode', 'Mode') ?>:</span>
                    <?php foreach (QS_PANEL_MODES as $modeOption): ?>
                    <button type="button" class="admin-account__mode-btn<?= $modeOption === $panelMode ? ' admin-account__mode-btn--active' : '' ?>"
                            data-panel-mode="<?= adminAttr($modeOption) ?>" aria-pressed="<?= $modeOption === $panelMode ? 'true' : 'false' ?>"><?= __admin('nav.mode' . ucfirst($modeOption)) ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- Both logouts POST and carry the per-session token: signing
                     somebody out is a state change, and as GET links any foreign
                     page could spend one <img> tag doing it. The router refuses
                     anything that is not that pair. -->
                <form method="POST" action="<?= adminAttr($router->url('logout')) ?>" class="admin-logout-form">
                    <input type="hidden" name="session_token" value="<?= adminAttr((string)$router->getToken()) ?>">
                    <button type="submit" class="admin-nav__link admin-account__action">
                        <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        <span><?= __admin('nav.logout') ?></span>
                    </button>
                </form>

                <!-- Ends every session of this account, not just this browser. -->
                <form method="POST" action="<?= adminAttr($router->url('logout')) ?>" class="admin-logout-form">
                    <input type="hidden" name="session_token" value="<?= adminAttr((string)$router->getToken()) ?>">
                    <input type="hidden" name="everywhere" value="1">
                    <button type="submit" class="admin-nav__link admin-account__action"
                            title="<?= adminAttr(__admin('nav.logoutEverywhereHint')) ?>">
                        <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                            <circle cx="18" cy="6" r="3"/>
                        </svg>
                        <span><?= __admin('nav.logoutEverywhere') ?></span>
                    </button>
                </form>
            </div>
        </div>

        <!-- A phone's way to the panel below: CSS shows it at the phone's width. The dot says a count
             waits inside the closed panel (js/core/members-badge.js). -->
        <button type="button" class="admin-header__menu-button" id="admin-header-menu-button"
                aria-expanded="false" aria-controls="admin-header-panel">
            <svg class="admin-header__menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
            <span class="admin-header__menu-label"><?= __admin('nav.menu', 'Menu') ?></span>
            <span class="admin-header__menu-dot" id="admin-header-menu-dot" hidden>
                <span class="admin-header__menu-label"><?= __admin('nav.menuWaiting', 'Something waits for you') ?></span>
            </span>
        </button>

        <!-- The menu, one set of elements for the three views. display: contents above the phone's width, so
             the menu is the header's second band; at the phone's width it is the panel the button opens.
             Nothing moves it, and no script reads a width. -->
        <div class="admin-header__panel" id="admin-header-panel">
            <nav class="admin-nav">
                <?php foreach ($navMenu as $navItem): ?>
                <?php if (!isset($navItem['entries'])): ?>
                <?= $navLink($navItem) ?>
                <?php else: ?>
                <div class="admin-nav__group<?= in_array($currentPage, $navItem['active'], true) ? ' admin-nav__group--has-active' : '' ?>" data-nav-group="<?= adminAttr($navItem['group']) ?>"<?= $navItem['simple'] ? '' : ' data-menu-advanced' ?>>
                    <a href="<?= adminAttr($router->url($navItem['page'])) ?>" class="admin-nav__group-toggle"><?= $navIcon($navItem['icon']) ?><span><?= $navWords($navItem['label']) ?></span><?= $navBadge($navItem['badge'] ?? null) ?></a>
                    <?= $navGroupArrow($navItem['group'], $navWords($navItem['label'])) ?>
                    <div class="admin-nav__group-dropdown" id="admin-nav-menu-<?= adminAttr($navItem['group']) ?>">
                        <?php foreach ($navItem['entries'] as $navEntry): ?>
                        <?= $navLink($navEntry) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>
    <?php endif; ?>

    <?php if ($isOperator): ?>
    <!-- Operator update notice. Rendered EMPTY and filled by
         js/core/update-notice.js, which is loaded only on this branch: checking
         for an update is a live network call to GitHub, and doing it inline
         would make every panel page wait on a third party. An account that is
         not an operator never receives the container OR the script. -->
    <div class="admin-security-warning admin-security-warning--info" id="admin-update-notice" hidden></div>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="admin-main<?= $isLoginPage ? ' admin-main--centered' : '' ?>">
        <?php if (!$isLoginPage && function_exists('qs_admin_menu_page_is_advanced') && qs_admin_menu_page_is_advanced($currentPage)): ?>
        <!-- A page the simple menu does not show, opened from its address or a link: in simple (the page's class) it says where
             it lives, with the Mode row's own switch. Drawn in either mode, shown in simple alone, so a switch needs no reload. It
             opens the page as ever: the mode is no lock. -->
        <div class="admin-alert admin-alert--info admin-mode-notice" id="admin-mode-notice">
            <span class="admin-mode-notice__text"><?= __admin('panelMode.notice.text', 'This page is in the Advanced menu: you reached it from its address or a link.') ?></span>
            <button type="button" class="admin-btn admin-btn--outline" data-panel-mode="advanced"><?= __admin('panelMode.notice.switch', 'Switch to Advanced') ?></button>
        </div>
        <?php endif; ?>
        <?php require $templatePath; ?>
    </main>
    
    <?php if (!$isLoginPage && !$isPreviewPage): ?>
    <!-- Admin Footer -->
    <footer class="admin-footer">
        <div class="admin-footer__content">
            <?php
            // The installation's version, from the VERSION file every release bumps: the same reader as the update notice and a build.
            require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
            $qsPanelVersion = qs_local_version();
            ?>
            <p><?php if ($qsPanelVersion !== null): ?><?= __admin('footer.version', ['version' => adminEscape($qsPanelVersion)]) ?> &bull; <?php endif; ?><?= __admin('footer.powered') ?></p>
        </div>
    </footer>
    <?php endif; ?>
    
    <!-- Admin Configuration (injected from PHP) -->
    <script>
        window.QUICKSITE_CONFIG = {
            apiBase: '<?= $router->getApiUrl() ?>',
            adminBase: '<?= $router->getBaseUrl() ?>',
            baseUrl: '<?= rtrim(BASE_URL, '/') ?>',
            // Where the EDITED project's own public/ is served: its surface-B view
            // '/p/<id>'. Any panel code that links to PROJECT content (assets, style,
            // uploads) must build on THIS, not baseUrl — baseUrl is the install base,
            // which serves no project's files.
            projectContentBase: '<?= adminEscape($router->getProjectContentBase()) ?>',
            publicSpace: '<?= defined('PUBLIC_FOLDER_SPACE') ? PUBLIC_FOLDER_SPACE : '' ?>',
            // The PER-SESSION TOKEN. Not a credential on its own — it grants
            // nothing without the session cookie it belongs to; sending it back
            // as `Authorization: Bearer` is how a management-API call proves it
            // came from something that could READ this page, which is what stops
            // a foreign site driving the API through the visitor's browser.
            // It MUST be in the config from creation: api.js seeds it and
            // admin.js fires loadPermissions() at parse time, both BEFORE any
            // later script block runs.
            token: '<?= adminEscape((string)$router->getToken()) ?>',
            // Project transport. currentProject = the EDITED project
            // (selected_project), the same one preview + the dashboard use; globalCommands =
            // the categories.php scope==='global' set. The client prepends the
            // '/management/p/<currentProject>/' marker for any command NOT in
            // globalCommands. Both are UX/plumbing — the server re-authorizes each call.
            currentProject: <?= json_encode($currentProject) ?>,
            globalCommands: <?= json_encode($globalCommands) ?>,
            // The account's starred projects (panel state): first in each project list.
            starredProjects: <?= qs_inline_script_json($starredProjects) ?>,
            // The write rate on load (null on the auth pages); api.js keeps it current.
            writeRate: <?= qs_inline_script_json($writeRatePanel) ?>,
            // Operator notices — a DISPLAY flag, never an authorization input.
            // No version is emitted here: the notice gets both versions from the
            // update-check arm's own response, and putting the engine version into
            // every account's page would let any account fingerprint the install.
            isOperator: <?= $isOperator ? 'true' : 'false' ?>,
            // The account's mode (panel state): 'simple', 'advanced', or null until its first pick, when
            // js/core/panel-mode.js opens the chooser. A display preference, never an authorization input.
            panelMode: <?= json_encode($panelMode) ?>,
            // The edited project's own value is project DATA, so it is written
            // the way every data value in an inline script is.
            defaultLang: <?= qs_inline_script_json(qs_project_default_language()) ?>,
            multilingual: <?= (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) ? 'true' : 'false' ?>,
            translations: {
                common: {
                    success: '<?= __adminJs('common.status.success') ?>',
                    error: '<?= __adminJs('common.status.error') ?>',
                    loading: '<?= __adminJs('common.loading') ?>',
                    noResults: '<?= __adminJs('common.noResults') ?>',
                    execute: '<?= __adminJs('common.execute') ?>',
                    copy: '<?= __adminJs('common.copy') ?>',
                    close: '<?= __adminJs('common.close') ?>',
                    validate: '<?= __adminJs('common.validate') ?>',
                    // The confirm dialog and the clipboard toast in
                    // js/core/utils.js are panel-wide chrome, so their default
                    // labels ship on every page like admin.js's do.
                    cancel: '<?= __adminJs('common.cancel') ?>',
                    confirm: '<?= __adminJs('common.confirm') ?>',
                    confirmAction: '<?= __adminJs('common.confirmAction') ?>',
                    copyFailed: '<?= __adminJs('common.copyFailed') ?>',
                    // The searchable select's words (core/searchable-select.js),
                    // for every list in the panel that is one.
                    selectPlaceholder: '<?= __adminJs('common.selectPlaceholder') ?>',
                    search: '<?= __adminJs('common.search') ?>',
                    noMatchesFor: '<?= __adminJs('common.noMatchesFor') ?>',
                    createFromSearch: '<?= __adminJs('common.createFromSearch') ?>',
                    // The star toggle (the dashboard's first line, My Memberships).
                    starred: '<?= __adminJs('common.starred') ?>',
                    starProject: '<?= __adminJs('common.starProject') ?>',
                    unstarProject: '<?= __adminJs('common.unstarProject') ?>',
                    starFailed: '<?= __adminJs('common.starFailed') ?>'
                },
                // A wait or a limit in time, in the words __adminDuration() uses on
                // the pages the server writes: QuickSiteAdmin.durationText() reads them.
                duration: {
                    second: '<?= __adminJs('common.duration.second') ?>',
                    seconds: '<?= __adminJs('common.duration.seconds') ?>',
                    minute: '<?= __adminJs('common.duration.minute') ?>',
                    minutes: '<?= __adminJs('common.duration.minutes') ?>',
                    hour: '<?= __adminJs('common.duration.hour') ?>',
                    hours: '<?= __adminJs('common.duration.hours') ?>',
                    day: '<?= __adminJs('common.duration.day') ?>',
                    days: '<?= __adminJs('common.duration.days') ?>'
                },
                // The write rate's strings: the header's figure and its hover, and
                // the refusal api.js says in the panel's language.
                writeRate: {
                    refused: '<?= __adminJs('writeRate.refused') ?>',
                    figure: '<?= __adminJs('writeRate.figure') ?>',
                    titleHead: '<?= __adminJs('writeRate.titleHead') ?>',
                    titleWindow: '<?= __adminJs('writeRate.titleWindow') ?>',
                    titleWindowFull: '<?= __adminJs('writeRate.titleWindowFull') ?>',
                    period_day: '<?= __adminJs('writeRate.period_day') ?>',
                    period_days: '<?= __adminJs('writeRate.period_days') ?>',
                    period_hour: '<?= __adminJs('writeRate.period_hour') ?>',
                    period_hours: '<?= __adminJs('writeRate.period_hours') ?>',
                    period_minute: '<?= __adminJs('writeRate.period_minute') ?>',
                    period_minutes: '<?= __adminJs('writeRate.period_minutes') ?>',
                    period_seconds: '<?= __adminJs('writeRate.period_seconds') ?>',
                    waitSeconds: '<?= __adminJs('writeRate.waitSeconds') ?>',
                    waitMinutes: '<?= __adminJs('writeRate.waitMinutes') ?>'
                },
                dashboard: {
                    projects: {
                        // Each with its English, as these two questions never read a key's path in a language that lacks the word.
                        copyId: <?= json_encode(__admin('nav.copyProjectId', "Copy this project's id")) ?>,
                        idCopied: <?= json_encode(__admin('nav.projectIdCopied', 'Project id copied')) ?>,
                        deleteTitle: <?= json_encode(__admin('dashboard.projects.deleteTitle', 'Delete Project')) ?>,
                        deleteBtn: <?= json_encode(__admin('dashboard.projects.deleteBtn', 'Delete Project')) ?>,
                        deleteConfirmNamed: <?= json_encode(__admin('dashboard.projects.deleteConfirmNamed', 'Delete ":name" permanently? Its pages, translations, assets and backups are removed and cannot be recovered.')) ?>,
                        deleteConfirmEditing: <?= json_encode(__admin('dashboard.projects.deleteConfirmEditing', 'This is the project you are editing.')) ?>,
                        switch: <?= json_encode(__admin('dashboard.projects.switch', 'Switch Project')) ?>,
                        switchBtn: <?= json_encode(__admin('dashboard.projects.switchBtn', 'Switch')) ?>,
                        switchConfirmNamed: <?= json_encode(__admin('dashboard.projects.switchConfirmNamed', 'Switch to ":name"? Every page of the panel then works on this project.')) ?>,
                        restoring: '<?= __adminJs('dashboard.projects.restoring') ?>',
                        restoreBtn: '<?= __adminJs('dashboard.projects.restoreBtn') ?>',
                        restore_success: '<?= __adminJs('dashboard.projects.restore_success') ?>',
                        restore_failed: '<?= __adminJs('dashboard.projects.restore_failed') ?>',
                        restore_incomplete: '<?= __adminJs('dashboard.projects.restore_incomplete') ?>',
                        backup_created: '<?= __adminJs('dashboard.projects.backup_created') ?>',
                        backup_failed: '<?= __adminJs('dashboard.projects.backup_failed') ?>',
                        backup_incomplete: '<?= __adminJs('dashboard.projects.backup_incomplete') ?>'
                    },
                    columns: {
                        command: '<?= __adminJs('dashboard.history.columns.command') ?>',
                        status: '<?= __adminJs('dashboard.history.columns.status') ?>',
                        duration: '<?= __adminJs('dashboard.history.columns.duration') ?>',
                        time: '<?= __adminJs('dashboard.history.columns.time') ?>'
                    },
                    // Strings the owner-space panel builds in JS (the panel's
                    // static labels are rendered server-side by __admin()).
                    storage: {
                        ownerProjects: '<?= __adminJs('dashboard.storage.ownerProjects') ?>',
                        ownerOneProject: '<?= __adminJs('dashboard.storage.ownerOneProject') ?>',
                        cachedHint: '<?= __adminJs('dashboard.storage.cachedHint') ?>',
                        backups: '<?= __adminJs('dashboard.storage.backups') ?>',
                        exports: '<?= __adminJs('dashboard.storage.exports') ?>',
                        showAll: '<?= __adminJs('dashboard.storage.showAll') ?>',
                        showTop: '<?= __adminJs('dashboard.storage.showTop') ?>',
                        noMatch: '<?= __adminJs('dashboard.storage.noMatch') ?>'
                    }
                },
                history: {
                    noHistoryFiltered: '<?= __adminJs('history.noHistoryFiltered') ?>',
                    pagination: {
                        info: '<?= __adminJs('history.pagination.info') ?>'
                    },
                    errors: {
                        loadFailed: '<?= __adminJs('history.errors.loadFailed') ?>'
                    }
                },
                commandForm: {
                    errors: {
                        docNotFound: '<?= __adminJs('commandForm.errors.docNotFound') ?>',
                        docLoadFailed: '<?= __adminJs('commandForm.errors.docLoadFailed') ?>'
                    }
                },
                // admin.js is loaded on EVERY page, so the strings it builds in
                // JS are emitted for every page too: the command executor's
                // button states and destructive-command prompt, the response
                // panel, the JSON editor toolbar, and the '?' shortcuts dialog.
                commands: {
                    execute: '<?= __adminJs('commands.execute') ?>',
                    uploading: '<?= __adminJs('commands.uploading') ?>',
                    executing: '<?= __adminJs('commands.executing') ?>',
                    responseStatus: '<?= __adminJs('commands.responseStatus') ?>',
                    errorLoadingOptions: '<?= __adminJs('commands.errorLoadingOptions') ?>',
                    executedMsg: '<?= __adminJs('commands.executedMsg') ?>',
                    notPermittedHint: '<?= __adminJs('commands.notPermittedHint') ?>',
                    failedMsg: '<?= __adminJs('commands.failedMsg') ?>',
                    errorPrefix: '<?= __adminJs('commands.errorPrefix') ?>',
                    downloadedPrefix: '<?= __adminJs('commands.downloadedPrefix') ?>',
                    responseCopied: '<?= __adminJs('commands.responseCopied') ?>',
                    selectPlaceholder: '<?= __adminJs('commands.selectPlaceholder') ?>',
                    confirmDestructive: {
                        title: '<?= __adminJs('commands.confirmDestructive.title') ?>',
                        message: '<?= __adminJs('commands.confirmDestructive.message') ?>'
                    },
                    jsonEditor: {
                        format: '<?= __adminJs('commands.jsonEditor.format') ?>',
                        valid: '<?= __adminJs('commands.jsonEditor.valid') ?>',
                        invalid: '<?= __adminJs('commands.jsonEditor.invalid') ?>',
                        invalidShort: '<?= __adminJs('commands.jsonEditor.invalidShort') ?>',
                        empty: '<?= __adminJs('commands.jsonEditor.empty') ?>'
                    }
                },
                shortcuts: {
                    title: '<?= __adminJs('shortcuts.title') ?>',
                    navGroup: '<?= __adminJs('shortcuts.navGroup') ?>',
                    actionsGroup: '<?= __adminJs('shortcuts.actionsGroup') ?>',
                    focusSearch: '<?= __adminJs('shortcuts.focusSearch') ?>',
                    showHelp: '<?= __adminJs('shortcuts.showHelp') ?>',
                    closeModal: '<?= __adminJs('shortcuts.closeModal') ?>'
                },
                // The mode's words built in JS (js/core/panel-mode.js): the bubble after the first pick, and a
                // save that failed.
                panelMode: {
                    bubble: '<?= __adminJs('panelMode.bubble') ?>',
                    bubbleClose: '<?= __adminJs('panelMode.bubbleClose') ?>',
                    saveFailed: '<?= __adminJs('panelMode.saveFailed') ?>'
                },
                nav: {
                    dashboard: '<?= __adminJs('nav.dashboard') ?>',
                    commands: '<?= __adminJs('nav.commands') ?>',
                    history: '<?= __adminJs('nav.history') ?>',
                    settings: '<?= __adminJs('nav.settings') ?>',
                    notLoggedIn: '<?= __adminJs('nav.notLoggedIn') ?>',
                    unknownUser: '<?= __adminJs('nav.unknownUser') ?>',
                    noProject: '<?= __adminJs('nav.noProject') ?>'
                },
                <?php if ($isOperator): ?>
                // Only an operator's page loads update-notice.js, so only an
                // operator's page carries its strings.
                updateNotice: {
                    label: '<?= __adminJs('updateNotice.label') ?>',
                    available: '<?= __adminJs('updateNotice.available') ?>',
                    availableSuffix: '<?= __adminJs('updateNotice.availableSuffix') ?>',
                    youHave: '<?= __adminJs('updateNotice.youHave') ?>',
                    howTo: '<?= __adminJs('updateNotice.howTo') ?>',
                    releaseNotes: '<?= __adminJs('updateNotice.releaseNotes') ?>',
                    dismiss: '<?= __adminJs('updateNotice.dismiss') ?>'
                },
                <?php endif; ?>
                settings: {
                    toast: {
                        preferencesSaved: '<?= __adminJs('settings.toast.preferencesSaved') ?>',
                        defaultLangUpdated: '<?= __adminJs('settings.toast.defaultLangUpdated') ?>'
                    },
                    errors: {
                        sysInfoFailed: '<?= __adminJs('settings.errors.sysInfoFailed') ?>',
                        routesFailed: '<?= __adminJs('settings.errors.routesFailed') ?>',
                        languagesFailed: '<?= __adminJs('settings.errors.languagesFailed') ?>',
                        defaultLangFailed: '<?= __adminJs('settings.errors.defaultLangFailed') ?>'
                    }
                }
            },
            adminLang: <?= json_encode($currentLang) ?>
        };
        
        // Language Switcher
        (function() {
            const STORAGE_KEY = 'quicksite_admin_lang';
            
            // Store current language in localStorage for persistence
            localStorage.setItem(STORAGE_KEY, QUICKSITE_CONFIG.adminLang);
            
            // Handle language switch buttons
            document.addEventListener('click', function(e) {
                const langBtn = e.target.closest('[data-lang]');
                if (langBtn) {
                    const newLang = langBtn.dataset.lang;
                    localStorage.setItem(STORAGE_KEY, newLang);
                    // Reload with lang parameter
                    const url = new URL(window.location.href);
                    url.searchParams.set('lang', newLang);
                    window.location.href = url.toString();
                }
            });
        })();
    </script>
    
    <!-- Core JavaScript Modules -->
    <script src="<?= $versionedAsset('/js/core/api.js') ?>"></script>
    <script src="<?= $versionedAsset('/js/core/utils.js') ?>"></script>
    <?php if (!$isLoginPage): ?>
    <!-- Membership counts + nav badge — async, derived from existing reads -->
    <script src="<?= $versionedAsset('/js/core/members-badge.js') ?>"></script>
    <?php endif; ?>

    <!-- Admin JavaScript -->
    <script src="<?= $versionedAsset('/admin.js') ?>"></script>

    <?php if (!$isLoginPage): ?>
    <?php if ($panelMode === null) {
        // Asked once, of a signed-in account that has not chosen: never on sign-in, register or first run.
        require SECURE_FOLDER_PATH . '/admin/templates/partials/mode-chooser.php';
    } ?>
    <!-- The mode: the account menu's Mode row and, for an account that has not chosen, the chooser. After admin.js. -->
    <script src="<?= $versionedAsset('/js/core/panel-mode.js') ?>"></script>
    <?php endif; ?>

    <?php if ($isOperator): ?>
    <!-- Operator update notice — emitted ONLY for an account named in
         operator.php, so a non-operator never downloads it at all. After
         js/core/api.js, which is where QuickSiteAPI.fetchHelper comes from. -->
    <script src="<?= $versionedAsset('/js/core/update-notice.js') ?>"></script>
    <?php endif; ?>
    
    <?php if (!$isLoginPage): ?>
    <script>
        window.QUICKSITE_CONFIG.apiUrl = '<?= $router->getApiUrl() ?>';
    </script>

    <script>
    (function () {
        // The header project picker switches which project you EDIT; the
        // dashboard's project switch calls the same thing. Every project is edited
        // at its own surface B (/p/<id>/). setSelectedProject
        // RESOLVES with a {ok, status, data} object (it does NOT reject on non-2xx) —
        // branch on ok. It is panel state, not a command, so it goes to /admin/state.
        var picker = document.getElementById('admin-project-picker');
        if (picker) {
            // The project edited when the page loaded: a switch that fails goes back to it.
            var editing = picker.value;
            // Starred projects first, as in every project list, behind the panel's
            // searchable dropdown (the native select stays as its data store). The
            // header sets no star: the dashboard and My Memberships do, and a star set
            // on the page re-orders this list too.
            QuickSiteAdmin.orderProjectSelect(picker);
            // The badge may cut a long name: the list says it whole on its first line.
            picker._qsPicker = new QSSearchableSelect(picker, {
                minWidth: 288,
                currentOnTop: true,
                currentCopy: {
                    title: <?= json_encode(__admin('nav.copyProjectId', "Copy this project's id")) ?>,
                    done: <?= json_encode(__admin('nav.projectIdCopied', 'Project id copied')) ?>
                }
            });
            document.addEventListener('quicksite:stars-changed', function () {
                QuickSiteAdmin.orderProjectSelect(picker);
            });
            var backToEditing = function () {
                picker.disabled = false;
                picker.value = editing;
                picker._qsPicker.refresh();
            };
            picker.addEventListener('change', function () {
                var project = picker.value;
                if (!project || project === editing) { return; }
                picker.disabled = true;
                picker._qsPicker.refresh();
                QuickSiteAdmin.setSelectedProject(project)
                    .then(function (res) {
                        if (res && res.ok) {
                            // Cache-busted navigation (NOT reload()) — Firefox can restore the
                            // preview iframe from bfcache on reload(), leaving the badge on the
                            // new project but the iframe (and thus the editor's DOM) on the OLD
                            // one → edits target the wrong project. A fresh URL forces a clean
                            // load of the whole page + iframe. Matches the dashboard switch.
                            window.location.href = window.location.pathname + '?t=' + Date.now();
                        } else {
                            backToEditing();
                            QuickSiteUtils.showToast('<?= __adminJs('nav.switchProjectFailed') ?>' + ((res && res.data && res.data.message) || '<?= __adminJs('common.unknownError') ?>'), 'error');
                        }
                    })
                    .catch(function (err) {
                        backToEditing();
                        QuickSiteUtils.showToast('<?= __adminJs('nav.switchProjectFailed') ?>' + ((err && err.message) || err), 'error');
                    });
            });
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
