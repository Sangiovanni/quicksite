<?php
/**
 * Admin Sitemap Page
 * 
 * Visual route tree, reachability graph, and route management.
 * 
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');
?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/sitemap.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/sitemap.js') ?>"></script>

<div class="admin-page-header">
    <div class="admin-page-header__row">
        <div>
            <h1 class="admin-page-header__title"><?= __admin('sitemapPage.title') ?></h1>
            <p class="admin-page-header__subtitle"><?= __admin('sitemapPage.subtitle') ?></p>
        </div>
        <button type="button" class="admin-btn admin-btn--primary" id="add-root-route-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <?= __admin('sitemapPage.addRootRoute') ?>
        </button>
    </div>
</div>

<!-- Route Tree -->
<section class="admin-section">
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-tree-container">
            <div class="admin-loading"><?= __admin('common.loading') ?></div>
        </div>
    </div>
</section>

<!-- Reachability Graph -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('sitemapPage.reachability') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-reachability-container">
            <div class="admin-loading"><?= __admin('common.loading') ?></div>
        </div>
    </div>
</section>

<!-- Sitemap.txt Generator -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('sitemapPage.sitemapFile') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-generator-container">
            <div class="sitemap-generator">
                <p class="sitemap-generator__desc"><?= __admin('sitemapPage.sitemapFileDesc') ?></p>
                <div class="sitemap-generator__controls">
                    <label class="sitemap-generator__label"><?= __admin('sitemapPage.baseUrl') ?></label>
                    <div class="sitemap-generator__row">
                        <input type="url" class="admin-input sitemap-generator__input" id="sitemap-base-url" 
                               value="<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>" 
                               placeholder="https://example.com" />
                        <button type="button" class="admin-btn admin-btn--secondary" id="sitemap-generate-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                            </svg>
                            <?= __admin('sitemapPage.generatePreview') ?>
                        </button>
                    </div>
                </div>
                <div class="sitemap-generator__preview" id="sitemap-preview" style="display:none;">
                    <div class="sitemap-generator__preview-header">
                        <span class="sitemap-generator__preview-title">sitemap.txt</span>
                        <span class="sitemap-generator__preview-count" id="sitemap-url-count"></span>
                    </div>
                    <div class="sitemap-generator__url-list" id="sitemap-url-list"></div>
                    <div class="sitemap-generator__custom-section">
                        <label class="sitemap-generator__label"><?= __admin('sitemapPage.customUrls') ?></label>
                        <p class="sitemap-generator__hint"><?= __admin('sitemapPage.customUrlsHint') ?></p>
                        <textarea class="admin-input sitemap-generator__textarea" id="sitemap-custom-urls" rows="3"
                                  placeholder="https://example.com/blog&#10;https://example.com/external-page"></textarea>
                    </div>
                    <div class="sitemap-generator__actions">
                        <button type="button" class="admin-btn admin-btn--primary" id="sitemap-save-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <?= __admin('sitemapPage.saveSitemap') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
