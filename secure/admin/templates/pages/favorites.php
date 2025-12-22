<?php
/**
 * Admin Favorites Page
 * 
 * Shows bookmarked/favorite commands for quick access.
 * 
 * @version 1.6.0
 */

require_once SECURE_FOLDER_PATH . '/admin/functions/AdminHelper.php';
$categories = getCommandCategories();
$allCommands = [];
foreach ($categories as $cat) {
    foreach ($cat['commands'] as $cmd) {
        $allCommands[$cmd] = $cat['label'];
    }
}
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('favorites.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('favorites.subtitle') ?></p>
</div>

<!-- Quick Access Actions -->
<div class="admin-card">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            <?= __admin('favorites.quickActions') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-quick-actions">
            <a href="<?= $router->url('command') ?>/build" class="admin-quick-action">
                <div class="admin-quick-action__icon admin-quick-action__icon--primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.buildSite') ?></span>
            </a>
            
            <a href="<?= $router->url('command') ?>/getRoutes" class="admin-quick-action">
                <div class="admin-quick-action__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.viewRoutes') ?></span>
            </a>
            
            <a href="<?= $router->url('command') ?>/listAssets" class="admin-quick-action">
                <div class="admin-quick-action__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.listAssets') ?></span>
            </a>
            
            <a href="<?= $router->url('command') ?>/getTranslations" class="admin-quick-action">
                <div class="admin-quick-action__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 8l6 6"/>
                        <path d="M4 14l6-6 2-3"/>
                        <path d="M2 5h12"/>
                        <path d="M7 2h1"/>
                        <path d="M22 22l-5-10-5 10"/>
                        <path d="M14 18h6"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.translations') ?></span>
            </a>
            
            <a href="<?= $router->url('command') ?>/getStyles" class="admin-quick-action">
                <div class="admin-quick-action__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="13.5" cy="6.5" r=".5"/>
                        <circle cx="17.5" cy="10.5" r=".5"/>
                        <circle cx="8.5" cy="7.5" r=".5"/>
                        <circle cx="6.5" cy="12.5" r=".5"/>
                        <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.viewStyles') ?></span>
            </a>
            
            <a href="<?= $router->url('structure') ?>" class="admin-quick-action">
                <div class="admin-quick-action__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <span class="admin-quick-action__label"><?= __admin('favorites.quickAction.structure') ?></span>
            </a>
        </div>
    </div>
</div>

<!-- Favorite Commands -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
            <?= __admin('favorites.yourFavorites') ?>
        </h2>
        <button type="button" class="admin-btn admin-btn--small admin-btn--secondary" onclick="clearAllFavorites()">
            <?= __admin('common.clearAll') ?>
        </button>
    </div>
    <div class="admin-card__body">
        <div id="favorites-list" class="admin-loading">
            <span class="admin-spinner"></span>
            <?= __admin('favorites.loading') ?>
        </div>
    </div>
</div>

<!-- All Commands for Adding -->
<div class="admin-card" style="margin-top: var(--space-lg);">
    <div class="admin-card__header">
        <h2 class="admin-card__title">
            <svg class="admin-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <?= __admin('favorites.addFavorites') ?>
        </h2>
    </div>
    <div class="admin-card__body">
        <div class="admin-form-group">
            <input type="text" id="command-search" class="admin-input" 
                   placeholder="<?= __admin('favorites.searchPlaceholder') ?>">
        </div>
        <div id="command-suggestions" class="admin-command-suggestions"></div>
    </div>
</div>

<script>
const ALL_COMMANDS = <?= json_encode($allCommands) ?>;
const COMMAND_BASE_URL = '<?= $router->url('command') ?>';
const TRANSLATIONS = {
    empty: {
        title: '<?= __adminJs('favorites.empty.title') ?>',
        message: '<?= __adminJs('favorites.empty.message') ?>'
    },
    noMatches: '<?= __adminJs('favorites.noMatches') ?>'
};

document.addEventListener('DOMContentLoaded', function() {
    loadFavorites();
    initCommandSearch();
});

function getFavorites() {
    return JSON.parse(localStorage.getItem('quicksite_admin_favorites') || '[]');
}

function saveFavorites(favorites) {
    localStorage.setItem('quicksite_admin_favorites', JSON.stringify(favorites));
}

function loadFavorites() {
    const container = document.getElementById('favorites-list');
    const favorites = getFavorites();
    
    if (favorites.length === 0) {
        container.innerHTML = `
            <div class="admin-empty" style="padding: var(--space-lg);">
                <svg class="admin-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <h3 class="admin-empty__title">${TRANSLATIONS.empty.title}</h3>
                <p class="admin-empty__text">${TRANSLATIONS.empty.message}</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="admin-favorites-grid">';
    favorites.forEach(cmd => {
        const category = ALL_COMMANDS[cmd] || 'Unknown';
        html += `
            <div class="admin-favorite-card">
                <a href="${COMMAND_BASE_URL}/${cmd}" class="admin-favorite-card__link">
                    <code>${cmd}</code>
                    <span class="admin-favorite-card__category">${category}</span>
                </a>
                <button type="button" class="admin-favorite-card__remove" onclick="removeFavorite('${cmd}')" title="Remove">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        `;
    });
    html += '</div>';
    
    container.innerHTML = html;
}

function addFavorite(command) {
    const favorites = getFavorites();
    if (!favorites.includes(command)) {
        favorites.push(command);
        saveFavorites(favorites);
        loadFavorites();
        QuickSiteAdmin.showToast(`Added "${command}" to favorites`, 'success');
    }
}

function removeFavorite(command) {
    const favorites = getFavorites();
    const index = favorites.indexOf(command);
    if (index > -1) {
        favorites.splice(index, 1);
        saveFavorites(favorites);
        loadFavorites();
        QuickSiteAdmin.showToast(`Removed "${command}" from favorites`, 'info');
    }
}

function clearAllFavorites() {
    QuickSiteAdmin.confirm('Are you sure you want to clear all favorites?', {
        title: 'Clear Favorites',
        confirmText: 'Clear All',
        confirmClass: 'danger'
    }).then(confirmed => {
        if (confirmed) {
            saveFavorites([]);
            loadFavorites();
            QuickSiteAdmin.showToast('All favorites cleared', 'success');
        }
    });
}

function initCommandSearch() {
    const input = document.getElementById('command-search');
    const suggestions = document.getElementById('command-suggestions');
    
    input.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        
        if (query.length < 2) {
            suggestions.innerHTML = '';
            return;
        }
        
        const favorites = getFavorites();
        const matches = Object.keys(ALL_COMMANDS).filter(cmd => 
            cmd.toLowerCase().includes(query) && !favorites.includes(cmd)
        ).slice(0, 10);
        
        if (matches.length === 0) {
            suggestions.innerHTML = `<p class="admin-text-muted">${TRANSLATIONS.noMatches}</p>`;
            return;
        }
        
        let html = '';
        matches.forEach(cmd => {
            html += `
                <div class="admin-suggestion" onclick="addFavorite('${cmd}')">
                    <code>${cmd}</code>
                    <span class="admin-suggestion__category">${ALL_COMMANDS[cmd]}</span>
                    <span class="admin-suggestion__action">+ Add</span>
                </div>
            `;
        });
        
        suggestions.innerHTML = html;
    });
}
</script>

<style>
.admin-quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--space-md);
}

.admin-quick-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-lg);
    background: var(--admin-bg-tertiary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--admin-text);
    transition: all var(--transition-fast);
}

.admin-quick-action:hover {
    border-color: var(--admin-accent);
    transform: translateY(-2px);
}

.admin-quick-action__icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--admin-bg);
    border-radius: var(--radius-md);
    color: var(--admin-text-muted);
}

.admin-quick-action__icon svg {
    width: 24px;
    height: 24px;
}

.admin-quick-action:hover .admin-quick-action__icon {
    color: var(--admin-accent);
}

.admin-quick-action__icon--primary {
    background: var(--admin-accent-muted);
    color: var(--admin-accent);
}

.admin-quick-action__label {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    text-align: center;
}

.admin-favorites-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--space-md);
}

.admin-favorite-card {
    display: flex;
    align-items: center;
    background: var(--admin-bg-tertiary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.admin-favorite-card__link {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-md);
    text-decoration: none;
    color: var(--admin-text);
}

.admin-favorite-card__link:hover {
    background: var(--admin-surface);
}

.admin-favorite-card__link code {
    color: var(--admin-accent);
}

.admin-favorite-card__category {
    font-size: var(--font-size-xs);
    color: var(--admin-text-muted);
}

.admin-favorite-card__remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 100%;
    min-height: 60px;
    background: none;
    border: none;
    border-left: 1px solid var(--admin-border);
    color: var(--admin-text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.admin-favorite-card__remove:hover {
    background: var(--admin-error-bg);
    color: var(--admin-error);
}

.admin-favorite-card__remove svg {
    width: 16px;
    height: 16px;
}

.admin-suggestion {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-tertiary);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-xs);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.admin-suggestion:hover {
    border-color: var(--admin-accent);
    background: var(--admin-surface);
}

.admin-suggestion code {
    color: var(--admin-accent);
}

.admin-suggestion__category {
    flex: 1;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

.admin-suggestion__action {
    font-size: var(--font-size-sm);
    color: var(--admin-success);
    font-weight: var(--font-weight-medium);
}
</style>
