<?php
/**
 * Admin Dashboard Page
 * 
 * Main admin panel landing page after login.
 * Shows site stats, site map, and recent commands.
 * 
 * @version 1.7.0
 */
?>

<div class="admin-page-header">
    <h1 class="admin-page-header__title"><?= __admin('dashboard.title') ?></h1>
    <p class="admin-page-header__subtitle"><?= __admin('dashboard.subtitle') ?></p>
</div>

<!-- Project Manager -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.projects.title') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body">
            <div class="project-manager">
                <!-- Current Project Info -->
                <div class="project-manager__current" id="current-project-info">
                    <div class="admin-loading">
                        <span class="admin-spinner"></span>
                        <span><?= __admin('common.loading') ?></span>
                    </div>
                </div>
                
                <!-- Project Actions -->
                <div class="project-manager__actions">
                    <!-- Switch Project -->
                    <div class="project-manager__action-group">
                        <label class="admin-label"><?= __admin('dashboard.projects.switch') ?></label>
                        <div class="project-manager__select-row">
                            <select id="project-selector" class="admin-input admin-input--select" disabled>
                                <option value=""><?= __admin('common.loading') ?></option>
                            </select>
                            <button type="button" id="btn-switch-project" class="admin-btn admin-btn--primary" disabled>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                </svg>
                                <?= __admin('dashboard.projects.switchBtn') ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Actions Row -->
                    <div class="project-manager__quick-actions">
                        <!-- Create Project -->
                        <button type="button" id="btn-create-project" class="admin-btn admin-btn--ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                                <line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>
                            </svg>
                            <?= __admin('dashboard.projects.create') ?>
                        </button>
                        
                        <!-- Export Project -->
                        <button type="button" id="btn-export-project" class="admin-btn admin-btn--ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            <?= __admin('dashboard.projects.export') ?>
                        </button>
                        
                        <!-- Import Project -->
                        <button type="button" id="btn-import-project" class="admin-btn admin-btn--ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <?= __admin('dashboard.projects.import') ?>
                        </button>
                        
                        <!-- Delete Project -->
                        <button type="button" id="btn-delete-project" class="admin-btn admin-btn--ghost admin-btn--danger">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                            <?= __admin('dashboard.projects.delete') ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Hidden file input for import -->
<input type="file" id="import-file-input" accept=".zip" style="display: none;">

<!-- Create Project Modal -->
<div id="modal-create-project" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.createTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('dashboard.projects.nameLabel') ?></label>
                <input type="text" id="create-project-name" class="admin-input" placeholder="my-new-site" pattern="[a-z0-9_\-]+" />
                <small class="admin-help"><?= __admin('dashboard.projects.nameHelp') ?></small>
            </div>
            <div class="admin-form-group">
                <label class="admin-checkbox">
                    <input type="checkbox" id="create-project-activate" checked />
                    <span><?= __admin('dashboard.projects.activateAfterCreate') ?></span>
                </label>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" id="btn-confirm-create" class="admin-btn admin-btn--primary"><?= __admin('dashboard.projects.createBtn') ?></button>
        </div>
    </div>
</div>

<!-- Delete Project Modal -->
<div id="modal-delete-project" class="admin-modal" style="display: none;">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__content">
        <div class="admin-modal__header">
            <h3 class="admin-modal__title"><?= __admin('dashboard.projects.deleteTitle') ?></h3>
            <button type="button" class="admin-modal__close" data-close-modal>×</button>
        </div>
        <div class="admin-modal__body">
            <p class="admin-warning"><?= __admin('dashboard.projects.deleteWarning') ?></p>
            <div class="admin-form-group">
                <label class="admin-label"><?= __admin('dashboard.projects.selectToDelete') ?></label>
                <select id="delete-project-selector" class="admin-input admin-input--select">
                    <option value=""><?= __admin('dashboard.projects.selectProject') ?></option>
                </select>
            </div>
        </div>
        <div class="admin-modal__footer">
            <button type="button" class="admin-btn admin-btn--ghost" data-close-modal><?= __admin('common.cancel') ?></button>
            <button type="button" id="btn-confirm-delete" class="admin-btn admin-btn--danger" disabled><?= __admin('dashboard.projects.deleteBtn') ?></button>
        </div>
    </div>
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

<!-- Site Map -->
<section class="admin-section">
    <h2 class="admin-section__title"><?= __admin('dashboard.sitemap.title') ?></h2>
    <div class="admin-card">
        <div class="admin-card__body" id="sitemap-container">
            <div class="admin-loading">
                <span class="admin-spinner"></span>
                <span><?= __admin('common.loading') ?></span>
            </div>
        </div>
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

<script>
// Load dashboard data
document.addEventListener('DOMContentLoaded', async function() {
    await Promise.all([
        loadDashboardStats(),
        loadSiteMap(),
        loadRecentCommands(),
        loadProjectManager()
    ]);
    
    // Setup project manager event listeners
    setupProjectManagerEvents();
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
            document.getElementById('stat-languages').textContent = langResult.data.data?.languages?.length || 1;
        }
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
}

async function loadSiteMap() {
    const container = document.getElementById('sitemap-container');
    const t = window.QUICKSITE_CONFIG?.translations || {};
    const sitemap = t.dashboard?.sitemap || {};
    
    try {
        // Load sitemap and translation coverage in parallel
        const [sitemapResult, validationResult] = await Promise.all([
            QuickSiteAdmin.apiRequest('getSiteMap'),
            QuickSiteAdmin.apiRequest('validateTranslations')
        ]);
        
        if (!sitemapResult.ok) {
            throw new Error('Failed to load sitemap');
        }
        
        const data = sitemapResult.data.data;
        const coverage = validationResult.ok ? validationResult.data.data?.validation_results : {};
        const baseUrl = data.baseUrl;
        const multilingual = data.multilingual;
        const defaultLang = data.defaultLang;
        const languages = data.languages || [];
        const languageNames = data.languageNames || {};
        const routes = data.routes || [];
        
        let html = '<div class="sitemap">';
        
        // Summary bar - adjust for mono/multilingual
        if (multilingual) {
            html += `<div class="sitemap__summary">
                <span class="sitemap__total">${data.totalUrls} ${sitemap.urls || 'URLs'}</span>
                <span class="sitemap__divider">•</span>
                <span>${routes.length} ${sitemap.routes || 'routes'}</span>
                <span class="sitemap__divider">•</span>
                <span>${languages.length} ${sitemap.languages || 'languages'}</span>
            </div>`;
        } else {
            html += `<div class="sitemap__summary">
                <span class="sitemap__total">${data.totalUrls} ${sitemap.urls || 'URLs'}</span>
                <span class="sitemap__divider">•</span>
                <span>${routes.length} ${sitemap.routes || 'routes'}</span>
                <span class="sitemap__divider">•</span>
                <span class="badge badge--ghost">${sitemap.monolingual || 'Single language'}</span>
            </div>`;
        }
        
        if (multilingual) {
            // Sort languages to put default first
            const sortedLangs = [...languages].sort((a, b) => {
                if (a === defaultLang) return -1;
                if (b === defaultLang) return 1;
                return a.localeCompare(b);
            });
            
            // Language groups
            html += '<div class="sitemap__languages">';
            
            sortedLangs.forEach((lang, idx) => {
                const langName = languageNames[lang] || lang.toUpperCase();
                const isDefault = lang === defaultLang;
                const isOpen = isDefault; // Auto-expand default language
                const langCoverage = coverage[lang];
                const coveragePercent = langCoverage?.coverage_percent ?? null;
                
                html += `<div class="sitemap__lang ${isOpen ? 'sitemap__lang--open' : ''}" data-lang="${lang}">`;
                
                // Language header - use data attribute instead of onclick to prevent event issues
                html += `<div class="sitemap__lang-header" data-toggle-lang="${lang}">
                    <svg class="sitemap__lang-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                    <span class="sitemap__lang-flag">${getFlagEmoji(lang)}</span>
                    <span class="sitemap__lang-name">${QuickSiteAdmin.escapeHtml(langName)}</span>
                    ${isDefault ? `<span class="badge badge--primary">${sitemap.default || 'Default'}</span>` : ''}
                    <span class="sitemap__lang-count">${routes.length} ${sitemap.pages || 'pages'}</span>
                    ${coveragePercent !== null ? `<span class="sitemap__lang-coverage ${getCoverageClass(coveragePercent)}">${coveragePercent}%</span>` : ''}
                </div>`;
                
                // Routes list
                html += '<div class="sitemap__routes">';
                routes.forEach(route => {
                    const url = route.urls[lang] || route.urls['default'];
                    const routePath = route.path;
                    const routeName = route.name;
                    const isHome = routeName === 'home';
                    
                    html += `<a href="${QuickSiteAdmin.escapeHtml(url)}" target="_blank" class="sitemap__route" title="${QuickSiteAdmin.escapeHtml(url)}">
                        <svg class="sitemap__route-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            ${isHome ? '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>' : '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>'}
                        </svg>
                        <span class="sitemap__route-name">${routeName}</span>
                        <span class="sitemap__route-path">${routePath}</span>
                        <svg class="sitemap__route-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>`;
                });
                html += '</div>'; // .sitemap__routes
                
                html += '</div>'; // .sitemap__lang
            });
            
            html += '</div>'; // .sitemap__languages
        } else {
            // Monolingual mode - simple flat list of routes
            html += '<div class="sitemap__routes sitemap__routes--flat">';
            routes.forEach(route => {
                const url = route.urls['default'];
                const routePath = route.path;
                const routeName = route.name;
                const isHome = routeName === 'home';
                
                html += `<a href="${QuickSiteAdmin.escapeHtml(url)}" target="_blank" class="sitemap__route" title="${QuickSiteAdmin.escapeHtml(url)}">
                    <svg class="sitemap__route-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        ${isHome ? '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>' : '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>'}
                    </svg>
                    <span class="sitemap__route-name">${routeName}</span>
                    <span class="sitemap__route-path">${routePath}</span>
                    <svg class="sitemap__route-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                </a>`;
            });
            html += '</div>'; // .sitemap__routes--flat
        }
        
        html += '</div>'; // .sitemap
        
        container.innerHTML = html;
        
        // Add event listeners for language toggles (using event delegation)
        container.addEventListener('click', function(e) {
            const header = e.target.closest('[data-toggle-lang]');
            if (header) {
                e.preventDefault();
                e.stopPropagation();
                const langEl = header.closest('.sitemap__lang');
                if (langEl) {
                    langEl.classList.toggle('sitemap__lang--open');
                }
            }
        });
        
    } catch (error) {
        console.error('Failed to load sitemap:', error);
        container.innerHTML = `
            <div class="admin-empty" style="padding: var(--space-lg);">
                <p>${sitemap.error || 'Failed to load site map'}</p>
            </div>
        `;
    }
}

function getCoverageClass(percent) {
    if (percent >= 95) return 'sitemap__coverage--excellent';
    if (percent >= 80) return 'sitemap__coverage--good';
    if (percent >= 50) return 'sitemap__coverage--warning';
    return 'sitemap__coverage--poor';
}

function getFlagEmoji(langCode) {
    const flags = {
        'en': '🇬🇧',
        'fr': '🇫🇷',
        'es': '🇪🇸',
        'de': '🇩🇪',
        'it': '🇮🇹',
        'pt': '🇵🇹',
        'nl': '🇳🇱',
        'ru': '🇷🇺',
        'zh': '🇨🇳',
        'ja': '🇯🇵',
        'ko': '🇰🇷',
        'ar': '🇸🇦',
        'hi': '🇮🇳',
        'tr': '🇹🇷',
        'pl': '🇵🇱',
        'sv': '🇸🇪',
        'da': '🇩🇰',
        'fi': '🇫🇮',
        'no': '🇳🇴',
        'cs': '🇨🇿',
        'el': '🇬🇷',
        'he': '🇮🇱',
        'th': '🇹🇭',
        'vi': '🇻🇳',
        'id': '🇮🇩',
        'ms': '🇲🇾',
        'uk': '🇺🇦',
        'ro': '🇷🇴',
        'hu': '🇭🇺',
        'bg': '🇧🇬',
        'sk': '🇸🇰',
        'hr': '🇭🇷',
        'sl': '🇸🇮',
        'et': '🇪🇪',
        'lv': '🇱🇻',
        'lt': '🇱🇹'
    };
    return flags[langCode.toLowerCase()] || '🌐';
}

async function loadRecentCommands() {
    const container = document.getElementById('recent-commands');
    const t = window.QUICKSITE_CONFIG?.translations || {};
    const cols = t.dashboard?.history?.columns || {};
    const common = t.common || {};
    
    try {
        const result = await QuickSiteAdmin.apiRequest('getCommandHistory', 'GET', null, []);
        
        if (result.ok && result.data.data?.entries?.length > 0) {
            const entries = result.data.data.entries.slice(0, 5); // Last 5 commands
            
            let html = '<table class="admin-table"><thead><tr>';
            html += `<th>${cols.command || 'Command'}</th><th>${cols.status || 'Status'}</th><th>${cols.duration || 'Duration'}</th><th>${cols.time || 'Time'}</th>`;
            html += '</tr></thead><tbody>';
            
            entries.forEach(entry => {
                // Handle both old format (status: "success") and new format (http_status: 200)
                const httpStatus = entry.result?.http_status || entry.result?.status;
                const isSuccess = typeof httpStatus === 'number' 
                    ? httpStatus >= 200 && httpStatus < 300
                    : httpStatus === 'success';
                const statusClass = isSuccess ? 'badge--success' : 'badge--error';
                const statusText = isSuccess ? (common.success || 'Success') : (common.error || 'Error');
                
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

// ============================================================================
// Project Manager Functions
// ============================================================================

let currentProject = null;
let allProjects = [];

async function loadProjectManager() {
    const infoContainer = document.getElementById('current-project-info');
    const selector = document.getElementById('project-selector');
    const switchBtn = document.getElementById('btn-switch-project');
    const t = window.QUICKSITE_CONFIG?.translations || {};
    const proj = t.dashboard?.projects || {};
    
    try {
        // Load active project and list in parallel
        const [activeResult, listResult] = await Promise.all([
            QuickSiteAdmin.apiRequest('getActiveProject'),
            QuickSiteAdmin.apiRequest('listProjects')
        ]);
        
        if (activeResult.ok && activeResult.data?.data?.project) {
            currentProject = activeResult.data.data.project;
            const created = activeResult.data.data.created_at;
            
            infoContainer.innerHTML = `
                <div class="project-manager__info">
                    <div class="project-manager__name">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                        </svg>
                        <span>${QuickSiteAdmin.escapeHtml(currentProject)}</span>
                        <span class="badge badge--primary">${proj.active || 'Active'}</span>
                    </div>
                    ${created ? `<div class="project-manager__meta">${proj.created || 'Created'}: ${new Date(created).toLocaleDateString()}</div>` : ''}
                </div>
            `;
        } else {
            // API returned error or no project - show error state
            currentProject = null;
            const errorMsg = activeResult.data?.message || proj.error || 'Failed to load active project';
            infoContainer.innerHTML = `<p class="admin-error">${QuickSiteAdmin.escapeHtml(errorMsg)}</p>`;
        }
        
        if (listResult.ok && listResult.data?.data?.projects) {
            allProjects = listResult.data.data.projects || [];
            
            // Populate selector
            selector.innerHTML = '';
            allProjects.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.name;
                opt.textContent = p.name + (p.is_active ? ` (${proj.active || 'active'})` : '');
                opt.selected = p.is_active;
                selector.appendChild(opt);
            });
            
            selector.disabled = false;
            switchBtn.disabled = false;
            
            // Also populate delete selector
            updateDeleteSelector();
        }
        
    } catch (error) {
        console.error('Failed to load project manager:', error);
        infoContainer.innerHTML = `<p class="admin-error">${proj.error || 'Failed to load project info'}</p>`;
    }
}

function updateDeleteSelector() {
    const deleteSelector = document.getElementById('delete-project-selector');
    const t = window.QUICKSITE_CONFIG?.translations || {};
    const proj = t.dashboard?.projects || {};
    
    deleteSelector.innerHTML = `<option value="">${proj.selectProject || 'Select a project...'}</option>`;
    
    // Only show non-active projects for deletion (or all if there's only one)
    const deletable = allProjects.filter(p => !p.is_active);
    deletable.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.name;
        opt.textContent = p.name;
        deleteSelector.appendChild(opt);
    });
    
    if (deletable.length === 0 && allProjects.length > 0) {
        // If only one project, show warning
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = proj.cannotDeleteActive || 'Cannot delete active project';
        opt.disabled = true;
        deleteSelector.appendChild(opt);
    }
}

function setupProjectManagerEvents() {
    const t = window.QUICKSITE_CONFIG?.translations || {};
    const proj = t.dashboard?.projects || {};
    const common = t.common || {};
    
    // Switch project
    document.getElementById('btn-switch-project').addEventListener('click', async function() {
        const selector = document.getElementById('project-selector');
        const newProject = selector.value;
        
        if (!newProject || newProject === currentProject) return;
        
        this.disabled = true;
        this.innerHTML = '<span class="spinner"></span> ' + (common.loading || 'Switching...');
        try {
            const result = await QuickSiteAdmin.apiRequest('switchProject', 'POST', { project: newProject });
            if (result.ok) {
                QuickSiteAdmin.showToast((proj.switched || 'Switched to project') + ': ' + newProject, 'success');
                // Server guarantees target.php is written before response, reload with cache bust
                window.location.href = window.location.pathname + '?t=' + Date.now();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Failed to switch project', 'error');
                this.disabled = false;
                this.textContent = proj.switch || 'Switch';
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Failed to switch project', 'error');
            this.disabled = false;
            this.textContent = proj.switch || 'Switch';
        }
    });
    
    // Create project modal
    document.getElementById('btn-create-project').addEventListener('click', function() {
        document.getElementById('modal-create-project').style.display = 'flex';
        document.getElementById('create-project-name').focus();
    });
    
    // Confirm create project
    document.getElementById('btn-confirm-create').addEventListener('click', async function() {
        const nameInput = document.getElementById('create-project-name');
        const activateCheckbox = document.getElementById('create-project-activate');
        const name = nameInput.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '-');
        
        if (!name) {
            QuickSiteAdmin.showToast(proj.nameRequired || 'Project name is required', 'error');
            return;
        }
        
        this.disabled = true;
        this.innerHTML = '<span class="spinner"></span> ' + (common.loading || 'Creating...');
        try {
            const result = await QuickSiteAdmin.apiRequest('createProject', 'POST', {
                name: name,
                switch_to: activateCheckbox.checked
            });
            
            if (result.ok) {
                QuickSiteAdmin.showToast((proj.created || 'Project created') + ': ' + name, 'success');
                closeAllModals();
                if (activateCheckbox.checked) {
                    // Server guarantees target.php is written, reload with cache bust
                    window.location.href = window.location.pathname + '?t=' + Date.now();
                } else {
                    // Just refresh the project list
                    loadProjectManager();
                    this.disabled = false;
                    this.textContent = proj.createModal?.submit || 'Create Project';
                }
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Failed to create project', 'error');
                this.disabled = false;
                this.textContent = proj.createModal?.submit || 'Create Project';
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Failed to create project', 'error');
            this.disabled = false;
            this.textContent = proj.createModal?.submit || 'Create Project';
        }
    });
    
    // Export project
    document.getElementById('btn-export-project').addEventListener('click', async function() {
        this.disabled = true;
        const originalText = this.textContent;
        this.innerHTML = '<span class="spinner"></span> ' + (proj.exporting || 'Exporting...');
        
        try {
            // Request export - streams directly by default
            const response = await fetch(window.QUICKSITE_CONFIG.apiBase + '/exportProject?name=' + encodeURIComponent(currentProject), {
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer ' + QuickSiteAdmin.getToken()
                }
            });
            
            if (response.ok) {
                // Get filename from Content-Disposition header or generate one
                const contentDisposition = response.headers.get('Content-Disposition');
                let filename = currentProject + '_export.zip';
                if (contentDisposition) {
                    const match = contentDisposition.match(/filename="?([^";\n]+)"?/);
                    if (match) filename = match[1];
                }
                
                // Create blob and trigger download
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                a.remove();
                
                QuickSiteAdmin.showToast(proj.exported || 'Project exported', 'success');
            } else {
                const errorData = await response.json().catch(() => ({}));
                QuickSiteAdmin.showToast(errorData.message || 'Failed to export project', 'error');
            }
        } catch (error) {
            console.error('Export error:', error);
            QuickSiteAdmin.showToast('Failed to export project', 'error');
        }
        
        this.disabled = false;
        this.textContent = originalText;
    });
    
    // Import project
    document.getElementById('btn-import-project').addEventListener('click', function() {
        document.getElementById('import-file-input').click();
    });
    
    document.getElementById('import-file-input').addEventListener('change', async function() {
        if (!this.files || !this.files[0]) return;
        
        const file = this.files[0];
        const formData = new FormData();
        formData.append('file', file);
        formData.append('activate', 'false');
        
        try {
            QuickSiteAdmin.showToast(proj.importing || 'Importing project...', 'info');
            
            const response = await fetch(window.QUICKSITE_CONFIG.apiBase + '/importProject', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + QuickSiteAdmin.getToken()
                },
                body: formData
            });
            
            const result = await response.json();
            
            if (response.ok) {
                QuickSiteAdmin.showToast(result.message || proj.imported || 'Project imported successfully', 'success');
                loadProjectManager();
            } else {
                QuickSiteAdmin.showToast(result.message || 'Failed to import project', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Failed to import project', 'error');
        }
        
        this.value = ''; // Reset file input
    });
    
    // Delete project modal
    document.getElementById('btn-delete-project').addEventListener('click', function() {
        updateDeleteSelector();
        document.getElementById('modal-delete-project').style.display = 'flex';
    });
    
    // Enable delete button only when project selected
    document.getElementById('delete-project-selector').addEventListener('change', function() {
        document.getElementById('btn-confirm-delete').disabled = !this.value;
    });
    
    // Confirm delete project
    document.getElementById('btn-confirm-delete').addEventListener('click', async function() {
        const selector = document.getElementById('delete-project-selector');
        const projectToDelete = selector.value;
        
        if (!projectToDelete) return;
        
        this.disabled = true;
        try {
            const result = await QuickSiteAdmin.apiRequest('deleteProject', 'POST', {
                name: projectToDelete,
                confirm: true
            });
            
            if (result.ok) {
                QuickSiteAdmin.showToast(proj.deleted || 'Project deleted', 'success');
                closeAllModals();
                loadProjectManager();
            } else {
                QuickSiteAdmin.showToast(result.data?.message || 'Failed to delete project', 'error');
            }
        } catch (error) {
            QuickSiteAdmin.showToast('Failed to delete project', 'error');
        }
        this.disabled = false;
    });
    
    // Modal close handlers
    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', closeAllModals);
    });
    
    document.querySelectorAll('.admin-modal__backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', closeAllModals);
    });
}

function closeAllModals() {
    document.querySelectorAll('.admin-modal').forEach(modal => {
        modal.style.display = 'none';
    });
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

/* Sitemap Styles */
.sitemap {
    padding: var(--space-md);
}

.sitemap__summary {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--admin-bg-tertiary);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

.sitemap__total {
    font-weight: var(--font-weight-semibold);
    color: var(--admin-text);
}

.sitemap__divider {
    color: var(--admin-border);
}

.sitemap__languages {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.sitemap__lang {
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: border-color var(--transition-fast);
}

.sitemap__lang:hover {
    border-color: var(--admin-accent);
}

.sitemap__lang-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--admin-surface);
    cursor: pointer;
    user-select: none;
    transition: background var(--transition-fast);
}

.sitemap__lang-header:hover {
    background: var(--admin-bg-tertiary);
}

.sitemap__lang-toggle {
    color: var(--admin-text-muted);
    transition: transform var(--transition-fast);
    flex-shrink: 0;
}

.sitemap__lang--open .sitemap__lang-toggle {
    transform: rotate(90deg);
}

.sitemap__lang-flag {
    font-size: 1.25em;
    line-height: 1;
}

.sitemap__lang-name {
    font-weight: var(--font-weight-medium);
    color: var(--admin-text);
    flex: 1;
}

.sitemap__lang-count {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
}

.sitemap__lang-coverage {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
    padding: 2px 8px;
    border-radius: var(--radius-full);
}

.sitemap__coverage--excellent {
    background: var(--admin-success-bg);
    color: var(--admin-success);
}

.sitemap__coverage--good {
    background: rgba(34, 197, 94, 0.15);
    color: #16a34a;
}

.sitemap__coverage--warning {
    background: var(--admin-warning-bg);
    color: var(--admin-warning);
}

.sitemap__coverage--poor {
    background: var(--admin-error-bg);
    color: var(--admin-error);
}

.sitemap__routes {
    display: none;
    padding: var(--space-sm);
    background: var(--admin-bg);
    border-top: 1px solid var(--admin-border);
}

.sitemap__lang--open .sitemap__routes {
    display: block;
}

.sitemap__route {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    color: var(--admin-text);
    text-decoration: none;
    border-radius: var(--radius-sm);
    transition: all var(--transition-fast);
}

.sitemap__route:hover {
    background: var(--admin-surface);
    color: var(--admin-accent);
}

.sitemap__route-icon {
    color: var(--admin-text-muted);
    flex-shrink: 0;
}

.sitemap__route:hover .sitemap__route-icon {
    color: var(--admin-accent);
}

.sitemap__route-name {
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
}

.sitemap__route-path {
    flex: 1;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    text-align: right;
}

.sitemap__route-external {
    color: var(--admin-text-muted);
    opacity: 0;
    transition: opacity var(--transition-fast);
    flex-shrink: 0;
}

.sitemap__route:hover .sitemap__route-external {
    opacity: 1;
}

/* Flat routes list for monolingual mode */
.sitemap__routes--flat {
    display: block;
    padding: var(--space-sm);
    background: var(--admin-surface);
    border-radius: var(--radius-md);
    border: 1px solid var(--admin-border);
}

/* Responsive sitemap */
@media (max-width: 600px) {
    .sitemap__summary {
        flex-wrap: wrap;
        gap: var(--space-xs);
    }
    
    .sitemap__route-path {
        display: none;
    }
}

/* Project Manager Styles */
.project-manager {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
    padding: var(--space-md);
}

.project-manager__current {
    min-height: 50px;
}

.project-manager__info {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.project-manager__name {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-semibold);
    color: var(--admin-text);
}

.project-manager__name svg {
    color: var(--admin-accent);
}

.project-manager__meta {
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin-left: 28px;
}

.project-manager__actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--admin-border);
}

.project-manager__action-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.project-manager__select-row {
    display: flex;
    gap: var(--space-sm);
}

.project-manager__select-row select {
    flex: 1;
}

.project-manager__quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}

.project-manager__quick-actions .admin-btn {
    flex: 1;
    min-width: 140px;
    justify-content: center;
}

/* Modal Styles */
.admin-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}

.admin-modal__content {
    position: relative;
    background: var(--admin-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    max-width: 480px;
    width: 90%;
    max-height: 90vh;
    overflow: auto;
}

.admin-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--admin-border);
}

.admin-modal__title {
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-semibold);
    margin: 0;
}

.admin-modal__close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--admin-text-muted);
    cursor: pointer;
    padding: 0;
    line-height: 1;
}

.admin-modal__close:hover {
    color: var(--admin-text);
}

.admin-modal__body {
    padding: var(--space-lg);
}

.admin-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-lg);
    border-top: 1px solid var(--admin-border);
}

.admin-warning {
    padding: var(--space-md);
    background: var(--admin-warning-bg);
    border-radius: var(--radius-md);
    color: var(--admin-warning);
    margin-bottom: var(--space-md);
}

.admin-form-group {
    margin-bottom: var(--space-md);
}

.admin-form-group:last-child {
    margin-bottom: 0;
}

.admin-help {
    display: block;
    font-size: var(--font-size-sm);
    color: var(--admin-text-muted);
    margin-top: var(--space-xs);
}

.admin-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    cursor: pointer;
}

.admin-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
}

.admin-btn--danger {
    color: var(--admin-error);
    border-color: var(--admin-error);
}

.admin-btn--danger:hover {
    background: var(--admin-error);
    color: white;
}

/* Ghost danger button - needs visible text on transparent bg */
.admin-btn--ghost.admin-btn--danger {
    background: transparent;
    border-color: transparent;
    color: var(--admin-error);
}

.admin-btn--ghost.admin-btn--danger:hover {
    background: var(--admin-error-bg, rgba(239, 68, 68, 0.1));
    border-color: transparent;
    color: var(--admin-error);
}

/* Responsive project manager */
@media (max-width: 600px) {
    .project-manager__select-row {
        flex-direction: column;
    }
    
    .project-manager__quick-actions {
        flex-direction: column;
    }
    
    .project-manager__quick-actions .admin-btn {
        min-width: auto;
    }
}
</style>
