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

<script>
// The words sitemap.js shows (`sitemapPage` in the panel's translations).
window.QS_SITEMAP_I18N = <?= qs_inline_script_json(AdminTranslation::getInstance()->getRaw('sitemapPage') ?: new stdClass()) ?>;
</script>
<script src="<?= $baseUrl ?>/admin/assets/js/pages/sitemap.js?v=<?= filemtime(ADMIN_ASSET_ROOT . '/admin/assets/js/pages/sitemap.js') ?>"></script>

<?php include __DIR__ . '/sitemap-edit-title.php'; ?>
<?php include __DIR__ . '/sitemap-resolver.php'; ?>
<?php include __DIR__ . '/sitemap-resolver-list.php'; ?>

<div class="admin-page-header">
    <div class="admin-page-header__row">
        <div>
            <h1 class="admin-page-header__title">
                <svg class="admin-page-header__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                <?= __admin('sitemapPage.title') ?>
            </h1>
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

<!-- Sitemap File: the links to select (shown when the page opens), the custom URLs, Generate, then the file written -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('sitemapPage.sitemapFile') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-generator-container">
            <div class="sitemap-generator">
                <p class="sitemap-generator__desc"><?= __admin('sitemapPage.sitemapFileDesc') ?></p>
                <div class="sitemap-generator__controls">
                    <label class="sitemap-generator__label" for="sitemap-base-url"><?= __admin('sitemapPage.baseUrl') ?></label>
                    <div class="sitemap-generator__row">
                        <input type="url" class="admin-input sitemap-generator__input" id="sitemap-base-url"
                               value="<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>"
                               placeholder="https://example.com" />
                    </div>
                </div>
                <div class="sitemap-generator__preview" id="sitemap-links" hidden>
                    <div class="sitemap-generator__preview-header">
                        <span class="sitemap-generator__preview-title"><?= __admin('sitemapPage.selectLinks') ?></span>
                        <span class="sitemap-generator__preview-count" id="sitemap-pages-kept"></span>
                    </div>
                    <p class="sitemap-generator__hint sitemap-generator__list-hint"><?= __admin('sitemapPage.selectLinksHint') ?></p>
                    <div class="sitemap-generator__url-list" id="sitemap-url-list"></div>
                    <div class="sitemap-generator__custom-section">
                        <label class="sitemap-generator__label" for="sitemap-custom-urls"><?= __admin('sitemapPage.customUrls') ?></label>
                        <p class="sitemap-generator__hint"><?= __admin('sitemapPage.customUrlsHint') ?></p>
                        <textarea class="admin-input sitemap-generator__textarea" id="sitemap-custom-urls" rows="3"
                                  placeholder="https://example.com/blog&#10;https://example.com/external-page"></textarea>
                    </div>
                    <div class="sitemap-generator__actions">
                        <button type="button" class="admin-btn admin-btn--primary" id="sitemap-generate-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <?= __admin('sitemapPage.generateSitemap') ?>
                        </button>
                    </div>
                </div>
                <div class="sitemap-generator__preview" id="sitemap-file" hidden>
                    <div class="sitemap-generator__preview-header">
                        <span class="sitemap-generator__preview-title"><?= __admin('sitemapPage.generatedFile') ?></span>
                        <span class="sitemap-generator__preview-count" id="sitemap-file-count"></span>
                    </div>
                    <pre class="sitemap-generator__preview-content" id="sitemap-file-text"></pre>
                    <div class="sitemap-generator__actions">
                        <button type="button" class="admin-btn admin-btn--secondary" id="sitemap-file-copy"
                                data-copied="<?= adminEscape(__admin('common.copied')) ?>">
                            <?= __admin('common.copy') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
