<?php
/**
 * Admin Panel Base Layout
 * 
 * Main template wrapper for all admin pages.
 * Uses the site's CSS variables but with darker admin theme.
 * 
 * @version 1.6.0
 */

$isLoginPage = $router->getPage() === 'login';
$currentPage = $router->getPage();

// Get page title from translations or fallback to capitalized page name
$pageTitle = $lang->has('nav.' . $currentPage) 
    ? __admin('nav.' . $currentPage) 
    : ucfirst($currentPage);

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="<?= adminEscape($lang->getCurrentLanguage()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __admin('common.appName') ?> - <?= adminEscape($pageTitle) ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/assets/images/favicon.png">
    
    <!-- Base site styles (for CSS variables) -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/style/style.css">
    
    <!-- Admin-specific styles -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/admin/assets/admin.css">
</head>
<body class="admin-body<?= $isLoginPage ? ' admin-body--login' : '' ?>">
    
    <?php if (!$isLoginPage): ?>
    <!-- Admin Header -->
    <header class="admin-header">
        <div class="admin-header__brand">
            <a href="<?= $router->url('dashboard') ?>" class="admin-header__logo">
                <svg class="admin-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/>
                    <path d="M2 12l10 5 10-5"/>
                </svg>
                <span><?= __admin('common.appName') ?></span>
            </a>
        </div>
        
        <nav class="admin-nav">
            <a href="<?= $router->url('dashboard') ?>" 
               class="admin-nav__link<?= $currentPage === 'dashboard' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/>
                    <rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span><?= __admin('nav.dashboard') ?></span>
            </a>
            
            <a href="<?= $router->url('command') ?>" 
               class="admin-nav__link<?= $currentPage === 'command' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="16 18 22 12 16 6"/>
                    <polyline points="8 6 2 12 8 18"/>
                </svg>
                <span><?= __admin('nav.commands') ?></span>
            </a>
            
            <a href="<?= $router->url('history') ?>" 
               class="admin-nav__link<?= $currentPage === 'history' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <span><?= __admin('nav.history') ?></span>
            </a>
            
            <a href="<?= $router->url('favorites') ?>" 
               class="admin-nav__link<?= $currentPage === 'favorites' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <span><?= __admin('nav.favorites') ?></span>
            </a>
            
            <a href="<?= $router->url('structure') ?>" 
               class="admin-nav__link<?= $currentPage === 'structure' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                    <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
                <span><?= __admin('nav.structure') ?></span>
            </a>
            
            <a href="<?= $router->url('batch') ?>" 
               class="admin-nav__link<?= $currentPage === 'batch' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="8" y1="6" x2="21" y2="6"/>
                    <line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6" x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/>
                    <line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
                <span><?= __admin('nav.batch') ?></span>
            </a>
            
            <a href="<?= $router->url('docs') ?>" 
               class="admin-nav__link<?= $currentPage === 'docs' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                <span><?= __admin('nav.docs') ?></span>
            </a>
            
            <a href="<?= $router->url('ai') ?>" 
               class="admin-nav__link<?= $currentPage === 'ai' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2z"/>
                    <circle cx="7.5" cy="14.5" r="1.5"/>
                    <circle cx="16.5" cy="14.5" r="1.5"/>
                </svg>
                <span><?= __admin('nav.ai') ?></span>
            </a>
            
            <a href="<?= $router->url('settings') ?>" 
               class="admin-nav__link<?= $currentPage === 'settings' ? ' admin-nav__link--active' : '' ?>">
                <svg class="admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <span><?= __admin('nav.settings') ?></span>
            </a>
        </nav>
        
        <div class="admin-header__actions">
            <?php 
            // Build the correct site URL - include default language if multilingual
            $siteUrl = rtrim($baseUrl, '/') . '/';
            if (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) {
                $siteUrl .= (CONFIG['LANGUAGE_DEFAULT'] ?? 'en') . '/';
            }
            ?>
            <a href="<?= $siteUrl ?>" class="admin-btn admin-btn--ghost" target="_blank">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                <span><?= __admin('common.backToSite') ?></span>
            </a>
            
            <a href="<?= $router->url('logout') ?>" class="admin-btn admin-btn--outline">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                <span><?= __admin('nav.logout') ?></span>
            </a>
        </div>
    </header>
    <?php endif; ?>
    
    <!-- Main Content -->
    <main class="admin-main<?= $isLoginPage ? ' admin-main--centered' : '' ?>">
        <?php require $templatePath; ?>
    </main>
    
    <?php if (!$isLoginPage): ?>
    <!-- Admin Footer -->
    <footer class="admin-footer">
        <p><?= __admin('footer.version', ['version' => '1.6.0']) ?> &bull; <?= __admin('footer.powered') ?></p>
    </footer>
    <?php endif; ?>
    
    <!-- Admin Configuration (injected from PHP) -->
    <script>
        window.QUICKSITE_CONFIG = {
            apiBase: '<?= $router->getApiUrl() ?>',
            adminBase: '<?= $router->getBaseUrl() ?>',
            baseUrl: '<?= rtrim(BASE_URL, '/') ?>',
            publicSpace: '<?= defined('PUBLIC_FOLDER_SPACE') ? PUBLIC_FOLDER_SPACE : '' ?>',
            defaultLang: '<?= CONFIG['LANGUAGE_DEFAULT'] ?? 'en' ?>',
            multilingual: <?= (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) ? 'true' : 'false' ?>
        };
    </script>
    
    <!-- Admin JavaScript -->
    <script src="<?= $baseUrl ?>/admin/assets/admin.js"></script>
</body>
</html>
