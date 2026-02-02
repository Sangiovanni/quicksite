<?php
/**
 * Global Miniplayer Partial
 * 
 * Floating preview window that persists across admin pages.
 * Include this in layout.php for global miniplayer functionality.
 * 
 * @version 1.0.0
 */

// Get site URL for iframe
$miniplayerSiteUrl = rtrim(BASE_URL, '/') . '/';
if (CONFIG['MULTILINGUAL_SUPPORT'] ?? false) {
    $miniplayerDefaultLang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
    $miniplayerSiteUrl .= $miniplayerDefaultLang . '/';
}
$miniplayerSiteUrl .= '?_editor=1';
?>

<!-- Global Miniplayer Container -->
<div class="global-miniplayer" id="global-miniplayer">
    <!-- Header bar (drag handle) -->
    <div class="global-miniplayer__header">
        <span class="global-miniplayer__title"><?= __admin('preview.title') ?></span>
        <div class="global-miniplayer__controls">
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-reload" title="<?= __admin('preview.reload') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </button>
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-goto" title="<?= __admin('preview.expand') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 3 21 3 21 9"/>
                    <polyline points="9 21 3 21 3 15"/>
                    <line x1="21" y1="3" x2="14" y2="10"/>
                    <line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
            </button>
            <button type="button" class="global-miniplayer__btn" id="global-miniplayer-close" title="<?= __admin('common.close') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Iframe container -->
    <div class="global-miniplayer__content">
        <iframe 
            id="global-miniplayer-iframe"
            class="global-miniplayer__iframe"
            src="about:blank"
            data-src="<?= $miniplayerSiteUrl ?>"
            title="Website Preview"
        ></iframe>
        
        <!-- Loading overlay -->
        <div class="global-miniplayer__loading" id="global-miniplayer-loading">
            <div class="global-miniplayer__spinner"></div>
        </div>
    </div>
    
    <!-- Resize handle indicator -->
    <div class="global-miniplayer__resize-handle"></div>
</div>

<!-- Miniplayer JavaScript -->
<script src="<?= $baseUrl ?>/admin/assets/js/components/miniplayer.js?v=<?= filemtime(PUBLIC_FOLDER_ROOT . '/admin/assets/js/components/miniplayer.js') ?>"></script>
