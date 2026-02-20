/**
 * Preview Navigation Module
 * 
 * Handles element navigation in the Visual Editor (parent/sibling/child).
 * Uses PreviewState for shared state access.
 * 
 * Features:
 * - Navigate to parent element
 * - Navigate to sibling elements (prev/next)
 * - Navigate to first child element
 * - Keyboard shortcuts (arrow keys)
 * - Navigation button state management
 * 
 * Dependencies:
 * - PreviewState module (must be loaded first)
 */
(function() {
    'use strict';
    
    // ==================== DOM Elements ====================
    
    const ctxNavParent = document.getElementById('ctx-nav-parent');
    const ctxNavPrev = document.getElementById('ctx-nav-prev');
    const ctxNavNext = document.getElementById('ctx-nav-next');
    const ctxNavChild = document.getElementById('ctx-nav-child');
    
    // ==================== State Access ====================
    
    function getState() {
        if (!window.PreviewState) {
            console.warn('[PreviewNavigation] PreviewState not available');
            return null;
        }
        return {
            navHasParent: PreviewState.get('navHasParent'),
            navHasPrevSibling: PreviewState.get('navHasPrevSibling'),
            navHasNextSibling: PreviewState.get('navHasNextSibling'),
            navHasChildren: PreviewState.get('navHasChildren'),
            selectedStruct: PreviewState.get('selectedStruct'),
            selectedNode: PreviewState.get('selectedNode')
        };
    }
    
    // ==================== Navigation Functions ====================
    
    /**
     * Update navigation button enabled/disabled states based on current selection
     */
    function updateNavigationButtons() {
        const state = getState();
        if (!state) return;
        
        if (ctxNavParent) ctxNavParent.disabled = !state.navHasParent;
        if (ctxNavPrev) ctxNavPrev.disabled = !state.navHasPrevSibling;
        if (ctxNavNext) ctxNavNext.disabled = !state.navHasNextSibling;
        if (ctxNavChild) ctxNavChild.disabled = !state.navHasChildren;
    }
    
    /**
     * Navigate to the parent element of current selection
     */
    function navigateToParent() {
        const state = getState();
        if (!state || !state.navHasParent || !state.selectedStruct || !state.selectedNode) return;
        
        PreviewState.sendToIframe('navigateToParent', {
            struct: state.selectedStruct,
            node: state.selectedNode
        });
    }
    
    /**
     * Navigate to the previous sibling element
     */
    function navigateToPrevSibling() {
        const state = getState();
        if (!state || !state.navHasPrevSibling || !state.selectedStruct || !state.selectedNode) return;
        
        PreviewState.sendToIframe('navigateToPrevSibling', {
            struct: state.selectedStruct,
            node: state.selectedNode
        });
    }
    
    /**
     * Navigate to the next sibling element
     */
    function navigateToNextSibling() {
        const state = getState();
        if (!state || !state.navHasNextSibling || !state.selectedStruct || !state.selectedNode) return;
        
        PreviewState.sendToIframe('navigateToNextSibling', {
            struct: state.selectedStruct,
            node: state.selectedNode
        });
    }
    
    /**
     * Navigate to the first child element
     */
    function navigateToFirstChild() {
        const state = getState();
        if (!state || !state.navHasChildren || !state.selectedStruct || !state.selectedNode) return;
        
        PreviewState.sendToIframe('navigateToFirstChild', {
            struct: state.selectedStruct,
            node: state.selectedNode
        });
    }
    
    // ==================== Keyboard Shortcuts ====================
    
    /**
     * Handle arrow key navigation
     * Called from main preview.js keyboard handler
     */
    function handleArrowKey(key) {
        const state = getState();
        if (!state || !state.selectedNode) return false;
        
        switch (key) {
            case 'ArrowUp':
                if (state.navHasParent) {
                    navigateToParent();
                    return true;
                }
                break;
                
            case 'ArrowDown':
                if (state.navHasChildren) {
                    navigateToFirstChild();
                    return true;
                }
                break;
                
            case 'ArrowLeft':
                if (state.navHasPrevSibling) {
                    navigateToPrevSibling();
                    return true;
                }
                break;
                
            case 'ArrowRight':
                if (state.navHasNextSibling) {
                    navigateToNextSibling();
                    return true;
                }
                break;
        }
        
        return false;
    }
    
    // ==================== Event Listeners ====================
    
    // Initialize navigation button click handlers
    if (ctxNavParent) ctxNavParent.addEventListener('click', navigateToParent);
    if (ctxNavPrev) ctxNavPrev.addEventListener('click', navigateToPrevSibling);
    if (ctxNavNext) ctxNavNext.addEventListener('click', navigateToNextSibling);
    if (ctxNavChild) ctxNavChild.addEventListener('click', navigateToFirstChild);
    
    // ==================== Public API ====================
    
    window.PreviewNavigation = {
        updateButtons: updateNavigationButtons,
        navigateToParent: navigateToParent,
        navigateToPrevSibling: navigateToPrevSibling,
        navigateToNextSibling: navigateToNextSibling,
        navigateToFirstChild: navigateToFirstChild,
        handleArrowKey: handleArrowKey
    };
    
    console.log('[PreviewNavigation] Module loaded');
    
})();
