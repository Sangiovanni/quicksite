<?php
/**
 * Admin Command Page
 * 
 * Either shows the list of all commands (if no specific command selected)
 * or shows the individual command form page.
 * 
 * @version 1.6.0
 */

$selectedCommand = $router->getCommand();
$categories = getCommandCategories();

// If a specific command is selected, show its page
if ($selectedCommand) {
    require_once __DIR__ . '/command-form.php';
    return;
}

// Otherwise show the command list
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('commands.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('commands.subtitle') ?></p>
</div>

<!-- Search -->
<div class="admin-search-bar">
    <svg class="admin-search-bar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/>
        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input 
        type="text" 
        id="command-search" 
        class="admin-input" 
        placeholder="<?= adminAttr(__admin('commands.searchPlaceholder')) ?>"
    >
</div>

<!-- Command Categories -->
<div class="admin-categories" id="command-list">
    <?php foreach ($categories as $categoryKey => $category): ?>
    <div class="admin-category" data-category="<?= adminAttr($categoryKey) ?>">
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
            <a href="<?= $router->url('command', $command) ?>" 
               class="admin-command-link" 
               data-command="<?= adminAttr($command) ?>">
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
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'history' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        default => '<circle cx="12" cy="12" r="10"/>'
    };
}
?>

<script>
// Command search functionality with permission-aware filtering
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('command-search');
    const categories = document.querySelectorAll('.admin-category');
    
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        let totalVisible = 0;
        
        categories.forEach(category => {
            const commands = category.querySelectorAll('.admin-command-link');
            let visibleCount = 0;
            
            commands.forEach(link => {
                const commandName = link.dataset.command.toLowerCase();
                const matches = !query || commandName.includes(query);
                const hasPermission = QuickSiteAdmin.hasPermission(link.dataset.command);
                
                // Reset classes
                link.classList.remove('admin-hidden-permission', 'admin-disabled-permission');
                
                if (!matches) {
                    link.style.display = 'none';
                } else if (!hasPermission) {
                    // Will decide visibility after counting
                    link.style.display = '';
                    link.classList.add('admin-hidden-permission');
                } else {
                    link.style.display = '';
                    visibleCount++;
                }
            });
            
            totalVisible += visibleCount;
            
            // Show/hide category based on matches
            category.style.display = visibleCount > 0 ? '' : 'none';
            
            // Update count
            const countEl = category.querySelector('.admin-category__count');
            if (countEl) {
                countEl.textContent = visibleCount;
            }
            
            // Open category if there's a search query and matches
            if (query && visibleCount > 0) {
                category.classList.add('admin-category--open');
            } else if (!query) {
                category.classList.remove('admin-category--open');
            }
        });
        
        // If search has ≤3 results, show hidden items as disabled
        if (query && totalVisible <= 3) {
            categories.forEach(category => {
                const hiddenCommands = category.querySelectorAll('.admin-command-link.admin-hidden-permission');
                hiddenCommands.forEach(link => {
                    const commandName = link.dataset.command.toLowerCase();
                    if (commandName.includes(query)) {
                        // Show as disabled instead of hidden
                        link.classList.remove('admin-hidden-permission');
                        link.classList.add('admin-disabled-permission');
                        link.style.display = '';
                    }
                });
                
                // Recalculate category visibility
                const visibleOrDisabled = category.querySelectorAll('.admin-command-link:not([style*="display: none"])');
                if (visibleOrDisabled.length > 0) {
                    category.style.display = '';
                    category.classList.add('admin-category--open');
                }
            });
        }
    });
    
    // Initial filter based on permissions (after QuickSiteAdmin loads permissions)
    setTimeout(() => {
        if (QuickSiteAdmin.permissions.loaded && !QuickSiteAdmin.permissions.isSuperAdmin) {
            QuickSiteAdmin.filterByPermissions();
        }
    }, 500);
});
</script>

<style>
.admin-search-bar {
    position: relative;
    margin-bottom: var(--space-xl);
}

.admin-search-bar__icon {
    position: absolute;
    left: var(--space-md);
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    color: var(--admin-text-light);
    pointer-events: none;
}

.admin-search-bar .admin-input {
    padding-left: calc(var(--space-md) + 28px);
}

.admin-categories {
    display: grid;
    gap: var(--space-sm);
}
</style>
