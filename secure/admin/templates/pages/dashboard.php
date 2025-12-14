<?php
/**
 * Admin Dashboard Page
 * 
 * Main admin panel landing page after login.
 * Shows site stats, quick actions, and command categories.
 * 
 * @version 1.6.0
 */

$categories = getCommandCategories();
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('dashboard.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('dashboard.subtitle') ?></p>
</div>

<!-- Quick Stats (loaded via AJAX) -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.stats.title') ?></h2>
    <div class="admin-grid admin-grid--cols-4" id="admin-stats">
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-routes">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.routes') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-pages">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.pages') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-components">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.components') ?></div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value" id="stat-languages">-</div>
            <div class="admin-stat__label"><?= __admin('dashboard.stats.languages') ?></div>
        </div>
    </div>
</section>

<!-- Command Categories -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.categories.title') ?></h2>
    <div class="admin-categories">
        <?php foreach ($categories as $categoryKey => $category): ?>
        <div class="admin-category">
            <div class="admin-category__header">
                <svg class="admin-category__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <?php echo getCategoryIcon($category['icon']); ?>
                </svg>
                <h3 class="admin-category__title"><?= adminEscape($category['label']) ?></h3>
                <span class="admin-category__count"><?= count($category['commands']) ?></span>
                <svg class="admin-category__toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>
            <div class="admin-category__commands">
                <?php foreach ($category['commands'] as $command): ?>
                <a href="<?= $router->url('command', $command) ?>" class="admin-command-link">
                    <span class="admin-command-link__name"><?= adminEscape($command) ?></span>
                    <svg class="admin-command-link__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Recent Command History -->
<section class="admin-section">
    <div class="admin-section__header">
        <h2 class="admin-section__title"><?= __admin('dashboard.recentCommands') ?></h2>
        <a href="<?= $router->url('history') ?>" class="admin-btn admin-btn--ghost">
            <?= __admin('dashboard.viewAllHistory') ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </a>
    </div>
    <div class="admin-card">
        <div class="admin-card__body" id="recent-commands">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
    </div>
</section>

<?php
/**
 * Get SVG icon paths for categories
 */
function getCategoryIcon(string $icon): string {
    return match($icon) {
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'route' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12"/>',
        'structure' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'translate' => '<path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2v3"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/>',
        'language' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"/>',
        'css' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'animation' => '<polygon points="5 3 19 12 5 21 5 3"/><line x1="12" y1="12" x2="12" y2="12"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'package' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'history' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        default => '<circle cx="12" cy="12" r="10"/>'
    };
}
?>

<script>
// Load dashboard data
document.addEventListener('DOMContentLoaded', async function() {
    await loadDashboardStats();
    await loadRecentCommands();
});

async function loadDashboardStats() {
    try {
        // Get routes count
        const routesResult = await QuickSiteAdmin.apiRequest('getRoutes');
        if (routesResult.ok) {
            document.getElementById('stat-routes').textContent = routesResult.data.data?.count || 0;
        }
        
        // Get pages count
        const pagesResult = await QuickSiteAdmin.apiRequest('listPages');
        if (pagesResult.ok) {
            document.getElementById('stat-pages').textContent = pagesResult.data.data?.count || 0;
        }
        
        // Get components count
        const componentsResult = await QuickSiteAdmin.apiRequest('listComponents');
        if (componentsResult.ok) {
            document.getElementById('stat-components').textContent = componentsResult.data.data?.count || 0;
        }
        
        // Get languages count
        const langResult = await QuickSiteAdmin.apiRequest('getLangList');
        if (langResult.ok) {
            document.getElementById('stat-languages').textContent = langResult.data.data?.count || 1;
        }
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
}

async function loadRecentCommands() {
    const container = document.getElementById('recent-commands');
    
    try {
        const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, []);
        
        if (result.ok && result.data.data?.entries?.length > 0) {
            const entries = result.data.data.entries.slice(0, 5); // Last 5 commands
            
            let html = '<table class="admin-table"><thead><tr>';
            html += '<th>Command</th><th>Status</th><th>Duration</th><th>Time</th>';
            html += '</tr></thead><tbody>';
            
            entries.forEach(entry => {
                // Handle both old format (status: "success") and new format (http_status: 200)
                const httpStatus = entry.result?.http_status || entry.result?.status;
                const isSuccess = typeof httpStatus === 'number' 
                    ? httpStatus >= 200 && httpStatus < 300
                    : httpStatus === 'success';
                const statusClass = isSuccess ? 'badge--success' : 'badge--error';
                const statusText = isSuccess ? 'Success' : 'Error';
                
                html += `<tr>
                    <td><code>${QuickSiteAdmin.escapeHtml(entry.command)}</code></td>
                    <td><span class="badge ${statusClass}">${statusText}</span></td>
                    <td>${entry.duration_ms}ms</td>
                    <td>${new Date(entry.timestamp).toLocaleString()}</td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        } else {
            container.innerHTML = `
                <div class="admin-empty" style="padding: var(--space-lg);">
                    <p><?= __admin('dashboard.noHistory') ?></p>
                </div>
            `;
        }
    } catch (error) {
        container.innerHTML = `
            <div class="admin-empty" style="padding: var(--space-lg);">
                <p><?= __admin('dashboard.noHistory') ?></p>
            </div>
        `;
    }
}
</script>

<style>
.admin-section {
    margin-bottom: var(--space-2xl);
}

.admin-section__title {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
    margin: 0 0 var(--space-lg) 0;
    color: var(--admin-text);
}

.admin-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-lg);
}

.admin-section__header .admin-section__title {
    margin: 0;
}

.admin-categories {
    display: grid;
    gap: var(--space-sm);
}
</style>
