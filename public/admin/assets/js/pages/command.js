/**
 * QuickSite Admin - Command List Page
 * 
 * Search and filter functionality for the command list.
 * Permission-aware: hides commands user can't access.
 * 
 * @module pages/command
 * @requires QuickSiteAdmin
 */

(function() {
    'use strict';

    /**
     * Initialize command search and filtering
     */
    function initCommandSearch() {
        const searchInput = document.getElementById('command-search');
        const categories = document.querySelectorAll('.admin-category');
        
        if (!searchInput || !categories.length) return;
        
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
                showDisabledCommands(categories, query);
            }
        });
        
        // Initial filter based on permissions (after QuickSiteAdmin loads permissions)
        applyInitialPermissionFilter();
    }

    /**
     * Show commands without permission as disabled when few results
     */
    function showDisabledCommands(categories, query) {
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

    /**
     * Apply initial permission filter after QuickSiteAdmin loads
     */
    function applyInitialPermissionFilter() {
        setTimeout(() => {
            if (QuickSiteAdmin.permissions.loaded && !QuickSiteAdmin.permissions.isSuperAdmin) {
                QuickSiteAdmin.filterByPermissions();
            }
        }, 500);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommandSearch);
    } else {
        initCommandSearch();
    }

})();
