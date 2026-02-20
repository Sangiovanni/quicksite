/**
 * Preview State Module
 * 
 * Shared state management for the Visual Editor.
 * Provides centralized state access for all preview modules.
 * 
 * This module MUST be loaded before preview.js and other preview modules.
 * 
 * Usage:
 *   PreviewState.set('selectedNode', '0.1.2');
 *   const node = PreviewState.get('selectedNode');
 *   PreviewState.getIframe().contentWindow.postMessage(...);
 */
(function() {
    'use strict';
    
    // ==================== Private State ====================
    
    const state = {
        // Core state
        currentDevice: 'desktop',
        currentMode: 'select',
        currentEditType: 'page',
        currentEditName: '',
        overlayInjected: false,
        
        // Selection state
        selectedStruct: null,
        selectedNode: null,
        selectedComponent: null,
        selectedElementClasses: null,
        selectedElementTag: null,
        
        // Navigation state
        navHasParent: false,
        navHasPrevSibling: false,
        navHasNextSibling: false,
        navHasChildren: false
    };
    
    // DOM element references (set during init)
    let elements = {
        iframe: null,
        container: null,
        wrapper: null,
        loading: null,
        targetSelect: null,
        langSelect: null
    };
    
    // Registered callbacks for state changes
    const listeners = {};
    
    // ==================== Utility Functions ====================
    
    /**
     * Parse struct string into type and name
     * Format: "page-home" -> {type:"page", name:"home"}, "menu" -> {type:"menu", name:null}
     */
    function parseStruct(struct) {
        if (!struct) return null;
        
        if (struct === 'menu' || struct === 'footer') {
            return { type: struct, name: null };
        }
        
        if (struct.startsWith('page-')) {
            return { type: 'page', name: struct.substring(5) };
        }
        
        if (struct.startsWith('component-')) {
            return { type: 'component', name: struct.substring(10) };
        }
        
        return null;
    }
    
    /**
     * Get a node from structure by nodeId path (e.g., "0.2.1")
     */
    function getNodeByPath(structure, nodeId) {
        const indices = nodeId.split('.').map(Number);
        let current = structure;
        
        for (let i = 0; i < indices.length; i++) {
            if (!Array.isArray(current)) {
                current = current.children;
            }
            if (!current || !Array.isArray(current)) return null;
            current = current[indices[i]];
            if (!current) return null;
        }
        return current;
    }
    
    /**
     * Send a message to the preview iframe
     */
    function sendToIframe(action, data) {
        try {
            const iframeWindow = elements.iframe?.contentWindow;
            if (iframeWindow) {
                iframeWindow.postMessage({ source: 'quicksite-admin', action, ...data }, '*');
            }
        } catch (e) {
            console.warn('[PreviewState] Could not send message to iframe:', e);
        }
    }
    
    /**
     * Deep clone an object, removing _nodeId properties
     */
    function cloneWithoutNodeIds(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (Array.isArray(obj)) return obj.map(cloneWithoutNodeIds);
        
        const clone = {};
        for (const key of Object.keys(obj)) {
            if (key === '_nodeId') continue;
            clone[key] = cloneWithoutNodeIds(obj[key]);
        }
        return clone;
    }
    
    // ==================== Public API ====================
    
    window.PreviewState = {
        /**
         * Initialize state with DOM element references
         * Called by preview.js during setup
         */
        init: function(elementsConfig) {
            elements = { ...elements, ...elementsConfig };
            console.log('[PreviewState] Initialized');
        },
        
        /**
         * Get a state value
         */
        get: function(key) {
            return state[key];
        },
        
        /**
         * Set a state value and notify listeners
         */
        set: function(key, value) {
            const oldValue = state[key];
            state[key] = value;
            
            // Notify listeners
            if (listeners[key]) {
                listeners[key].forEach(fn => {
                    try {
                        fn(value, oldValue, key);
                    } catch (e) {
                        console.error('[PreviewState] Listener error:', e);
                    }
                });
            }
        },
        
        /**
         * Set multiple state values at once
         */
        setMany: function(updates) {
            for (const [key, value] of Object.entries(updates)) {
                this.set(key, value);
            }
        },
        
        /**
         * Subscribe to state changes
         * @returns {Function} Unsubscribe function
         */
        on: function(key, callback) {
            if (!listeners[key]) {
                listeners[key] = [];
            }
            listeners[key].push(callback);
            
            // Return unsubscribe function
            return () => {
                listeners[key] = listeners[key].filter(fn => fn !== callback);
            };
        },
        
        /**
         * Get current selection info
         */
        getSelection: function() {
            return {
                struct: state.selectedStruct,
                node: state.selectedNode,
                component: state.selectedComponent,
                classes: state.selectedElementClasses,
                tag: state.selectedElementTag
            };
        },
        
        /**
         * Set selection state (convenience method)
         * Also sets navigation state if provided
         */
        setSelection: function(data) {
            this.setMany({
                selectedStruct: data.struct || null,
                selectedNode: data.node || null,
                selectedComponent: data.component || null,
                selectedElementClasses: data.classes || null,
                selectedElementTag: data.tag || null
            });
            
            // Also update navigation state if provided
            if ('hasParent' in data || 'hasPrevSibling' in data || 'hasNextSibling' in data || 'childCount' in data) {
                this.setMany({
                    navHasParent: data.hasParent ?? false,
                    navHasPrevSibling: data.hasPrevSibling ?? false,
                    navHasNextSibling: data.hasNextSibling ?? false,
                    navHasChildren: (data.childCount ?? 0) > 0
                });
            }
        },
        
        /**
         * Clear selection state
         */
        clearSelection: function() {
            this.setSelection({});
            this.setMany({
                navHasParent: false,
                navHasPrevSibling: false,
                navHasNextSibling: false,
                navHasChildren: false
            });
        },
        
        /**
         * Check if an element is currently selected
         */
        hasSelection: function() {
            return !!(state.selectedStruct && state.selectedNode);
        },
        
        // DOM Element accessors
        getIframe: function() { return elements.iframe; },
        getContainer: function() { return elements.container; },
        getWrapper: function() { return elements.wrapper; },
        getTargetSelect: function() { return elements.targetSelect; },
        getLangSelect: function() { return elements.langSelect; },
        
        // Callback functions (set by preview.js)
        _callbacks: {},
        setCallback: function(name, fn) { this._callbacks[name] = fn; },
        reloadPreview: function() { this._callbacks.reloadPreview?.(); },
        hotReloadCss: function() { this._callbacks.hotReloadCss?.(); },
        
        // Utility functions
        utils: {
            parseStruct: parseStruct,
            getNodeByPath: getNodeByPath,
            sendToIframe: sendToIframe,
            cloneWithoutNodeIds: cloneWithoutNodeIds
        },
        
        // Direct access to sendToIframe (commonly used)
        sendToIframe: sendToIframe
    };
    
    console.log('[PreviewState] Module loaded');
})();
