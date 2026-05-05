/**
 * QuickSite Visual Editor - Iframe Overlay Script
 * 
 * This script is injected into the preview iframe to enable:
 * - Element selection and highlighting
 * - Drag-and-drop reordering
 * - Text editing
 * - Style mode selection
 * - JS mode interaction detection
 * - Navigation (parent/sibling/child)
 * 
 * Communication with parent window via postMessage.
 * 
 * @version 1.0.0
 */
(function() {
    'use strict';
    
    console.log('[QuickSite] Overlay script loaded');
    
    // Security: parent (admin) and this iframe (preview) are same-origin.
    // Use the explicit origin for every postMessage instead of '*'.
    const ALLOWED_ORIGIN = window.location.origin;
    
    // Safe attribute-selector helper: CSS.escape() guards against quotes
    // or special chars sneaking into struct/nodeId values used in selectors.
    function qsAttr(name, value) {
        return '[' + name + '="' + (window.CSS && CSS.escape ? CSS.escape(String(value)) : String(value).replace(/"/g, '\\"')) + '"]';
    }
    function qsNodeSel(struct, nodeId) {
        return qsAttr('data-qs-struct', struct) + qsAttr('data-qs-node', nodeId);
    }
    function qsStructSel(struct) {
        return qsAttr('data-qs-struct', struct);
    }
    
    let currentMode = 'select';
    let hoveredElement = null;
    let selectedElement = null;
    
    // Drag state
    let isDragging = false;
    let dragElement = null;
    let dragInfo = null;
    let dropIndicator = null;
    let dropTarget = null;
    let dropPosition = null; // 'before', 'after', or 'inside'
    let dragGhost = null;
    let dragOriginalParent = null;
    let dragOriginalNextSibling = null;
    let dragStartX = 0;
    let dragStartY = 0;
    let dragThreshold = 5; // pixels before drag actually starts
    let dragActivated = false; // true once threshold exceeded
    let dragPendingElement = null; // element waiting for threshold
    
    // Drag Phase 1/2 state
    let dragSelectedElement = null; // Phase 1: currently selected element
    let dragLocked = false; // Phase 2: element is locked for dragging
    let dragLockPersistent = false; // true = locked via button click (stays locked after drop)
    let longPressTimer = null; // timer for long-press lock detection
    let longPressDelay = 400; // ms before long-press triggers lock
    
    // Drag undo/redo stacks
    let dragUndoStack = []; // { element, oldParent, oldNextSibling, newParent, newNextSibling, struct }
    let dragRedoStack = [];
    
    // Text edit state
    let editingElement = null;
    let editingTextKey = null;
    let originalText = null;
    
    // Elements to ignore
    const ignoreTags = ['SCRIPT', 'STYLE', 'META', 'LINK', 'NOSCRIPT', 'BR', 'HR', 'HTML', 'HEAD'];
    
    // Inject CSS for empty-element hints
    var emptyHintStyle = document.createElement('style');
    emptyHintStyle.textContent = '.qs-empty-hint { outline: 1px dashed rgba(128,128,128,0.5); }';
    document.head.appendChild(emptyHintStyle);
    
    // Listen for messages from admin
    window.addEventListener('message', function(e) {
        // Security: reject messages from any other origin (admin is same-origin)
        if (e.origin !== ALLOWED_ORIGIN) return;
        if (e.data && e.data.source === 'quicksite-admin') {
            console.log('[QuickSite] Received message:', e.data.action);
            if (e.data.action === 'setMode') {
                setMode(e.data.mode);
            }
            if (e.data.action === 'clearSelection') {
                clearSelection();
            }
            if (e.data.action === 'highlightNode') {
                highlightByNode(e.data.struct, e.data.node);
            }
            if (e.data.action === 'selectNode') {
                selectByNode(e.data.struct, e.data.node);
            }
            if (e.data.action === 'insertNode') {
                insertNodeIntoDom(e.data.struct, e.data.targetNode, e.data.position, e.data.html, e.data.newNodeId);
            }
            if (e.data.action === 'updateNode') {
                updateNodeInDom(e.data.struct, e.data.nodeId, e.data.html);
            }
            if (e.data.action === 'removeNode') {
                removeNodeFromDom(e.data.struct, e.data.nodeId);
            }
            if (e.data.action === 'duplicateNode') {
                duplicateNodeInDom(e.data.struct, e.data.sourceNodeId, e.data.newNodeId, e.data.html);
            }
            if (e.data.action === 'rollbackDrag') {
                rollbackDrag();
            }
            if (e.data.action === 'reindexNodes') {
                reindexAllNodes(e.data.struct);
            }
            // Drag tool options from parent
            if (e.data.action === 'dragToggleLock') {
                toggleDragLockPersistent();
            }
            if (e.data.action === 'dragUndo') {
                dragUndoMove();
            }
            if (e.data.action === 'dragRedo') {
                dragRedoMove();
            }
            // Drag navigation from parent buttons
            if (e.data.action === 'dragNavParent') {
                dragNavigateParent();
            }
            if (e.data.action === 'dragNavPrev') {
                dragNavigatePrevSibling();
            }
            if (e.data.action === 'dragNavNext') {
                dragNavigateNextSibling();
            }
            if (e.data.action === 'dragNavChild') {
                dragNavigateChild();
            }
            if (e.data.action === 'showStruct') {
                toggleStructVisibility(e.data.struct, true);
            }
            if (e.data.action === 'hideStruct') {
                toggleStructVisibility(e.data.struct, false);
            }
            if (e.data.action === 'clearStyleSelection') {
                clearStyleSelection();
            }
            if (e.data.action === 'applyLiveStyle') {
                applyLiveStyle(e.data.struct, e.data.nodeId, e.data.property, e.data.value);
            }
            // Phase 8.4: Selector-based highlighting
            if (e.data.action === 'highlightBySelector') {
                highlightBySelector(e.data.selector);
            }
            if (e.data.action === 'selectBySelector') {
                selectBySelector(e.data.selector);
            }
            if (e.data.action === 'clearSelectorHighlight') {
                clearSelectorHighlight();
            }
            // JS Mode: Get all classes from DOM
            if (e.data.action === 'getPageClasses') {
                getPageClasses();
            }
            // Navigation handlers
            if (e.data.action === 'navigateToParent') {
                navigateToParent(e.data.struct, e.data.node);
            }
            if (e.data.action === 'navigateToPrevSibling') {
                navigateToPrevSibling(e.data.struct, e.data.node);
            }
            if (e.data.action === 'navigateToNextSibling') {
                navigateToNextSibling(e.data.struct, e.data.node);
            }
            if (e.data.action === 'navigateToFirstChild') {
                navigateToFirstChild(e.data.struct, e.data.node);
            }
        }
    });
    
    // Get all unique CSS classes from the current page DOM
    function getPageClasses() {
        const classSet = new Set();
        
        // Get all elements with class attribute
        document.querySelectorAll('[class]').forEach(el => {
            // Skip QuickSite internal classes
            el.classList.forEach(cls => {
                if (!cls.startsWith('qs-')) {
                    classSet.add(cls);
                }
            });
        });
        
        // Convert to sorted array
        const classes = Array.from(classSet).sort();
        
        // Send back to parent
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'pageClassesResult',
            classes: classes
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Sent page classes:', classes.length, 'unique classes');
    }
    
    // Navigation functions
    function selectElementAndNotify(el) {
        if (!el) return;
        clearSelection();
        clearHover();
        selectedElement = el;
        // In JS mode, mirror the click-mode visual class and emit
        // the JS-mode event so the JS panel updates instead of the
        // generic select panel.
        if (currentMode === 'js') {
            document.querySelectorAll('.qs-js-selected').forEach(function(n){ n.classList.remove('qs-js-selected'); });
            el.classList.add('qs-js-selected');
            const info = getElementInfo(el);
            window.parent.postMessage({
                source: 'quicksite-preview',
                action: 'interactionSelected',
                element: info
            }, ALLOWED_ORIGIN);
            return;
        }
        el.classList.add('qs-selected');

        const info = getElementInfo(el);
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'elementSelected',
            ...info
        }, ALLOWED_ORIGIN);
    }
    
    function navigateToParent(struct, nodeId) {
        const current = document.querySelector(qsNodeSel(struct, nodeId));
        if (!current) return;
        
        const parent = current.parentElement ? current.parentElement.closest('[data-qs-node]') : null;
        if (parent) {
            selectElementAndNotify(parent);
        }
    }
    
    function navigateToPrevSibling(struct, nodeId) {
        const current = document.querySelector(qsNodeSel(struct, nodeId));
        if (!current || !current.parentElement) return;
        
        const siblings = Array.from(current.parentElement.children).filter(function(c) {
            return c.hasAttribute('data-qs-node');
        });
        const idx = siblings.indexOf(current);
        if (idx > 0) {
            selectElementAndNotify(siblings[idx - 1]);
        }
    }
    
    function navigateToNextSibling(struct, nodeId) {
        const current = document.querySelector(qsNodeSel(struct, nodeId));
        if (!current || !current.parentElement) return;
        
        const siblings = Array.from(current.parentElement.children).filter(function(c) {
            return c.hasAttribute('data-qs-node');
        });
        const idx = siblings.indexOf(current);
        if (idx < siblings.length - 1) {
            selectElementAndNotify(siblings[idx + 1]);
        }
    }
    
    function navigateToFirstChild(struct, nodeId) {
        const current = document.querySelector(qsNodeSel(struct, nodeId));
        if (!current) return;
        
        const firstChild = current.querySelector('[data-qs-node]');
        if (firstChild) {
            selectElementAndNotify(firstChild);
        }
    }
    
    // Insert a new node into the DOM without full page reload
    function insertNodeIntoDom(struct, targetNode, position, html, newNodeId) {
        console.log('[QuickSite] Inserting node:', { struct, targetNode, position, newNodeId });
        
        // Find the target element - try direct match first, then within struct container
        let targetEl = document.querySelector(qsNodeSel(struct, targetNode));
        if (!targetEl) {
            // If not found with struct attribute, search within struct container
            const structContainer = document.querySelector(qsStructSel(struct));
            if (structContainer) {
                targetEl = structContainer.querySelector(qsAttr('data-qs-node', targetNode));
            }
        }
        if (!targetEl) {
            console.error('[QuickSite] Target element not found:', struct, targetNode);
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'insertNodeFailed', 
                error: 'Target element not found' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Parse newNodeId to get the inserted index
        const newIdParts = newNodeId.split('.');
        const insertedIndex = parseInt(newIdParts[newIdParts.length - 1], 10);
        const parentPath = newIdParts.slice(0, -1).join('.');
        
        // FIRST: Reindex existing siblings BEFORE inserting the new element
        // Search ALL elements with data-qs-node in the entire document for maximum coverage
        const allNodeElements = Array.from(document.querySelectorAll('[data-qs-node]'));
        
        console.log('[QuickSite] Total elements with data-qs-node:', allNodeElements.length);
        console.log('[QuickSite] Looking for siblings with parentPath:', parentPath, 'and index >=', insertedIndex);
        
        // Find and reindex siblings with index >= insertedIndex
        const siblingsToReindex = allNodeElements
            .filter(function(el) {
                const elNodeId = el.getAttribute('data-qs-node');
                if (!elNodeId) return false;
                const elParts = elNodeId.split('.');
                const elParentPath = elParts.slice(0, -1).join('.');
                const elIndex = parseInt(elParts[elParts.length - 1], 10);
                const shouldReindex = elParentPath === parentPath && elIndex >= insertedIndex;
                if (shouldReindex) {
                    console.log('[QuickSite] Will reindex:', elNodeId, '(parent:', elParentPath, 'index:', elIndex, ')');
                }
                return shouldReindex;
            })
            .sort(function(a, b) {
                // Sort descending to avoid conflicts when incrementing
                const aIndex = parseInt(a.getAttribute('data-qs-node').split('.').pop(), 10);
                const bIndex = parseInt(b.getAttribute('data-qs-node').split('.').pop(), 10);
                return bIndex - aIndex;
            });
        
        console.log('[QuickSite] Found', siblingsToReindex.length, 'siblings to reindex');
        
        // Reindex BEFORE inserting the new element
        siblingsToReindex.forEach(function(el) {
            const elNodeId = el.getAttribute('data-qs-node');
            const elParts = elNodeId.split('.');
            const elIndex = parseInt(elParts[elParts.length - 1], 10);
            const incrementedIndex = elIndex + 1;
            const incrementedNodeId = parentPath ? parentPath + '.' + incrementedIndex : String(incrementedIndex);
            el.setAttribute('data-qs-node', incrementedNodeId);
            // Also update data-qs-component-node if it matches
            const elCompNode = el.getAttribute('data-qs-component-node');
            if (elCompNode === elNodeId) {
                el.setAttribute('data-qs-component-node', incrementedNodeId);
            }
            console.log('[QuickSite] Reindexed sibling:', elNodeId, '->', incrementedNodeId);
            
            // Also reindex any child elements within this element
            reindexChildNodes(el, struct, elNodeId, incrementedNodeId);
        });
        
        // NOW create and insert the new element
        const temp = document.createElement('div');
        temp.innerHTML = html;
        const newElement = temp.firstElementChild;
        
        if (!newElement) {
            console.error('[QuickSite] Invalid HTML for new node');
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'insertNodeFailed', 
                error: 'Invalid HTML' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Insert based on position
        if (position === 'before') {
            targetEl.parentNode.insertBefore(newElement, targetEl);
        } else if (position === 'after') {
            targetEl.parentNode.insertBefore(newElement, targetEl.nextSibling);
        } else if (position === 'inside') {
            // Insert as first child
            targetEl.insertBefore(newElement, targetEl.firstChild);
        }
        
        console.log('[QuickSite] Node inserted successfully:', newNodeId);
        
        // Select the new element and notify parent (this updates the info bar)
        selectElementAndNotify(newElement);
        newElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // Update an existing node in the DOM (replace its content)
    function updateNodeInDom(struct, nodeId, html) {
        console.log('[QuickSite] Updating node:', { struct, nodeId });
        
        // Find the target element - try direct match first, then within struct container
        let targetEl = document.querySelector(qsNodeSel(struct, nodeId));
        if (!targetEl) {
            const structContainer = document.querySelector(qsStructSel(struct));
            if (structContainer) {
                targetEl = structContainer.querySelector(qsAttr('data-qs-node', nodeId));
            }
        }
        if (!targetEl) {
            console.error('[QuickSite] Target element not found:', struct, nodeId);
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'updateNodeFailed', 
                error: 'Target element not found' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Create a temporary container to parse the HTML
        const temp = document.createElement('div');
        temp.innerHTML = html;
        const newElement = temp.firstElementChild;
        
        if (!newElement) {
            console.error('[QuickSite] Invalid HTML for node update');
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'updateNodeFailed', 
                error: 'Invalid HTML' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Replace the old element with the new one
        targetEl.parentNode.replaceChild(newElement, targetEl);
        
        console.log('[QuickSite] Node updated successfully:', nodeId);
        
        // Re-select the updated element and notify parent (updates info bar)
        selectElementAndNotify(newElement);
    }
    
    // Remove a node from the DOM and reindex siblings
    function removeNodeFromDom(struct, nodeId) {
        console.log('[QuickSite] Removing node:', { struct, nodeId });
        
        // Find the target element - try direct match first, then within struct container
        let targetEl = document.querySelector(qsNodeSel(struct, nodeId));
        if (!targetEl) {
            // If not found with struct attribute, search within struct container
            const structContainer = document.querySelector(qsStructSel(struct));
            if (structContainer) {
                targetEl = structContainer.querySelector(qsAttr('data-qs-node', nodeId));
            }
        }
        if (!targetEl) {
            console.error('[QuickSite] Target element not found:', struct, nodeId);
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'removeNodeFailed', 
                error: 'Target element not found' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Get parent element and identify siblings that need reindexing
        const parent = targetEl.parentNode;
        const nodeIdParts = nodeId.split('.');
        const deletedIndex = parseInt(nodeIdParts[nodeIdParts.length - 1], 10);
        const parentPath = nodeIdParts.slice(0, -1).join('.');
        
        // Remove the element first
        targetEl.remove();
        
        // Reindex siblings with higher indices
        // Search ALL elements with data-qs-node in the entire document for maximum coverage
        const allNodeElements = Array.from(document.querySelectorAll('[data-qs-node]'));
        
        console.log('[QuickSite] Total elements with data-qs-node:', allNodeElements.length);
        console.log('[QuickSite] Looking for siblings with parentPath:', parentPath, 'and index >', deletedIndex);
        
        // Find siblings that need to be decremented (index > deletedIndex)
        const siblingsToReindex = allNodeElements
            .filter(function(el) {
                const elNodeId = el.getAttribute('data-qs-node');
                if (!elNodeId) return false;
                
                const elParts = elNodeId.split('.');
                const elParentPath = elParts.slice(0, -1).join('.');
                const elIndex = parseInt(elParts[elParts.length - 1], 10);
                
                // Same parent path, and index is greater than deleted
                const shouldReindex = elParentPath === parentPath && elIndex > deletedIndex;
                if (shouldReindex) {
                    console.log('[QuickSite] Will reindex:', elNodeId, '(parent:', elParentPath, 'index:', elIndex, ')');
                }
                return shouldReindex;
            })
            .sort(function(a, b) {
                // Sort ascending for decrement (process lower indices first to avoid conflicts)
                const aIndex = parseInt(a.getAttribute('data-qs-node').split('.').pop(), 10);
                const bIndex = parseInt(b.getAttribute('data-qs-node').split('.').pop(), 10);
                return aIndex - bIndex;
            });
        
        console.log('[QuickSite] Found', siblingsToReindex.length, 'siblings to reindex after delete');
        
        siblingsToReindex.forEach(function(el) {
            const elNodeId = el.getAttribute('data-qs-node');
            const elParts = elNodeId.split('.');
            const elIndex = parseInt(elParts[elParts.length - 1], 10);
            
            // Decrement the index
            const newIndex = elIndex - 1;
            const newNodeId = parentPath ? parentPath + '.' + newIndex : String(newIndex);
            el.setAttribute('data-qs-node', newNodeId);
            // Also update data-qs-component-node if it matches
            const elCompNode = el.getAttribute('data-qs-component-node');
            if (elCompNode === elNodeId) {
                el.setAttribute('data-qs-component-node', newNodeId);
            }
            console.log('[QuickSite] Reindexed node:', elNodeId, '->', newNodeId);
            
            // Also reindex any child elements within this element
            reindexChildNodes(el, struct, elNodeId, newNodeId);
        });
        
        console.log('[QuickSite] Node removed successfully:', nodeId);
        
        // Notify parent of success
        window.parent.postMessage({ 
            source: 'quicksite-preview', 
            action: 'removeNodeSuccess', 
            nodeId: nodeId 
        }, ALLOWED_ORIGIN);
        
        clearSelection();
    }
    
    // Helper: reindex child nodes when parent's nodeId changed
    function reindexChildNodes(parentEl, struct, oldParentId, newParentId) {
        // Query all elements with data-qs-node within the parent
        const children = parentEl.querySelectorAll('[data-qs-node]');
        children.forEach(function(child) {
            const childNodeId = child.getAttribute('data-qs-node');
            if (childNodeId && childNodeId.startsWith(oldParentId + '.')) {
                const newChildId = newParentId + childNodeId.substring(oldParentId.length);
                child.setAttribute('data-qs-node', newChildId);
                // Also update data-qs-component-node if it matches
                const compNode = child.getAttribute('data-qs-component-node');
                if (compNode && compNode.startsWith(oldParentId + '.')) {
                    const newCompId = newParentId + compNode.substring(oldParentId.length);
                    child.setAttribute('data-qs-component-node', newCompId);
                } else if (compNode === oldParentId) {
                    child.setAttribute('data-qs-component-node', newParentId);
                }
                console.log('[QuickSite] Reindexed child:', childNodeId, '->', newChildId);
            }
        });
    }
    
    /**
     * Reindex ALL data-qs-node attributes in a struct based on actual DOM positions.
     * Called after a successful move to fix stale node IDs.
     * Walks the DOM tree and recomputes every node ID from scratch.
     *
     * IMPORTANT: Every element in the struct has data-qs-struct, not just roots.
     * We identify true roots by checking that no ancestor also belongs to this struct.
     */
    function reindexAllNodes(struct) {
        console.log('[QuickSite] Reindexing all nodes for struct:', struct);
        
        // All elements with both data-qs-struct and data-qs-node
        const allNodes = document.querySelectorAll(qsStructSel(struct) + '[data-qs-node]');
        
        // True root nodes = no ancestor with the same data-qs-struct + data-qs-node
        const structSelector = qsStructSel(struct) + '[data-qs-node]';
        const rootNodes = Array.from(allNodes).filter(function(el) {
            const parent = el.parentElement;
            if (!parent) return true;
            return !parent.closest(structSelector);
        });
        
        if (rootNodes.length === 0) {
            console.warn('[QuickSite] No root nodes found for struct:', struct);
            return;
        }
        
        console.log('[QuickSite] Found', rootNodes.length, 'root nodes out of', allNodes.length, 'total');
        let reindexCount = 0;
        
        rootNodes.forEach(function(el, index) {
            const oldId = el.getAttribute('data-qs-node');
            // Preserve empty-string root IDs (single-object structures like {tag:"main",...})
            // These use "" for the root and "0","1" for children, unlike array roots
            // which use "0" for root and "0.0","0.1" for children.
            const newId = (oldId === '' && rootNodes.length === 1) ? '' : String(index);
            if (oldId !== newId) {
                el.setAttribute('data-qs-node', newId);
                // Update component-node if it matches
                if (el.getAttribute('data-qs-component-node') === oldId) {
                    el.setAttribute('data-qs-component-node', newId);
                }
                console.log('[QuickSite] Reindexed root:', oldId, '->', newId);
                reindexCount++;
            }
            // Recursively reindex all descendants
            reindexCount += reindexDescendants(el, newId);
        });
        
        console.log('[QuickSite] Reindex complete:', reindexCount, 'nodes updated');
    }
    
    /**
     * Recursively reindex direct structure children of a parent element.
     * "Direct structure children" = descendant elements with data-qs-node 
     * that have no other data-qs-node ancestor between them and parentEl.
     */
    function reindexDescendants(parentEl, parentId) {
        const directChildren = getDirectNodeChildren(parentEl);
        let count = 0;
        
        directChildren.forEach(function(child, index) {
            const oldId = child.getAttribute('data-qs-node');
            // When parentId is empty (single-object root), children are "0","1",etc.
            const newId = parentId === '' ? String(index) : parentId + '.' + index;
            if (oldId !== newId) {
                child.setAttribute('data-qs-node', newId);
                if (child.getAttribute('data-qs-component-node') === oldId) {
                    child.setAttribute('data-qs-component-node', newId);
                }
                console.log('[QuickSite] Reindexed:', oldId, '->', newId);
                count++;
            }
            // Recurse into this child's descendants
            count += reindexDescendants(child, newId);
        });
        
        return count;
    }
    
    /**
     * Get direct structure children of a parent element.
     * Returns elements with data-qs-node that are descendants of parentEl
     * but have no other data-qs-node ancestor between them and parentEl.
     * Preserves DOM order.
     */
    function getDirectNodeChildren(parentEl) {
        const allDescendants = parentEl.querySelectorAll('[data-qs-node]');
        return Array.from(allDescendants).filter(function(el) {
            let current = el.parentElement;
            while (current && current !== parentEl) {
                if (current.hasAttribute('data-qs-node')) return false;
                current = current.parentElement;
            }
            return current === parentEl;
        });
    }
    
    // Duplicate a node in the DOM (clone and insert after with new nodeId)
    function duplicateNodeInDom(struct, sourceNodeId, newNodeId, html) {
        console.log('[QuickSite] Duplicating node:', { struct, sourceNodeId, newNodeId, hasHtml: !!html });
        
        // Find the source element - try direct match first, then within struct container
        let sourceEl = document.querySelector(qsNodeSel(struct, sourceNodeId));
        if (!sourceEl) {
            const structContainer = document.querySelector(qsStructSel(struct));
            if (structContainer) {
                sourceEl = structContainer.querySelector(qsAttr('data-qs-node', sourceNodeId));
            }
        }
        if (!sourceEl) {
            console.error('[QuickSite] Source element not found:', struct, sourceNodeId);
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'duplicateNodeFailed', 
                error: 'Source element not found' 
            }, ALLOWED_ORIGIN);
            return;
        }
        
        // Parse the newNodeId to get parent path and index
        const nodeIdParts = newNodeId.split('.');
        const newIndex = parseInt(nodeIdParts[nodeIdParts.length - 1], 10);
        const parentPath = nodeIdParts.slice(0, -1).join('.');
        
        // FIRST: Reindex siblings that need to shift (index >= newIndex)
        // Search ALL elements with data-qs-node in the entire document for maximum coverage
        const allNodeElements = Array.from(document.querySelectorAll('[data-qs-node]'));
        
        console.log('[QuickSite] Total elements with data-qs-node:', allNodeElements.length);
        console.log('[QuickSite] Looking for siblings with parentPath:', parentPath, 'and index >=', newIndex);
        
        // Process in reverse order (highest index first) to avoid conflicts
        const siblingsToReindex = allNodeElements
            .filter(function(el) {
                const elNodeId = el.getAttribute('data-qs-node');
                if (!elNodeId) return false;
                const elParts = elNodeId.split('.');
                const elParentPath = elParts.slice(0, -1).join('.');
                const elIndex = parseInt(elParts[elParts.length - 1], 10);
                const shouldReindex = elParentPath === parentPath && elIndex >= newIndex;
                if (shouldReindex) {
                    console.log('[QuickSite] Will reindex:', elNodeId, '(parent:', elParentPath, 'index:', elIndex, ')');
                }
                return shouldReindex;
            })
            .sort(function(a, b) {
                const aIndex = parseInt(a.getAttribute('data-qs-node').split('.').pop(), 10);
                const bIndex = parseInt(b.getAttribute('data-qs-node').split('.').pop(), 10);
                return bIndex - aIndex; // Descending order
            });
        
        console.log('[QuickSite] Found', siblingsToReindex.length, 'siblings to reindex');
        
        siblingsToReindex.forEach(function(el) {
            const elNodeId = el.getAttribute('data-qs-node');
            const elParts = elNodeId.split('.');
            const elIndex = parseInt(elParts[elParts.length - 1], 10);
            const incrementedIndex = elIndex + 1;
            const incrementedNodeId = parentPath ? parentPath + '.' + incrementedIndex : String(incrementedIndex);
            el.setAttribute('data-qs-node', incrementedNodeId);
            // Also update data-qs-component-node if it matches
            const elCompNode = el.getAttribute('data-qs-component-node');
            if (elCompNode === elNodeId) {
                el.setAttribute('data-qs-component-node', incrementedNodeId);
            }
            console.log('[QuickSite] Reindexed sibling:', elNodeId, '->', incrementedNodeId);
            
            // Also reindex any child elements
            reindexChildNodes(el, struct, elNodeId, incrementedNodeId);
        });
        
        // THEN: Insert the new element using server-rendered HTML (correct translations)
        // or fall back to cloneNode if no HTML provided
        let newEl;
        if (html) {
            const temp = document.createElement('div');
            temp.innerHTML = html;
            newEl = temp.firstElementChild;
        }
        if (!newEl) {
            // Fallback: deep clone source element and update node IDs
            newEl = sourceEl.cloneNode(true);
            newEl.setAttribute('data-qs-node', newNodeId);
            const children = newEl.querySelectorAll('[data-qs-node]');
            children.forEach(function(child) {
                const childNodeId = child.getAttribute('data-qs-node');
                if (childNodeId && childNodeId.startsWith(sourceNodeId + '.')) {
                    const suffix = childNodeId.substring(sourceNodeId.length);
                    child.setAttribute('data-qs-node', newNodeId + suffix);
                }
            });
        }
        
        // Remove selected class from new element
        newEl.classList.remove('qs-selected', 'qs-hover');
        
        // Insert after source element
        sourceEl.parentNode.insertBefore(newEl, sourceEl.nextSibling);
        
        console.log('[QuickSite] Node duplicated successfully:', newNodeId);
        
        // Select the new element and notify parent (updates info bar)
        selectElementAndNotify(newEl);
        newEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    /**
     * Show or hide all elements belonging to a structure (e.g., 'menu' or 'footer').
     * Used by route layout toggles for live preview.
     */
    function toggleStructVisibility(struct, show) {
        const elements = document.querySelectorAll(qsStructSel(struct));
        elements.forEach(function(el) {
            el.style.display = show ? '' : 'none';
        });
        console.log('[QuickSite] ' + (show ? 'Showing' : 'Hiding') + ' struct:', struct, '(' + elements.length + ' elements)');
    }
    
    /**
     * Scan all data-qs-node elements and apply min-height/min-width
     * on axes where the element has zero computed dimension.
     */
    function scanEmptyElements() {
        clearEmptyHints();
        var nodes = document.querySelectorAll('[data-qs-node]');
        nodes.forEach(function(el) {
            var rect = el.getBoundingClientRect();
            var needH = rect.height < 1;
            var needW = rect.width < 1;
            if (needH || needW) {
                if (needH) el.style.minHeight = '24px';
                if (needW) el.style.minWidth = '24px';
                el.classList.add('qs-empty-hint');
            }
        });
    }
    
    /**
     * Remove all empty-element hints previously applied by scanEmptyElements.
     */
    function clearEmptyHints() {
        var hinted = document.querySelectorAll('.qs-empty-hint');
        hinted.forEach(function(el) {
            el.style.minHeight = '';
            el.style.minWidth = '';
            el.classList.remove('qs-empty-hint');
        });
    }
    
    function setMode(mode) {
        // Clean up previous mode
        if (currentMode === 'drag') {
            disableDragMode();
        }
        if (currentMode === 'text') {
            disableTextMode();
        }
        if (currentMode === 'style') {
            disableStyleMode();
        }
        if (currentMode === 'js') {
            disableJsMode();
        }
        
        currentMode = mode;
        clearHover();
        // Preserve selection for modes that need it (select, style, js, add)
        if (mode !== 'select' && mode !== 'style' && mode !== 'js' && mode !== 'add') clearSelection();
        
        // Enable new mode
        if (mode === 'drag') {
            enableDragMode();
        }
        if (mode === 'text') {
            enableTextMode();
        }
        if (mode === 'style') {
            enableStyleMode();
        }
        if (mode === 'js') {
            enableJsMode();
        }
        
        // Show empty-element hints in select and drag modes
        if (mode === 'select' || mode === 'drag') {
            scanEmptyElements();
        } else {
            clearEmptyHints();
        }
    }
    
    function clearHover() {
        if (hoveredElement) {
            hoveredElement.classList.remove('qs-hover');
            hoveredElement = null;
        }
    }
    
    function clearSelection() {
        if (selectedElement) {
            selectedElement.classList.remove('qs-selected');
            selectedElement = null;
        }
    }
    
    function highlightByNode(struct, node) {
        clearSelection();
        const el = document.querySelector(qsNodeSel(struct, node));
        if (el) {
            selectedElement = el;
            el.classList.add('qs-selected');
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    /**
     * Select a node by struct+node, notify parent, and scroll to it.
     * Used for auto-selecting layout elements (menu/footer) after navigation.
     */
    function selectByNode(struct, node) {
        clearSelection();
        const el = document.querySelector(qsNodeSel(struct, node));
        if (el) {
            selectElementAndNotify(el);
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    // ===== Selector Browser Functions =====
    let selectorHoveredElements = [];
    let selectorSelectedElements = [];
    
    function highlightBySelector(selector) {
        // Clear previous hover highlights
        clearSelectorHover();
        
        try {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                if (!ignoreTags.includes(el.tagName)) {
                    el.classList.add('qs-selector-hover');
                    selectorHoveredElements.push(el);
                }
            });
        } catch (e) {
            console.warn('[QuickSite] Invalid selector:', selector, e);
        }
    }
    
    function selectBySelector(selector) {
        // Clear previous selection
        clearSelectorSelection();
        
        try {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                if (!ignoreTags.includes(el.tagName)) {
                    el.classList.add('qs-selector-selected');
                    selectorSelectedElements.push(el);
                }
            });
            
            // Scroll to first matched element
            if (selectorSelectedElements.length > 0) {
                selectorSelectedElements[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch (e) {
            console.warn('[QuickSite] Invalid selector:', selector, e);
        }
    }
    
    function clearSelectorHover() {
        selectorHoveredElements.forEach(el => {
            el.classList.remove('qs-selector-hover');
        });
        selectorHoveredElements = [];
    }
    
    function clearSelectorSelection() {
        selectorSelectedElements.forEach(el => {
            el.classList.remove('qs-selector-selected');
        });
        selectorSelectedElements = [];
    }
    
    function clearSelectorHighlight() {
        clearSelectorHover();
        clearSelectorSelection();
    }
    
    // Find an embed child of `container` whose bounding rect contains (mx, my)
    function findEmbedAtPoint(container, mx, my) {
        var embeds = container.querySelectorAll(
            'iframe[data-qs-struct], video[data-qs-struct], audio[data-qs-struct], embed[data-qs-struct], object[data-qs-struct]'
        );
        for (var i = 0; i < embeds.length; i++) {
            var rect = embeds[i].getBoundingClientRect();
            if (mx >= rect.left && mx <= rect.right && my >= rect.top && my <= rect.bottom) {
                return embeds[i];
            }
        }
        return null;
    }
    
    // Find the selectable target (bubble up to component if inside one)
    function getSelectableTarget(el, mouseX, mouseY) {
        if (!el || el === document.body || el === document.documentElement) return null;
        if (ignoreTags.includes(el.tagName)) return null;
        
        // Check if element has editor attributes
        if (!el.hasAttribute('data-qs-struct')) {
            // No editor attributes, find closest parent with them
            el = el.closest('[data-qs-struct]');
            if (!el) return null;
        }
        
        // If mouse coords provided, check if an embed child is under the cursor
        if (mouseX !== undefined && mouseY !== undefined) {
            var embedHit = findEmbedAtPoint(el, mouseX, mouseY);
            if (embedHit) {
                el = embedHit;
            }
        }
        
        // If inside a component, bubble up to component root
        if (el.hasAttribute('data-qs-in-component') && !el.hasAttribute('data-qs-component')) {
            const componentRoot = el.closest('[data-qs-component]');
            if (componentRoot) {
                return componentRoot;
            }
        }
        
        return el;
    }
    
    // Get draggable elements (only direct children of the body struct or component children)
    function getDraggableTarget(el, mouseX, mouseY) {
        if (!el || el === document.body || el === document.documentElement) return null;
        if (ignoreTags.includes(el.tagName)) return null;
        
        // Check if element has editor attributes
        if (!el.hasAttribute('data-qs-struct')) {
            el = el.closest('[data-qs-struct]');
            if (!el) return null;
        }
        
        // If mouse coords provided, check if an embed child is under the cursor
        if (mouseX !== undefined && mouseY !== undefined) {
            var embedHit = findEmbedAtPoint(el, mouseX, mouseY);
            if (embedHit) {
                el = embedHit;
            }
        }
        
        // For components, treat the whole component as one draggable unit
        if (el.hasAttribute('data-qs-in-component') && !el.hasAttribute('data-qs-component')) {
            const componentRoot = el.closest('[data-qs-component]');
            if (componentRoot) return componentRoot;
        }
        
        return el;
    }
    
    function getElementInfo(el) {
        // Get editor data attributes
        const struct = el.getAttribute('data-qs-struct');
        const node = el.getAttribute('data-qs-node');
        const component = el.getAttribute('data-qs-component');
        const componentNode = el.getAttribute('data-qs-component-node');
        
        const id = el.id || null;
        const classes = el.className && typeof el.className === 'string' 
            ? el.className.replace(/qs-(hover|selected|draggable|dragging|drag-over|text-editable|text-editing)/g, '').trim() 
            : null;
        
        // Get direct text content
        let textContent = null;
        for (const child of el.childNodes) {
            if (child.nodeType === 3 && child.textContent.trim()) {
                textContent = child.textContent.trim().substring(0, 100);
                break;
            }
        }
        
        // Get textKeys from element or its children
        const textKeys = [];
        // Check if this element itself has a textKey
        if (el.hasAttribute('data-qs-textkey')) {
            textKeys.push(el.getAttribute('data-qs-textkey'));
        }
        // Also check immediate children with textKeys
        el.querySelectorAll('[data-qs-textkey]').forEach(function(textEl) {
            const key = textEl.getAttribute('data-qs-textkey');
            if (key && !textKeys.includes(key)) {
                textKeys.push(key);
            }
        });
        
        // Navigation info - check for parent, siblings, children
        const parent = el.parentElement ? el.parentElement.closest('[data-qs-node]') : null;
        const hasParent = !!parent;
        
        // Get siblings with data-qs-node
        let hasPrevSibling = false;
        let hasNextSibling = false;
        if (el.parentElement) {
            const siblings = Array.from(el.parentElement.children).filter(function(c) {
                return c.hasAttribute('data-qs-node');
            });
            const idx = siblings.indexOf(el);
            hasPrevSibling = idx > 0;
            hasNextSibling = idx < siblings.length - 1;
        }
        
        // Check for children with data-qs-node
        const hasChildren = el.querySelector('[data-qs-node]') !== null;
        
        return {
            // Editor info
            struct: struct,
            node: node,
            component: component,
            componentNode: componentNode,
            isComponent: !!component,
            // DOM info
            tag: el.tagName.toLowerCase(),
            id: id,
            classes: classes || null,
            childCount: el.children.length,
            textContent: textContent,
            textKeys: textKeys,
            // Navigation info
            hasParent: hasParent,
            hasPrevSibling: hasPrevSibling,
            hasNextSibling: hasNextSibling,
            hasChildren: hasChildren
        };
    }
    
    // ===== DRAG MODE FUNCTIONALITY =====
    
    function enableDragMode() {
        // Mark all draggable elements
        document.querySelectorAll('[data-qs-node]').forEach(el => {
            // Only mark top-level nodes or component roots as draggable
            if (!el.hasAttribute('data-qs-in-component') || el.hasAttribute('data-qs-component')) {
                el.classList.add('qs-draggable');
            }
        });
        
        // Create drop indicator
        dropIndicator = document.createElement('div');
        dropIndicator.className = 'qs-drop-indicator';
        dropIndicator.style.display = 'none';
        document.body.appendChild(dropIndicator);
        
        // Reset Phase 1/2 state
        dragSelectedElement = null;
        dragLocked = false;
        dragLockPersistent = false;
        
        // Notify parent to show drag options and set initial hint
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'dragModeReady',
            undoCount: dragUndoStack.length,
            redoCount: dragRedoStack.length
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Drag mode enabled (Phase 1: select)');
    }
    
    function disableDragMode() {
        // Remove draggable class from all elements
        document.querySelectorAll('.qs-draggable').forEach(el => {
            el.classList.remove('qs-draggable', 'qs-dragging', 'qs-drag-over', 'qs-drag-shifting', 'qs-drag-rollback', 'qs-drag-selected', 'qs-drag-locked');
        });
        
        // Remove drop indicator
        if (dropIndicator) {
            dropIndicator.remove();
            dropIndicator = null;
        }
        
        // Remove ghost
        if (dragGhost) {
            dragGhost.remove();
            dragGhost = null;
        }
        
        // Clear long-press timer
        if (longPressTimer) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        
        isDragging = false;
        dragActivated = false;
        dragElement = null;
        dragPendingElement = null;
        dragInfo = null;
        dropTarget = null;
        dropPosition = null;
        dragOriginalParent = null;
        dragOriginalNextSibling = null;
        dragSelectedElement = null;
        dragLocked = false;
        dragLockPersistent = false;
        
        // Note: undo/redo stacks are cleared when leaving drag mode
        dragUndoStack = [];
        dragRedoStack = [];
        
        console.log('[QuickSite] Drag mode disabled');
    }
    
    // ===== DRAG PHASE 1: Selection =====
    
    function dragSelectElement(el) {
        if (!el) return;
        // Clear previous selection
        if (dragSelectedElement) {
            dragSelectedElement.classList.remove('qs-drag-selected', 'qs-drag-locked');
        }
        dragSelectedElement = el;
        dragLocked = false;
        el.classList.add('qs-drag-selected');
        
        // Send element info to parent for info panel
        const info = getElementInfo(el);
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'dragElementSelected',
            ...info
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Drag Phase 1: selected', info.tag, info.node);
    }
    
    function dragLockElement(persistent) {
        if (!dragSelectedElement) return;
        dragLocked = true;
        dragLockPersistent = !!persistent;
        dragSelectedElement.classList.remove('qs-drag-selected');
        dragSelectedElement.classList.add('qs-drag-locked');
        
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'dragElementLocked',
            persistent: dragLockPersistent
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Drag Phase 2: locked', dragLockPersistent ? '(persistent)' : '(momentary)');
    }
    
    function dragUnlockElement() {
        if (!dragSelectedElement) return;
        dragLocked = false;
        dragLockPersistent = false;
        dragSelectedElement.classList.remove('qs-drag-locked');
        dragSelectedElement.classList.add('qs-drag-selected');
        
        const navInfo = getDragNavInfo(dragSelectedElement);
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'dragElementUnlocked',
            ...navInfo
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Drag: unlocked, back to Phase 1');
    }
    
    function toggleDragLockPersistent() {
        if (!dragSelectedElement) return;
        if (dragLocked && dragLockPersistent) {
            // Unlock
            dragUnlockElement();
        } else {
            // Lock (persistent)
            dragLockElement(true);
        }
    }
    
    // ===== DRAG NAVIGATION HELPERS =====
    
    function getDragNavInfo(el) {
        if (!el) return { hasParent: false, hasPrevSibling: false, hasNextSibling: false, hasChildren: false };
        const parent = el.parentElement ? el.parentElement.closest('[data-qs-node]') : null;
        let hasPrevSibling = false, hasNextSibling = false;
        if (el.parentElement) {
            const siblings = Array.from(el.parentElement.children).filter(c => c.hasAttribute('data-qs-node'));
            const idx = siblings.indexOf(el);
            hasPrevSibling = idx > 0;
            hasNextSibling = idx < siblings.length - 1;
        }
        return {
            hasParent: !!parent,
            hasPrevSibling: hasPrevSibling,
            hasNextSibling: hasNextSibling,
            hasChildren: el.querySelector('[data-qs-node]') !== null
        };
    }
    
    // ===== DRAG ARROW NAVIGATION (Phase 1) =====
    
    function dragNavigateParent() {
        if (!dragSelectedElement || dragLocked) return false;
        const parent = dragSelectedElement.parentElement ? dragSelectedElement.parentElement.closest('[data-qs-node]') : null;
        if (parent && parent.classList.contains('qs-draggable')) {
            dragSelectElement(parent);
            parent.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }
        return false;
    }
    
    function dragNavigateChild() {
        if (!dragSelectedElement || dragLocked) return false;
        const firstChild = dragSelectedElement.querySelector('[data-qs-node]');
        if (firstChild && firstChild.classList.contains('qs-draggable')) {
            dragSelectElement(firstChild);
            firstChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }
        return false;
    }
    
    function dragNavigatePrevSibling() {
        if (!dragSelectedElement || dragLocked) return false;
        if (!dragSelectedElement.parentElement) return false;
        const siblings = Array.from(dragSelectedElement.parentElement.children).filter(c => c.hasAttribute('data-qs-node'));
        const idx = siblings.indexOf(dragSelectedElement);
        if (idx > 0) {
            dragSelectElement(siblings[idx - 1]);
            siblings[idx - 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }
        return false;
    }
    
    function dragNavigateNextSibling() {
        if (!dragSelectedElement || dragLocked) return false;
        if (!dragSelectedElement.parentElement) return false;
        const siblings = Array.from(dragSelectedElement.parentElement.children).filter(c => c.hasAttribute('data-qs-node'));
        const idx = siblings.indexOf(dragSelectedElement);
        if (idx < siblings.length - 1) {
            dragSelectElement(siblings[idx + 1]);
            siblings[idx + 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }
        return false;
    }
    
    // ===== DRAG UNDO/REDO =====
    
    function dragPushUndo(entry) {
        dragUndoStack.push(entry);
        dragRedoStack = []; // Clear redo on new move
        notifyDragStackState();
    }
    
    function notifyDragStackState() {
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'dragStackUpdate',
            undoCount: dragUndoStack.length,
            redoCount: dragRedoStack.length
        }, ALLOWED_ORIGIN);
    }
    
    function dragUndoMove() {
        if (dragUndoStack.length === 0) return;
        const entry = dragUndoStack.pop();
        
        // Store current position for redo
        const redoEntry = {
            element: entry.element,
            oldParent: entry.element.parentNode,
            oldNextSibling: entry.element.nextElementSibling,
            newParent: entry.oldParent,
            newNextSibling: entry.oldNextSibling,
            struct: entry.struct,
            sourceInfo: entry.targetInfo,
            targetInfo: entry.sourceInfo
        };
        
        // Move element back to original position
        if (entry.oldNextSibling && entry.oldNextSibling.parentNode === entry.oldParent) {
            entry.oldParent.insertBefore(entry.element, entry.oldNextSibling);
        } else {
            entry.oldParent.appendChild(entry.element);
        }
        
        // Push redo before async API call
        dragRedoStack.push(redoEntry);
        notifyDragStackState();
        
        // Re-select the element at its restored position
        dragSelectElement(entry.element);
        entry.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Compute move params from current DOM state and call API
        performUndoRedoApiCall(entry.element, entry.struct, 'undo');
    }
    
    function dragRedoMove() {
        if (dragRedoStack.length === 0) return;
        const entry = dragRedoStack.pop();
        
        // Store current position for undo
        const undoEntry = {
            element: entry.element,
            oldParent: entry.element.parentNode,
            oldNextSibling: entry.element.nextElementSibling,
            newParent: entry.newParent,
            newNextSibling: entry.newNextSibling,
            struct: entry.struct,
            sourceInfo: entry.targetInfo,
            targetInfo: entry.sourceInfo
        };
        
        // Move element to redo position
        if (entry.newNextSibling && entry.newNextSibling.parentNode === entry.newParent) {
            entry.newParent.insertBefore(entry.element, entry.newNextSibling);
        } else {
            entry.newParent.appendChild(entry.element);
        }
        
        // Push undo
        dragUndoStack.push(undoEntry);
        notifyDragStackState();
        
        // Re-select the element at its new position
        dragSelectElement(entry.element);
        entry.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Call API to sync
        performUndoRedoApiCall(entry.element, entry.struct, 'redo');
    }
    
    /**
     * After undo/redo DOM move, compute API params from current DOM position
     * and post 'elementMoved' to parent for server sync.
     */
    function performUndoRedoApiCall(element, struct, action) {
        // After DOM move, figure out the element's new position relative to a sibling or parent
        const sourceInfo = getElementInfo(element);
        
        // Find the reference element: previous sibling or parent
        let targetEl = null;
        let position = null;
        
        const prevSibling = element.previousElementSibling;
        const nextSibling = element.nextElementSibling;
        
        if (prevSibling && prevSibling.hasAttribute('data-qs-node')) {
            targetEl = prevSibling;
            position = 'after';
        } else if (nextSibling && nextSibling.hasAttribute('data-qs-node')) {
            targetEl = nextSibling;
            position = 'before';
        } else {
            // Only child — moved inside parent
            const parentNode = element.parentElement ? element.parentElement.closest('[data-qs-node]') : null;
            if (parentNode) {
                targetEl = parentNode;
                position = 'inside';
            }
        }
        
        if (!targetEl || !position) {
            console.warn('[QuickSite] Undo/redo: could not determine target element');
            return;
        }
        
        const targetInfo = getElementInfo(targetEl);
        
        window.parent.postMessage({
            source: 'quicksite-preview',
            action: 'elementMoved',
            sourceElement: sourceInfo,
            targetElement: targetInfo,
            position: position,
            isUndoRedo: true,
            undoRedoAction: action
        }, ALLOWED_ORIGIN);
    }
    
    // ===== TEXT MODE FUNCTIONALITY =====
    
    function enableTextMode() {
        // Mark all text-editable elements
        document.querySelectorAll('[data-qs-textkey]').forEach(el => {
            el.classList.add('qs-text-editable');
        });
        console.log('[QuickSite] Text mode enabled');
    }
    
    function disableTextMode() {
        // Cancel any active editing
        if (editingElement) {
            cancelTextEdit();
        }
        
        // Remove editable class from all elements
        document.querySelectorAll('.qs-text-editable').forEach(el => {
            el.classList.remove('qs-text-editable', 'qs-text-editing');
        });
        console.log('[QuickSite] Text mode disabled');
    }
    
    function startTextEdit(el) {
        if (editingElement) {
            // Save current edit before starting new one
            finishTextEdit();
        }
        
        editingElement = el;
        editingTextKey = el.getAttribute('data-qs-textkey');
        originalText = el.textContent;
        
        el.classList.add('qs-text-editing');
        el.setAttribute('contenteditable', 'true');
        el.focus();
        
        // Select all text for easy replacement
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(el);
        selection.removeAllRanges();
        selection.addRange(range);
        
        // Send element info to parent for info bar display
        // Text-only nodes have data-qs-node directly on the span
        const isTextOnly = el.hasAttribute('data-qs-textonly');
        const nodeEl = isTextOnly ? el : el.closest('[data-qs-node]');
        if (nodeEl) {
            const elementInfo = isTextOnly ? {
                struct: el.getAttribute('data-qs-struct'),
                node: el.getAttribute('data-qs-node'),
                component: null,
                componentNode: null,
                isComponent: false,
                tag: null,
                id: null,
                classes: null,
                childCount: 0,
                textContent: el.textContent.trim().substring(0, 100),
                textKeys: [editingTextKey],
                textOnly: true,
                hasParent: true,
                hasPrevSibling: false,
                hasNextSibling: false,
                hasChildren: false
            } : getElementInfo(nodeEl);
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'textElementInfo',
                element: elementInfo
            }, ALLOWED_ORIGIN);
        }
        
        console.log('[QuickSite] Started editing:', editingTextKey);
    }
    
    function finishTextEdit() {
        if (!editingElement) return;
        
        const newText = editingElement.textContent.trim();
        const textKey = editingTextKey;
        const struct = editingElement.closest('[data-qs-struct]')?.getAttribute('data-qs-struct') || '';
        
        // Clean up editing state
        editingElement.classList.remove('qs-text-editing');
        editingElement.setAttribute('contenteditable', 'false');
        
        // Only save if text actually changed
        if (newText !== originalText) {
            console.log('[QuickSite] Text changed:', textKey, originalText, '->', newText);
            
            window.parent.postMessage({ 
                source: 'quicksite-preview', 
                action: 'textEdited',
                textKey: textKey,
                newValue: newText,
                oldValue: originalText,
                structure: struct
            }, ALLOWED_ORIGIN);
        } else {
            console.log('[QuickSite] Text unchanged, not saving');
        }
        
        editingElement = null;
        editingTextKey = null;
        originalText = null;
    }
    
    function cancelTextEdit() {
        if (!editingElement) return;
        
        // Restore original text
        editingElement.textContent = originalText;
        editingElement.classList.remove('qs-text-editing');
        editingElement.setAttribute('contenteditable', 'false');
        
        console.log('[QuickSite] Edit cancelled, restored:', editingTextKey);
        
        editingElement = null;
        editingTextKey = null;
        originalText = null;
    }
    
    // ===== STYLE MODE FUNCTIONALITY =====
    
    function enableStyleMode() {
        // Mark all styleable elements
        document.querySelectorAll('[data-qs-node]').forEach(el => {
            el.classList.add('qs-styleable');
        });
        console.log('[QuickSite] Style mode enabled');
    }
    
    function disableStyleMode() {
        // Remove styleable class from all elements
        document.querySelectorAll('.qs-styleable').forEach(el => {
            el.classList.remove('qs-styleable', 'qs-style-selected');
        });
        console.log('[QuickSite] Style mode disabled');
    }
    
    function getStyleInfo(el) {
        const computed = window.getComputedStyle(el);
        
        // Get class list (filter out our overlay classes)
        const classList = Array.from(el.classList).filter(c => 
            !c.startsWith('qs-')
        );
        
        // CSS properties we expose in the simplified panel
        const commonProps = [
            'padding', 'margin',
            'color', 'background-color',
            'font-size', 'font-weight',
            'width', 'max-width',
            'border-radius',
            'display', 'gap'
        ];
        
        const styles = {};
        commonProps.forEach(prop => {
            styles[prop] = computed.getPropertyValue(prop);
        });
        
        // Detect CSS variables in use (check inline style)
        const cssVars = [];
        const inlineStyle = el.getAttribute('style') || '';
        const varMatches = inlineStyle.matchAll(/var\((--[\w-]+)\)/g);
        for (const match of varMatches) {
            cssVars.push(match[1]);
        }
        
        // Build selector suggestion
        const tag = el.tagName.toLowerCase();
        let selector = tag;
        if (classList.length > 0) {
            selector = '.' + classList[0]; // Primary class
        } else if (el.id) {
            selector = '#' + el.id;
        }
        
        return {
            selector: selector,
            tag: tag,
            id: el.id || null,
            classList: classList,
            styles: styles,
            cssVars: cssVars
        };
    }
    
    // Clear style selection (called from parent)
    function clearStyleSelection() {
        document.querySelectorAll('.qs-style-selected').forEach(el => {
            el.classList.remove('qs-style-selected');
        });
    }
    
    // Apply live style preview to an element
    function applyLiveStyle(struct, nodeId, property, value) {
        const selector = qsNodeSel(struct, nodeId);
        const el = document.querySelector(selector);
        if (el) {
            // Convert property to camelCase for style property
            const camelProp = property.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
            el.style[camelProp] = value;
            console.log('[QuickSite] Live style applied:', property, '=', value);
        }
    }
    
    // ===== JS MODE FUNCTIONALITY =====
    
    // Event attributes to check for interactions
    const interactionEvents = ['onclick', 'ondblclick', 'onmouseover', 'onmouseout', 
        'onmouseenter', 'onmouseleave', 'onfocus', 'onblur', 'oninput', 'onchange',
        'onsubmit', 'onreset', 'ontoggle', 'onplay', 'onpause', 'onended'];
    
    let jsTooltip = null;
    
    function hasInteractions(el) {
        // Check if element has any event attributes with QS.* calls
        // ({{call:...}} gets transformed to QS.functionName(...) at render time)
        for (const event of interactionEvents) {
            const attr = el.getAttribute(event);
            if (attr && (attr.includes('QS.') || attr.includes('{{call:'))) {
                return true;
            }
        }
        return false;
    }
    
    function getInteractionSummary(el) {
        // Get summary of all interactions on element
        const summary = [];
        for (const event of interactionEvents) {
            const attr = el.getAttribute(event);
            if (attr && (attr.includes('QS.') || attr.includes('{{call:'))) {
                // Extract QS.* function calls
                const qsMatches = attr.match(/QS\.(\w+)\([^)]*\)/g) || [];
                const callMatches = attr.match(/\{\{call:([^}]+)\}\}/g) || [];
                
                const funcs = [];
                // Parse QS.func() format
                qsMatches.forEach(m => {
                    funcs.push(m.replace('QS.', ''));
                });
                // Parse {{call:...}} format (fallback)
                callMatches.forEach(m => {
                    const inner = m.replace('{{call:', '').replace('}}', '');
                    const parts = inner.split(':');
                    funcs.push(parts[0] + '(' + (parts.slice(1).join(',') || '') + ')');
                });
                
                if (funcs.length > 0) {
                    summary.push(event + ': ' + funcs.join(', '));
                }
            }
        }
        return summary.join('\n');
    }
    
    function showJsTooltip(el, e) {
        if (!hasInteractions(el)) return;
        
        if (!jsTooltip) {
            jsTooltip = document.createElement('div');
            jsTooltip.className = 'qs-interaction-tooltip';
            document.body.appendChild(jsTooltip);
        }
        
        const summary = getInteractionSummary(el);
        if (!summary) return;
        
        jsTooltip.textContent = summary;
        jsTooltip.style.display = 'block';
        jsTooltip.style.left = (e.clientX + 15) + 'px';
        jsTooltip.style.top = (e.clientY + 15) + 'px';
    }
    
    function hideJsTooltip() {
        if (jsTooltip) {
            jsTooltip.style.display = 'none';
        }
    }
    
    function enableJsMode() {
        // Mark all interactable elements (elements with data-qs-node)
        document.querySelectorAll('[data-qs-node]').forEach(el => {
            el.classList.add('qs-interactable');
            
            // Mark elements that have interactions with badge
            if (hasInteractions(el)) {
                el.classList.add('qs-has-interaction');
            }
            
            // Add hover listener for tooltip
            el._jsHoverHandler = (e) => showJsTooltip(el, e);
            el._jsLeaveHandler = () => hideJsTooltip();
            el.addEventListener('mouseenter', el._jsHoverHandler);
            el.addEventListener('mouseleave', el._jsLeaveHandler);
        });
        console.log('[QuickSite] JS mode enabled');
    }
    
    function disableJsMode() {
        // Remove interactable class and badges from all elements
        document.querySelectorAll('.qs-interactable').forEach(el => {
            el.classList.remove('qs-interactable', 'qs-js-selected', 'qs-has-interaction');
            
            // Remove hover listeners
            if (el._jsHoverHandler) {
                el.removeEventListener('mouseenter', el._jsHoverHandler);
                el.removeEventListener('mouseleave', el._jsLeaveHandler);
                delete el._jsHoverHandler;
                delete el._jsLeaveHandler;
            }
        });
        hideJsTooltip();
        console.log('[QuickSite] JS mode disabled');
    }
    
    // Clear JS selection (called from parent)
    function clearJsSelection() {
        document.querySelectorAll('.qs-js-selected').forEach(el => {
            el.classList.remove('qs-js-selected');
        });
    }
    
    function canDropAt(source, target, position) {
        if (!source || !target) return false;
        if (source === target) return false;
        
        // Can't drop a parent into/around its own descendant
        // DOM throws HierarchyRequestError: "The new child is an ancestor of the parent"
        if (source.contains(target)) return false;
        
        // Must be in the same structure (page, menu, footer, component)
        const sourceStruct = source.getAttribute('data-qs-struct');
        const targetStruct = target.getAttribute('data-qs-struct');
        if (sourceStruct !== targetStruct) return false;
        
        // For 'inside': also verify target can accept children
        // (it already has a data-qs-node, so it represents a structure node)
        // Cross-level moves are allowed. The server API fully supports this.
        return true;
    }
    
    function showDropIndicator(targetEl, position) {
        if (!dropIndicator || !targetEl) return;
        
        const rect = targetEl.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        
        if (position === 'inside') {
            // Show as a border around the whole element
            dropIndicator.style.display = 'block';
            dropIndicator.style.left = (rect.left + scrollLeft) + 'px';
            dropIndicator.style.top = (rect.top + scrollTop) + 'px';
            dropIndicator.style.width = rect.width + 'px';
            dropIndicator.style.height = rect.height + 'px';
            dropIndicator.classList.add('qs-drop-inside');
        } else {
            dropIndicator.classList.remove('qs-drop-inside');
            dropIndicator.style.height = '4px';
            dropIndicator.style.display = 'block';
            dropIndicator.style.left = (rect.left + scrollLeft) + 'px';
            dropIndicator.style.width = rect.width + 'px';
            
            if (position === 'before') {
                dropIndicator.style.top = (rect.top + scrollTop - 2) + 'px';
            } else {
                dropIndicator.style.top = (rect.bottom + scrollTop - 2) + 'px';
            }
        }
    }
    
    function hideDropIndicator() {
        if (dropIndicator) {
            dropIndicator.style.display = 'none';
            dropIndicator.classList.remove('qs-drop-inside');
            dropIndicator.style.height = '4px';
        }
    }
    
    /**
     * Check if the intended drop represents a meaningful change from the element's
     * original position. This is checked BEFORE any DOM move, comparing against
     * the element's current (original) DOM position.
     */
    function isMeaningfulMove(dragEl, target, position) {
        if (!target || !position) return false;
        
        if (position === 'inside') {
            // Moving inside a different parent is always meaningful.
            // Even if already a child — appendChild moves to last, which may differ.
            return true;
        }
        if (position === 'before') {
            // Not meaningful if element is already immediately before target
            return dragEl.nextElementSibling !== target;
        }
        if (position === 'after') {
            // Not meaningful if element is already immediately after target
            return target.nextElementSibling !== dragEl;
        }
        return false;
    }
    
    function handleDragStart(e, el) {
        // Clear any leftover state from previous drag
        if (dragElement) {
            dragElement.classList.remove('qs-dragging');
        }
        if (dragGhost) {
            dragGhost.remove();
            dragGhost = null;
        }
        dragElement = null;
        dragOriginalParent = null;
        dragOriginalNextSibling = null;
        
        // Don't activate drag immediately — wait for threshold
        dragPendingElement = el;
        dragStartX = e.clientX;
        dragStartY = e.clientY;
        isDragging = true;
        dragActivated = false;
        
        // Capture element info NOW (original IDs before any DOM moves)
        dragInfo = getElementInfo(el);
        
        console.log('[QuickSite] Drag pending:', dragInfo);
    }
    
    function activateDrag(e) {
        if (!dragPendingElement) return;
        
        dragElement = dragPendingElement;
        dragPendingElement = null;
        dragActivated = true;
        
        // Store original position for rollback
        dragOriginalParent = dragElement.parentNode;
        dragOriginalNextSibling = dragElement.nextElementSibling;
        
        // Add dragging class
        dragElement.classList.add('qs-dragging');
        
        // Create ghost clone that follows cursor
        dragGhost = dragElement.cloneNode(true);
        dragGhost.className = 'qs-drag-ghost';
        dragGhost.style.width = dragElement.offsetWidth + 'px';
        dragGhost.style.height = dragElement.offsetHeight + 'px';
        document.body.appendChild(dragGhost);
        positionGhost(e);
        
        // Add transition class to siblings for smooth shifting
        const parent = dragElement.parentNode;
        if (parent) {
            Array.from(parent.children).forEach(child => {
                if (child !== dragElement && child.hasAttribute('data-qs-node')) {
                    child.classList.add('qs-drag-shifting');
                }
            });
        }
        
        // Notify parent with element info (so info panel shows details)
        window.parent.postMessage({ 
            source: 'quicksite-preview', 
            action: 'dragStarted',
            ...dragInfo
        }, ALLOWED_ORIGIN);
        
        console.log('[QuickSite] Drag activated');
    }
    
    function positionGhost(e) {
        if (!dragGhost) return;
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        dragGhost.style.left = (e.clientX + scrollLeft + 12) + 'px';
        dragGhost.style.top = (e.clientY + scrollTop - 12) + 'px';
    }
    
    function handleDragMove(e) {
        if (!isDragging) return;
        
        // Check threshold before activating
        if (!dragActivated) {
            const dx = e.clientX - dragStartX;
            const dy = e.clientY - dragStartY;
            if (Math.sqrt(dx * dx + dy * dy) < dragThreshold) return;
            activateDrag(e);
        }
        
        if (!dragElement) return;
        
        // Move ghost
        positionGhost(e);
        
        // Find potential drop target
        // Ghost already has pointer-events: none via CSS
        dragGhost.style.pointerEvents = 'none';
        
        const elementsAtPoint = document.elementsFromPoint(e.clientX, e.clientY);
        let foundTarget = null;
        
        for (const el of elementsAtPoint) {
            if (el === dragElement || el === dragGhost || el === dropIndicator) continue;
            // Skip descendants of dragElement (element stays in place, children intercept)
            if (dragElement.contains(el)) continue;
            if (el.classList.contains('qs-draggable')) {
                foundTarget = el;
                break;
            }
        }
        
        if (foundTarget) {
            // 3-zone detection: 30% top = before, 40% middle = inside, 30% bottom = after
            // For very small elements (< 30px), skip 'inside' — only before/after.
            const rect = foundTarget.getBoundingClientRect();
            const relY = e.clientY - rect.top;
            const height = rect.height;
            let position;
            if (height < 30) {
                position = relY < height / 2 ? 'before' : 'after';
            } else {
                const edgeZone = height * 0.30;
                if (relY < edgeZone) {
                    position = 'before';
                } else if (relY > height - edgeZone) {
                    position = 'after';
                } else {
                    position = 'inside';
                }
            }
            
            if (canDropAt(dragElement, foundTarget, position)) {
                // Clear previous drop target highlight
                if (dropTarget && dropTarget !== foundTarget) {
                    dropTarget.classList.remove('qs-drag-over', 'qs-drop-target-inside');
                }
                
                dropTarget = foundTarget;
                dropPosition = position;
                
                if (position === 'inside') {
                    foundTarget.classList.add('qs-drop-target-inside');
                    foundTarget.classList.remove('qs-drag-over');
                } else {
                    foundTarget.classList.add('qs-drag-over');
                    foundTarget.classList.remove('qs-drop-target-inside');
                }
                
                // Show drop indicator (visual-only — no live DOM moves)
                showDropIndicator(foundTarget, position);
            } else {
                // canDropAt failed — clear drop target
                if (dropTarget) {
                    dropTarget.classList.remove('qs-drag-over', 'qs-drop-target-inside');
                    dropTarget = null;
                    dropPosition = null;
                    hideDropIndicator();
                }
            }
        }
        // When foundTarget is null (cursor over dragElement itself or empty space),
        // keep the current dropTarget — don't clear it.
    }
    
    function handleDragEnd(e) {
        if (!isDragging) return;
        
        // If threshold wasn't met (quick click on locked element), just cancel
        if (!dragActivated) {
            isDragging = false;
            dragPendingElement = null;
            dragInfo = null;
            
            // If momentary lock (from long-press), unlock back to Phase 1
            if (dragLocked && !dragLockPersistent) {
                dragUnlockElement();
            }
            return;
        }
        
        // Clean up visual state
        if (dragElement) {
            dragElement.classList.remove('qs-dragging');
        }
        if (dropTarget) {
            dropTarget.classList.remove('qs-drag-over', 'qs-drop-target-inside');
        }
        
        // Hide drop indicator
        hideDropIndicator();
        
        // Remove ghost
        if (dragGhost) {
            dragGhost.remove();
            dragGhost = null;
        }
        
        // Remove shifting transition class from siblings
        document.querySelectorAll('.qs-drag-shifting').forEach(el => {
            el.classList.remove('qs-drag-shifting');
        });
        // Clean up any stray drop-target classes
        document.querySelectorAll('.qs-drop-target-inside').forEach(el => {
            el.classList.remove('qs-drop-target-inside');
        });
        
        // Check if we have a valid and meaningful move.
        if (dragElement && dragInfo && dropTarget && dropPosition) {
            if (isMeaningfulMove(dragElement, dropTarget, dropPosition)) {
                // Save undo state BEFORE the DOM move
                const undoEntry = {
                    element: dragElement,
                    oldParent: dragOriginalParent,
                    oldNextSibling: dragOriginalNextSibling,
                    struct: dragInfo.struct
                };
                
                // Perform the DOM move now
                try {
                    if (dropPosition === 'before') {
                        dropTarget.parentNode.insertBefore(dragElement, dropTarget);
                    } else if (dropPosition === 'after') {
                        dropTarget.parentNode.insertBefore(dragElement, dropTarget.nextSibling);
                    } else {
                        // 'inside' — append as last child of target
                        dropTarget.appendChild(dragElement);
                    }
                } catch (domErr) {
                    console.warn('[QuickSite] DOM move failed:', domErr.message);
                    isDragging = false;
                    dragActivated = false;
                    dragInfo = null;
                    dropTarget = null;
                    dropPosition = null;
                    return;
                }
                
                // Save new position for undo entry
                undoEntry.newParent = dragElement.parentNode;
                undoEntry.newNextSibling = dragElement.nextElementSibling;
                dragPushUndo(undoEntry);
                
                const finalTargetInfo = getElementInfo(dropTarget);
                
                console.log('[QuickSite] Drop confirmed:', {
                    sourceElement: dragInfo,
                    targetElement: finalTargetInfo,
                    position: dropPosition
                });
                
                window.parent.postMessage({ 
                    source: 'quicksite-preview', 
                    action: 'elementMoved',
                    sourceElement: dragInfo,
                    targetElement: finalTargetInfo,
                    position: dropPosition
                }, ALLOWED_ORIGIN);
            } else {
                console.log('[QuickSite] No meaningful move (same position)');
            }
        } else if (dragElement && dragInfo) {
            console.log('[QuickSite] No valid drop target');
        }
        
        isDragging = false;
        dragActivated = false;
        dragInfo = null;
        dropTarget = null;
        dropPosition = null;
        
        // After drop: handle lock state
        if (dragLockPersistent && dragSelectedElement) {
            // Persistent lock: element stays locked, re-apply visual
            dragSelectedElement.classList.add('qs-drag-locked');
            // Keep dragElement + rollback refs alive for the persistent lock case
        } else if (dragLocked && !dragLockPersistent) {
            // Momentary lock (long-press): unlock back to Phase 1
            dragLocked = false;
            if (dragSelectedElement) {
                dragSelectedElement.classList.remove('qs-drag-locked');
                dragSelectedElement.classList.add('qs-drag-selected');
            }
            const navInfo = getDragNavInfo(dragSelectedElement);
            window.parent.postMessage({
                source: 'quicksite-preview',
                action: 'dragElementUnlocked',
                ...navInfo
            }, ALLOWED_ORIGIN);
        } else {
            // Re-select the element in Phase 1 after drop
            if (dragSelectedElement) {
                dragSelectedElement.classList.add('qs-drag-selected');
            }
        }
        
        // Keep dragElement, dragOriginalParent, dragOriginalNextSibling alive
        // for potential rollback from parent on API failure.
    }
    
    /**
     * Rollback drag: restore element to original position
     */
    function rollbackDrag() {
        if (!dragElement || !dragOriginalParent) {
            console.log('[QuickSite] No drag to rollback');
            return;
        }
        
        console.log('[QuickSite] Rolling back drag');
        const el = dragElement;
        el.classList.add('qs-drag-rollback');
        
        if (dragOriginalNextSibling && dragOriginalNextSibling.parentNode === dragOriginalParent) {
            dragOriginalParent.insertBefore(el, dragOriginalNextSibling);
        } else {
            dragOriginalParent.appendChild(el);
        }
        
        // Clean up after animation
        setTimeout(() => {
            el.classList.remove('qs-drag-rollback');
        }, 300);
        
        // Clear rollback state
        dragElement = null;
        dragOriginalParent = null;
        dragOriginalNextSibling = null;
    }
    
    // ===== EVENT HANDLERS =====
    
    // Hover handling
    document.addEventListener('mouseover', function(e) {
        if (currentMode !== 'select') return;
        
        const target = getSelectableTarget(e.target, e.clientX, e.clientY);
        if (!target || target === hoveredElement || target === selectedElement) return;
        
        clearHover();
        hoveredElement = target;
        target.classList.add('qs-hover');
    }, true);
    
    document.addEventListener('mouseout', function(e) {
        if (currentMode !== 'select') return;
        const target = getSelectableTarget(e.target, e.clientX, e.clientY);
        if (target && target === hoveredElement) {
            clearHover();
        }
    }, true);
    
    // ===== Navigation guard (capture phase) =====
    // Some previewed sites attach their own click handlers that call
    // window.location = ... programmatically — preventDefault on the bubble
    // phase doesn't stop that. Run a capture-phase blocker FIRST so we can
    // stopImmediatePropagation on links / form submits before the site's
    // own listeners get a chance to navigate. Only swallows nav-related
    // targets so regular text/selection clicks still reach the bubble
    // handler below for mode-specific behavior.
    function isEditorMode() {
        return currentMode === 'select'  || currentMode === 'drag' ||
               currentMode === 'text'    || currentMode === 'style' ||
               currentMode === 'js'      || currentMode === 'add'  ||
               currentMode === 'preview';
    }
    function navTarget(el) {
        if (!el || !el.closest) return null;
        // Explicit nav / submit elements.
        const direct = el.closest('a[href], button[type="submit"], input[type="submit"], input[type="image"], input[type="reset"], button[type="reset"]');
        if (direct) return direct;
        // A bare <button> inside a <form> defaults to type="submit". Some
        // sites also wire button.onclick = () => form.submit() which never
        // fires a submit event — swallow the click before it runs.
        const btn = el.closest('button');
        if (btn && !btn.hasAttribute('type') && btn.closest('form')) return btn;
        return null;
    }
    document.addEventListener('click', function(e) {
        if (isEditorMode() && navTarget(e.target)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('auxclick', function(e) {
        // Middle-click (button 1) on a link opens a new tab — block in editor.
        if (isEditorMode() && navTarget(e.target)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('submit', function(e) {
        if (isEditorMode()) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }, true);

    // Preview mode is a pure look-don't-touch view: also block focus / typing
    // on form controls so the user can't accidentally fill an input. Other
    // editor modes leave inputs alone (text mode etc. may need them).
    function formField(el) {
        return el && el.closest && el.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]');
    }
    document.addEventListener('mousedown', function(e) {
        if (currentMode === 'preview' && formField(e.target)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('keydown', function(e) {
        if (currentMode === 'preview' && formField(e.target)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }, true);
    
    // Click handling
    document.addEventListener('click', function(e) {
        // Prevent link navigation in all editor modes
        if (currentMode === 'select' || currentMode === 'drag' || currentMode === 'text' || currentMode === 'style' || currentMode === 'js' || currentMode === 'add' || currentMode === 'preview') {
            e.preventDefault();
            e.stopPropagation();
        }
        
        if (currentMode === 'select') {
            e.stopImmediatePropagation();
            
            const target = getSelectableTarget(e.target, e.clientX, e.clientY);
            if (target) {
                clearSelection();
                clearHover();
                
                selectedElement = target;
                target.classList.add('qs-selected');
                
                const info = getElementInfo(target);
                console.log('[QuickSite] Element selected:', info);
                
                window.parent.postMessage({ 
                    source: 'quicksite-preview', 
                    action: 'elementSelected',
                    ...info
                }, ALLOWED_ORIGIN);
            }
            return false;
        }
        
        // Text mode: click on textkey element to edit
        if (currentMode === 'text') {
            const textEl = e.target.closest('[data-qs-textkey]');
            if (textEl && textEl !== editingElement) {
                startTextEdit(textEl);
            }
        }
        
        // Style mode: click on element to get style info
        if (currentMode === 'style') {
            e.stopImmediatePropagation();
            
            const target = getSelectableTarget(e.target, e.clientX, e.clientY);
            if (target) {
                // Remove previous selection
                document.querySelectorAll('.qs-style-selected').forEach(el => {
                    el.classList.remove('qs-style-selected');
                });
                
                selectedElement = target;
                target.classList.add('qs-style-selected');
                
                const elementInfo = getElementInfo(target);
                const styleInfo = getStyleInfo(target);
                
                console.log('[QuickSite] Style mode - element selected:', styleInfo);
                
                window.parent.postMessage({ 
                    source: 'quicksite-preview', 
                    action: 'styleSelected',
                    element: elementInfo,
                    style: styleInfo
                }, ALLOWED_ORIGIN);
            }
            return false;
        }
        
        // JS mode: click on element to manage interactions
        if (currentMode === 'js') {
            e.stopImmediatePropagation();

            let target = getSelectableTarget(e.target, e.clientX, e.clientY);
            // Interactions can never live on a text-only node (textKey).
            // The renderer wraps text in a <span data-qs-textkey data-qs-textonly>
            // which carries its own data-qs-node, so a naive "closest [data-qs-node]"
            // walk lands on the span instead of the parent tag. Walk one step up
            // to the nearest real tag node so the JS panel always targets an
            // element that actually accepts params.
            if (target && target.hasAttribute('data-qs-textonly')) {
                const realTag = target.parentElement
                    ? target.parentElement.closest('[data-qs-node]:not([data-qs-textonly])')
                    : null;
                if (realTag) target = realTag;
            }
            if (target) {
                // Remove previous selection
                document.querySelectorAll('.qs-js-selected').forEach(el => {
                    el.classList.remove('qs-js-selected');
                });

                selectedElement = target;
                target.classList.add('qs-js-selected');

                const elementInfo = getElementInfo(target);

                console.log('[QuickSite] JS mode - element selected:', elementInfo);

                window.parent.postMessage({
                    source: 'quicksite-preview',
                    action: 'interactionSelected',
                    element: elementInfo
                }, ALLOWED_ORIGIN);
            }
            return false;
        }
    }, true);
    
    // Keyboard handling for text editing and drag mode navigation
    document.addEventListener('keydown', function(e) {
        // JS mode: arrow keys navigate the current selection (mirror of select mode).
        // Iframe-side handler is needed because the iframe captures focus after
        // a click, so the parent-side keydown listener never receives arrow keys.
        if (currentMode === 'js' && selectedElement && selectedElement.hasAttribute && selectedElement.hasAttribute('data-qs-node')) {
            // Don't hijack arrows while typing in any input/textarea inside the iframe
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                // fall through to other handlers below
            } else {
                const struct = selectedElement.getAttribute('data-qs-struct');
                const nodeId = selectedElement.getAttribute('data-qs-node');
                if (struct && nodeId !== null) {
                    if (e.key === 'ArrowUp')    { e.preventDefault(); navigateToParent(struct, nodeId); return; }
                    if (e.key === 'ArrowDown')  { e.preventDefault(); navigateToFirstChild(struct, nodeId); return; }
                    if (e.key === 'ArrowLeft')  { e.preventDefault(); navigateToPrevSibling(struct, nodeId); return; }
                    if (e.key === 'ArrowRight') { e.preventDefault(); navigateToNextSibling(struct, nodeId); return; }
                }
            }
        }

        // Drag mode: arrow key navigation (Phase 1 only) and Escape
        if (currentMode === 'drag') {
            // Escape cancels active drag or unlocks
            if (e.key === 'Escape') {
                e.preventDefault();
                if (isDragging) {
                    // Clean up active drag
                    if (dragElement) dragElement.classList.remove('qs-dragging');
                    if (dropTarget) dropTarget.classList.remove('qs-drag-over');
                    if (dragGhost) { dragGhost.remove(); dragGhost = null; }
                    document.querySelectorAll('.qs-drag-shifting').forEach(el => el.classList.remove('qs-drag-shifting'));
                    hideDropIndicator();
                    
                    if (dragActivated) rollbackDrag();
                    
                    isDragging = false;
                    dragActivated = false;
                    dragElement = null;
                    dragPendingElement = null;
                    dragInfo = null;
                    dropTarget = null;
                    dropPosition = null;
                    
                    // After cancelling drag, go back to Phase 1 with the element still selected
                    if (dragSelectedElement) {
                        dragLocked = false;
                        dragLockPersistent = false;
                        dragSelectedElement.classList.remove('qs-drag-locked');
                        dragSelectedElement.classList.add('qs-drag-selected');
                        const navInfo = getDragNavInfo(dragSelectedElement);
                        window.parent.postMessage({
                            source: 'quicksite-preview',
                            action: 'dragElementUnlocked',
                            ...navInfo
                        }, ALLOWED_ORIGIN);
                    }
                } else if (dragLocked) {
                    // Unlock if locked
                    dragUnlockElement();
                } else if (dragSelectedElement) {
                    // Deselect
                    dragSelectedElement.classList.remove('qs-drag-selected');
                    dragSelectedElement = null;
                    window.parent.postMessage({
                        source: 'quicksite-preview',
                        action: 'dragElementDeselected'
                    }, ALLOWED_ORIGIN);
                }
                return;
            }
            
            // Arrow keys for Phase 1 navigation (not during drag, not when locked)
            if (dragSelectedElement && !dragLocked && !isDragging) {
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    dragNavigatePrevSibling();
                    return;
                }
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    dragNavigateNextSibling();
                    return;
                }
                if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    dragNavigateParent();
                    return;
                }
                if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    dragNavigateChild();
                    return;
                }
                // Enter locks the element (keyboard alternative to long-press)
                if (e.key === 'Enter') {
                    e.preventDefault();
                    dragLockElement(true);
                    return;
                }
            }
            
            // Ctrl+Z / Ctrl+Y for undo/redo (always available in drag mode)
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                dragUndoMove();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
                e.preventDefault();
                dragRedoMove();
                return;
            }
        }
        
        if (currentMode === 'text' && editingElement) {
            if (e.key === 'Escape') {
                e.preventDefault();
                cancelTextEdit();
            }
            // Enter always saves (no newlines - would break JSON translations)
            if (e.key === 'Enter') {
                e.preventDefault();
                finishTextEdit();
            }
        }
    }, true);
    
    // Blur handling for text editing
    document.addEventListener('blur', function(e) {
        if (currentMode === 'text' && editingElement && e.target === editingElement) {
            // Small delay to allow click on another textkey to work
            setTimeout(() => {
                if (editingElement === e.target) {
                    finishTextEdit();
                }
            }, 100);
        }
    }, true);
    
    // Mouse events for drag mode
    document.addEventListener('mousedown', function(e) {
        if (currentMode === 'select') {
            e.preventDefault();
        }
        
        if (currentMode === 'drag') {
            const target = getDraggableTarget(e.target, e.clientX, e.clientY);
            if (!target || !target.classList.contains('qs-draggable')) return;
            
            e.preventDefault();
            
            if (dragLocked && dragSelectedElement) {
                // Phase 2: Locked — mousedown starts the actual drag
                handleDragStart(e, dragSelectedElement);
            } else {
                // Phase 1: Select the element and start long-press timer
                dragSelectElement(target);
                
                // Start long-press timer for momentary lock
                dragStartX = e.clientX;
                dragStartY = e.clientY;
                if (longPressTimer) clearTimeout(longPressTimer);
                longPressTimer = setTimeout(function() {
                    longPressTimer = null;
                    // Only lock if mouse hasn't moved much (not a drag gesture)
                    if (dragSelectedElement === target && !isDragging) {
                        dragLockElement(false); // momentary lock
                        // Immediately start drag with this element
                        handleDragStart(e, dragSelectedElement);
                    }
                }, longPressDelay);
            }
        }
    }, true);
    
    document.addEventListener('mousemove', function(e) {
        if (currentMode === 'drag') {
            // Cancel long-press if mouse moves significantly before timer fires
            if (longPressTimer) {
                const dx = e.clientX - dragStartX;
                const dy = e.clientY - dragStartY;
                if (Math.sqrt(dx * dx + dy * dy) > dragThreshold) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
            }
            
            if (isDragging) {
                e.preventDefault();
                handleDragMove(e);
            }
        }
    }, true);
    
    document.addEventListener('mouseup', function(e) {
        if (currentMode === 'drag') {
            // Clear long-press timer
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            
            if (isDragging) {
                e.preventDefault();
                handleDragEnd(e);
            }
        }
    }, true);
    
    // Notify parent that we're ready
    window.parent.postMessage({ 
        source: 'quicksite-preview', 
        action: 'overlayReady'
    }, ALLOWED_ORIGIN);
    
    // Initial scan for empty elements (default mode is select)
    scanEmptyElements();
    
    // Re-scan empty elements after DOM mutations (insert, delete, duplicate, drag)
    var emptyHintTimer = null;
    var emptyHintObserver = new MutationObserver(function() {
        if (currentMode !== 'select' && currentMode !== 'drag') return;
        if (emptyHintTimer) clearTimeout(emptyHintTimer);
        emptyHintTimer = setTimeout(scanEmptyElements, 80);
    });
    emptyHintObserver.observe(document.body, { childList: true, subtree: true });
})();
