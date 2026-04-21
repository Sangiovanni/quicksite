<?php
/**
 * Admin Optimization Tools Page
 *
 * Dedicated section for CSS and project optimization tools.
 * First tool: CSS Refiner — client-side JS analysis, admin-native UI.
 *
 * @version 1.0.0
 */

$baseUrl = rtrim(BASE_URL, '/');

// CSS Refiner JS lib files (loaded order matters)
$libBase = $baseUrl . '/admin/assets/js/lib/css-refiner';
$libPath  = PUBLIC_CONTENT_PATH . '/admin/assets/js/lib/css-refiner';

$libFiles = [
    'constants.js',         // CSSRefiner.CONST — must load first
    'css-parser.js',
    'utils.js',
    'ui/components.js',     // CSSRefiner.UI.Components.el — needed by diff-view
    'analyzers/empty-rules.js',
    'analyzers/color-normalize.js',
    'analyzers/duplicates.js',
    'analyzers/media-queries.js',
    'analyzers/fuzzy-values.js',
    'analyzers/near-duplicates.js',
    'analyzers/design-tokens.js',
    'ui/diff-view.js',
];

$libReady = file_exists($libPath . '/css-parser.js');
?>

<?php if ($libReady): ?>
    <!-- Bootstrap: provide CSSRefiner.t stub (analyzer description strings are passthrough) -->
    <script>
    window.CSSRefiner = window.CSSRefiner || {};
    CSSRefiner.t = function(key) { return key; };
    </script>
    <?php foreach ($libFiles as $file): ?>
        <?php $fullPath = $libPath . '/' . $file; ?>
        <?php if (file_exists($fullPath)): ?>
            <script src="<?= $libBase . '/' . $file ?>?v=<?= filemtime($fullPath) ?>"></script>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<script src="<?= $baseUrl ?>/admin/assets/js/pages/optimize.js?v=<?= filemtime(PUBLIC_CONTENT_PATH . '/admin/assets/js/pages/optimize.js') ?>"></script>

<div class="optimize-page" data-lib-ready="<?= $libReady ? 'true' : 'false' ?>">

    <!-- Page Header -->
    <div class="admin-page-header">
        <h1 class="admin-page-header__title"><?= __admin('optimize.title') ?></h1>
        <p class="admin-page-header__subtitle"><?= __admin('optimize.subtitle') ?></p>
    </div>

    <div class="optimize-layout">

        <!-- Tool Sidebar -->
        <aside class="optimize-sidebar admin-card">
            <div class="admin-card__header">
                <h2 class="admin-card__title"><?= __admin('optimize.tools') ?></h2>
            </div>
            <nav class="optimize-tool-nav">
                <button type="button" class="optimize-tool-nav__item optimize-tool-nav__item--active" data-tool="css-refiner">
                    <svg class="optimize-tool-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    <span><?= __admin('optimize.cssRefiner.title') ?></span>
                </button>
                <!-- Future tools inserted here -->
            </nav>
        </aside>

        <!-- Main Panel -->
        <main class="optimize-main">

            <!-- CSS Refiner Panel -->
            <section class="optimize-tool-panel admin-card" id="optimize-panel-css-refiner">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <?= __admin('optimize.cssRefiner.title') ?>
                    </h2>
                    <p class="admin-card__subtitle"><?= __admin('optimize.cssRefiner.subtitle') ?></p>
                </div>

                <?php if (!$libReady): ?>
                <!-- Lib not yet copied notice -->
                <div class="admin-card__body">
                    <div class="optimize-setup-notice">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <p><?= __admin('optimize.cssRefiner.libNotReady') ?></p>
                    </div>
                </div>
                <?php else: ?>
                <!-- Toolbar -->
                <div class="admin-card__body">
                    <div class="optimize-toolbar">
                        <button type="button" class="admin-btn admin-btn--primary" id="optimize-btn-auto-refine">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                            </svg>
                            <?= __admin('optimize.cssRefiner.autoRefine') ?>
                        </button>
                        <button type="button" class="admin-btn admin-btn--secondary" id="optimize-btn-analyze">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                            <?= __admin('optimize.cssRefiner.analyze') ?>
                        </button>
                        <button type="button" class="admin-btn admin-btn--ghost" id="optimize-btn-reset" style="display:none">
                            <?= __admin('optimize.cssRefiner.reset') ?>
                        </button>
                        <span class="optimize-toolbar__status" id="optimize-status"></span>
                    </div>

                    <!-- Results area -->
                    <div id="optimize-results" class="optimize-results" style="display:none">
                        <!-- Populated by optimize.js -->
                    </div>

                    <!-- Empty state (before first analysis) -->
                    <div id="optimize-empty" class="optimize-empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <p><?= __admin('optimize.cssRefiner.emptyState') ?></p>
                    </div>

                    <!-- Apply bar (shown when suggestions are checked) -->
                    <div id="optimize-apply-bar" class="optimize-apply-bar" style="display:none">
                        <span id="optimize-selected-count"></span>
                        <button type="button" class="admin-btn admin-btn--primary" id="optimize-btn-apply-selected">
                            <?= __admin('optimize.cssRefiner.applySelected') ?>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <!-- Auto-Refine Confirmation Modal -->
    <div class="admin-modal-overlay" id="optimize-auto-refine-modal" style="display:none">
        <div class="admin-modal">
            <div class="admin-modal__header">
                <h3 class="admin-modal__title"><?= __admin('optimize.cssRefiner.autoRefineModal.title') ?></h3>
                <button type="button" class="admin-modal__close" id="optimize-modal-close">&times;</button>
            </div>
            <div class="admin-modal__body" id="optimize-modal-body">
                <!-- Summary list populated by JS -->
            </div>
            <div class="admin-modal__footer">
                <p class="admin-modal__note"><?= __admin('optimize.cssRefiner.autoRefineModal.backupNote') ?></p>
                <div class="admin-modal__actions">
                    <button type="button" class="admin-btn admin-btn--secondary" id="optimize-modal-cancel">
                        <?= __admin('common.cancel') ?>
                    </button>
                    <button type="button" class="admin-btn admin-btn--primary" id="optimize-modal-confirm">
                        <!-- Label set by JS with count -->
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
