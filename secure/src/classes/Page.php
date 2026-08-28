<?php

class Page {
    private $title;
    private $content;
    private $lang;
    private $showMenu;
    private $showFooter;
    private $pageEventsScript;
    /** @var array This route's state stores, as data — the <script> tag is the handoff's job. */
    private $stateStores;

    public function __construct($title, $content, $lang, $showMenu = true, $showFooter = true, $pageEventsScript = '', $stateStores = []) {
        $this->title = $title;
        $this->content = $content;
        $this->lang = $lang;
        $this->showMenu = $showMenu;
        $this->showFooter = $showFooter;
        $this->pageEventsScript = $pageEventsScript;
        // Tolerate the old pre-rendered-tag form so a page compiled by an
        // earlier build cannot fatal here; it simply carries no stores.
        $this->stateStores = is_array($stateStores) ? $stateStores : [];
    }

    public function render() {
        $title = $this->title;
        $content = $this->content;
        $lang = $this->lang;
        $showMenu = $this->showMenu;
        $showFooter = $this->showFooter;
        $pageEventsScript = $this->pageEventsScript;
        $stateStores = $this->stateStores;
        $spacePrefix = PUBLIC_FOLDER_SPACE !== '' ? PUBLIC_FOLDER_SPACE . '/' : '';
        $stylePath = (defined('PUBLIC_CONTENT_PATH') ? PUBLIC_CONTENT_PATH : dirname(__DIR__, 3) . '/' . (defined('PUBLIC_FOLDER_NAME') ? PUBLIC_FOLDER_NAME : 'public')) . '/style/style.css';
        $cssVersion = file_exists($stylePath) ? filemtime($stylePath) : time();

        // ── Theme resolution ──────────────────────────────────────────────
        $themeEnabled  = defined('THEME_MODE_ENABLED') && THEME_MODE_ENABLED;
        $themeDefault  = defined('THEME_DEFAULT') ? THEME_DEFAULT : 'light';
        $toggleEnabled = defined('THEME_USER_TOGGLE_ENABLED') && THEME_USER_TOGGLE_ENABLED;
        $projectKey    = defined('PROJECT_NAME') ? PROJECT_NAME : 'default';

        $themeAttr = '';
        if ($themeEnabled) {
            $themeAttr = ' data-theme="' . (($themeDefault === 'dark') ? 'dark' : 'light') . '"';
        }

        $themeScript = '';
        if ($themeEnabled && ($toggleEnabled || $themeDefault === 'system')) {
            $js = '(function(){try{';
            if ($toggleEnabled) {
                $js .= 'var s=localStorage.getItem("qs-theme-' . $projectKey . '");';
                $js .= 'if(s==="dark"||s==="light"){document.documentElement.setAttribute("data-theme",s);return;}';
            }
            if ($themeDefault === 'system') {
                $js .= 'if(window.matchMedia&&window.matchMedia("(prefers-color-scheme:dark)").matches){document.documentElement.setAttribute("data-theme","dark");}';
            }
            $js .= '}catch(e){}})();';
            $themeScript = '<script>' . $js . '</script>';
        }
        // ─────────────────────────────────────────────────────────────────
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"<?= $themeAttr ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <?php
    // Favicon: prefer CONFIG['FAVICON_PATH'] (project-configurable).
    // Accepts: an absolute URL (https?:// or data:) emitted as-is,
    // or a path starting with `/` (treated as relative to the
    // site root; respects PUBLIC_FOLDER_SPACE via $spacePrefix).
    // Default falls back to the project's conventional assets path
    // (the old hardcoded path here was buggy — missing `images/`
    // — which caused fresh pages to 404 on /assets/favicon.png).
    $faviconPath = (defined('CONFIG') && isset(CONFIG['FAVICON_PATH']) && CONFIG['FAVICON_PATH'] !== '')
        ? CONFIG['FAVICON_PATH']
        : '/assets/images/favicon.png';
    if (preg_match('#^(https?:)?//|^data:#i', $faviconPath)) {
        $faviconHref = $faviconPath;
    } else {
        // Treat as root-relative; honour the optional PUBLIC_FOLDER_SPACE.
        $faviconHref = '/' . $spacePrefix . ltrim($faviconPath, '/');
    }
    ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <link rel="stylesheet" href="/<?= $spacePrefix ?>style/style.css?v=<?= $cssVersion ?>">
    <?= $themeScript ?>
</head>
<body>
    <?php if ($showMenu): ?>
    <header>
        <?php require_once PROJECT_PATH . '/templates/menu.php'; ?>
    </header>
    <?php endif; ?>
    <main>
        <?= $content ?>
    </main>
    <?php if ($showFooter): ?>
    <footer>
        <?php require_once PROJECT_PATH . '/templates/footer.php'; ?>
    </footer>
    <?php endif; ?>
    <?php
    // The consent banner + popup, compiled at build time and site-wide (not
    // per-route like the menu and footer). Hidden by default; qs.js reveals
    // them when the project's consent layer is on and the visitor has not
    // answered yet. Emitted before the handoff so the markup exists by the time
    // the runtime looks for it.
    foreach (['consent-banner', 'consent-popup'] as $__consentPart) {
        $__consentFilePart = PROJECT_PATH . '/templates/' . $__consentPart . '.php';
        if (is_file($__consentFilePart)) {
            require $__consentFilePart;
        }
    }
    ?>
    <?php
    // ── The runtime handoff ───────────────────────────────────────────────
    // Every <script> the server hands the browser runtime — route schema,
    // storage namespace, qs.js, consent map, theme wiring, API config, enums,
    // this route's state stores, and what the server-side resolver already
    // fetched — in the one order they have to be in.
    //
    // Emitted through the SHARED writer, which the live /p/<projectId>/ render
    // also uses. A built page used to emit its own shorter version of this run
    // and silently lost the consent map and both resolver blocks.
    require_once SECURE_FOLDER_PATH . '/src/functions/runtimeHandoff.php';
    require_once SECURE_FOLDER_PATH . '/src/functions/resolverRegistry.php';
    require_once SECURE_FOLDER_PATH . '/src/functions/apiRegistry.php';

    // Consent, PRECOMPUTED at build time. The live site derives this payload by
    // walking the storage registry through the authoring helpers; a build ships
    // the answer instead, because it only changes when the author rebuilds.
    $__consentPayload = null;
    $__consentFile = PROJECT_PATH . '/data/consent-runtime.json';
    if (is_file($__consentFile)) {
        $__decoded = json_decode((string) @file_get_contents($__consentFile), true);
        if (is_array($__decoded) && !empty($__decoded['enabled'])) {
            $__consentPayload = $__decoded;
        }
    }

    $__routePath = '';
    if (class_exists('TrimParameters')) {
        $__tp = new TrimParameters();
        $__routePath = $__tp->routePath();
    }

    echo qs_runtime_handoff([
        'base'               => '/' . $spacePrefix,
        'contentPath'        => defined('PUBLIC_CONTENT_PATH') ? PUBLIC_CONTENT_PATH : '',
        'projectKey'         => $projectKey,
        'themeEnabled'       => $themeEnabled,
        'themeToggleEnabled' => $toggleEnabled,
        'consentPayload'     => $__consentPayload,
        // NOT precomputed like consent above: these are per-LANGUAGE, and a
        // built site serves every language its project declares from the one
        // set of files. Resolved per request, from the registry + translation
        // files the build carries.
        'countStrings'       => qs_api_count_strings(PROJECT_PATH),
        'stateStores'        => $stateStores,
        'resolverConfigs'    => $__routePath !== '' ? qs_resolvers_for_route($__routePath) : [],
        'resolvedVars'       => qs_get_resolved_vars(),
        'pageEventsScript'   => $pageEventsScript,
    ]);
    ?></body>
</html>
        <?php
    }
}