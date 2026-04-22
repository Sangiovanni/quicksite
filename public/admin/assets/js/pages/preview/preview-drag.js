/**
 * Preview Drag Module
 *
 * Manages the drag-and-drop element reordering tool in the Visual Editor.
 *
 * Features:
 * - Drag mode UI state (lock/undo/redo/nav buttons)
 * - Element move API persistence via moveNode command
 * - Undo/redo forwarding via iframe message protocol
 * - Keyboard shortcuts (Ctrl+Z / Ctrl+Y in drag mode)
 *
 * Dependencies:
 * - window.PreviewState  (sendToIframe, utils.parseStruct)  — loaded before this file
 * - window.PreviewConfig (i18n strings)                     — loaded before this file
 * - window.QuickSiteAdmin (apiRequest, showToast)           — loaded before this file
 *
 * Public API (window.PreviewDrag):
 *   init()                              — query DOM refs and bind button events
 *   onModeEnter()                       — called by preview.js when switching to drag mode
 *   handleMessage(data)                 — called for every iframe postMessage; returns true if consumed
 *   handleKeydown(e)                    — called from keydown handler; returns true if consumed
 *   setShowContextualInfo(fn)           — inject showContextualInfo callback from preview.js
 *   setUpdateGlobalElementInfo(fn)      — inject updateGlobalElementInfo callback from preview.js
 */
(function () {
    'use strict';

    // ==================== DOM refs (set in init) ====================

    let dragDefault   = null;
    let dragInfo      = null;
    let dragLockBtn   = null;
    let dragUndoBtn   = null;
    let dragRedoBtn   = null;
    let dragHint      = null;
    let dragNavParent = null;
    let dragNavPrev   = null;
    let dragNavNext   = null;
    let dragNavChild  = null;

    // ==================== Injected callbacks ====================

    let showContextualInfoFn       = null;
    let updateGlobalElementInfoFn  = null;

    // ==================== Internal helpers ====================

    function showToast(message, type) {
        if (window.QuickSiteAdmin && QuickSiteAdmin.showToast) {
            QuickSiteAdmin.showToast(message, type);
        } else {
            console.log('[PreviewDrag][Toast]', type, message);
        }
    }

    function sendToIframe(action, data) {
        if (window.PreviewState) {
            PreviewState.sendToIframe(action, data);
        }
    }

    function parseStruct(struct) {
        if (window.PreviewState) {
            return PreviewState.utils.parseStruct(struct);
        }
        return null;
    }

    function i18n(key) {
        return (window.PreviewConfig && PreviewConfig.i18n && PreviewConfig.i18n[key]) || '';
    }

    // ==================== Element Move Handler ====================

    /**
     * Persist a drag-reorder operation to the server via moveNode command.
     * The iframe has already moved the DOM live — on failure we ask it to rollback.
     */
    async function handleElementMoved(data) {
        console.log('[PreviewDrag] Element moved:', data);

        const source    = data.sourceElement;
        const target    = data.targetElement;
        const position  = data.position;        // 'before', 'after', or 'inside'
        const isUndoRedo      = !!data.isUndoRedo;
        const undoRedoAction  = data.undoRedoAction; // 'undo' or 'redo'

        if (!source || !target || !source.struct || !source.node || !target.node) {
            console.error('[PreviewDrag] Invalid move data:', { source, target, position });
            showToast(i18n('error') + ': Invalid move data', 'error');
            sendToIframe('rollbackDrag', {});
            return;
        }

        const structInfo = parseStruct(source.struct);
        if (!structInfo || !structInfo.type) {
            showToast(i18n('error') + ': Invalid structure type', 'error');
            sendToIframe('rollbackDrag', {});
            return;
        }

        try {
            const params = {
                type:         structInfo.type,
                sourceNodeId: source.node,
                targetNodeId: target.node,
                position:     position
            };
            if (structInfo.name) {
                params.name = structInfo.name;
            }

            console.log('[PreviewDrag] Moving node:', params);
            const result = await QuickSiteAdmin.apiRequest('moveNode', 'PATCH', params);

            if (!result.ok) {
                throw new Error(
                    result.data?.message || result.data?.data?.message || 'Failed to move node'
                );
            }

            // DOM is already correct — ask iframe to reindex all node IDs for this struct
            sendToIframe('reindexNodes', { struct: source.struct });

            // Highlight the moved element after reindex
            setTimeout(() => {
                const iframeEl = window.PreviewState?.getIframe();
                const iframeDoc = iframeEl
                    ? (iframeEl.contentDocument || iframeEl.contentWindow?.document)
                    : null;

                const newNodeId = result.data?.data?.newNodeId;
                const highlightSelector = newNodeId
                    ? `[data-qs-struct="${source.struct}"][data-qs-node="${newNodeId}"], ` +
                      `[data-qs-struct="${source.struct}"] [data-qs-node="${newNodeId}"]`
                    : null;

                const sourceEl = (highlightSelector && iframeDoc)
                    ? iframeDoc.querySelector(highlightSelector)
                    : null;

                if (sourceEl) {
                    sourceEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    sourceEl.style.outline       = '3px solid var(--primary, #3b82f6)';
                    sourceEl.style.outlineOffset = '2px';
                    setTimeout(() => {
                        sourceEl.style.outline       = '';
                        sourceEl.style.outlineOffset = '';
                    }, 1500);
                }
            }, 50);

            showToast(
                isUndoRedo
                    ? (undoRedoAction === 'undo' ? i18n('elementMoveUndone') : i18n('elementMoveRedone'))
                    : i18n('elementMoved'),
                'success'
            );
            console.log('[PreviewDrag] Move saved successfully');

        } catch (error) {
            console.error('[PreviewDrag] Move error:', error);
            showToast(i18n('error') + ': ' + error.message, 'error');
            if (isUndoRedo) {
                sendToIframe(undoRedoAction === 'undo' ? 'dragRedo' : 'dragUndo', {});
            } else {
                sendToIframe('rollbackDrag', {});
            }
        }
    }

    // ==================== Mode Enter ====================

    /**
     * Reset drag UI to its default state.
     * Called by preview.js when setMode('drag') is invoked.
     */
    function onModeEnter() {
        if (dragDefault) dragDefault.style.display = '';
        if (dragInfo)    dragInfo.style.display    = 'none';
        if (dragHint)    dragHint.textContent       = i18n('dragSelectHint');
        if (dragLockBtn) dragLockBtn.classList.remove('preview-sidebar-tool-option--active');
    }

    // ==================== Iframe Message Handling ====================

    const DRAG_ACTIONS = new Set([
        'elementMoved',
        'dragStarted',
        'dragElementSelected',
        'dragElementLocked',
        'dragElementUnlocked',
        'dragElementDeselected',
        'dragStackUpdate',
        'dragModeReady'
    ]);

    /**
     * Handle a postMessage from the preview iframe.
     * @returns {boolean} true if the message was consumed, false otherwise.
     */
    function handleMessage(data) {
        if (!DRAG_ACTIONS.has(data.action)) return false;

        switch (data.action) {

            case 'elementMoved':
                handleElementMoved(data);
                return true;

            case 'dragStarted':
                if (showContextualInfoFn) showContextualInfoFn(data);
                return true;

            case 'dragElementSelected':
                if (dragDefault) dragDefault.style.display = 'none';
                if (dragInfo)    dragInfo.style.display    = '';
                if (dragNavParent) dragNavParent.disabled  = !data.hasParent;
                if (dragNavPrev)   dragNavPrev.disabled    = !data.hasPrevSibling;
                if (dragNavNext)   dragNavNext.disabled    = !data.hasNextSibling;
                if (dragNavChild)  dragNavChild.disabled   = !data.hasChildren;
                if (dragLockBtn)   dragLockBtn.classList.remove('preview-sidebar-tool-option--active');
                if (dragHint)      dragHint.textContent    = i18n('dragSelectHint');
                if (updateGlobalElementInfoFn) updateGlobalElementInfoFn(data);
                return true;

            case 'dragElementLocked':
                if (dragLockBtn) dragLockBtn.classList.toggle('preview-sidebar-tool-option--active', !!data.persistent);
                if (dragHint)    dragHint.textContent = i18n('dragLockedHint');
                // Disable nav buttons while element is locked
                if (dragNavParent) dragNavParent.disabled = true;
                if (dragNavPrev)   dragNavPrev.disabled   = true;
                if (dragNavNext)   dragNavNext.disabled   = true;
                if (dragNavChild)  dragNavChild.disabled  = true;
                return true;

            case 'dragElementUnlocked':
                if (dragLockBtn) dragLockBtn.classList.remove('preview-sidebar-tool-option--active');
                if (dragHint)    dragHint.textContent      = i18n('dragSelectHint');
                if (dragNavParent) dragNavParent.disabled  = !data.hasParent;
                if (dragNavPrev)   dragNavPrev.disabled    = !data.hasPrevSibling;
                if (dragNavNext)   dragNavNext.disabled    = !data.hasNextSibling;
                if (dragNavChild)  dragNavChild.disabled   = !data.hasChildren;
                return true;

            case 'dragElementDeselected':
                if (dragLockBtn) dragLockBtn.classList.remove('preview-sidebar-tool-option--active');
                if (dragHint)    dragHint.textContent = i18n('dragSelectHint');
                if (dragDefault) dragDefault.style.display = '';
                if (dragInfo)    dragInfo.style.display    = 'none';
                return true;

            case 'dragStackUpdate':
                if (dragUndoBtn) dragUndoBtn.disabled = (data.undoCount === 0);
                if (dragRedoBtn) dragRedoBtn.disabled = (data.redoCount === 0);
                return true;

            case 'dragModeReady':
                if (dragUndoBtn) dragUndoBtn.disabled = (data.undoCount === 0);
                if (dragRedoBtn) dragRedoBtn.disabled = (data.redoCount === 0);
                if (dragHint)    dragHint.textContent  = i18n('dragSelectHint');
                return true;
        }

        return false;
    }

    // ==================== Keyboard Handling ====================

    /**
     * Handle keydown events when the editor is in drag mode.
     * @returns {boolean} true if the event was consumed.
     */
    function handleKeydown(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
            e.preventDefault();
            sendToIframe('dragUndo', {});
            return true;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
            e.preventDefault();
            sendToIframe('dragRedo', {});
            return true;
        }
        return false;
    }

    // ==================== Init ====================

    function init() {
        // Query drag DOM references
        dragDefault   = document.getElementById('contextual-drag-default');
        dragInfo      = document.getElementById('contextual-drag-info');
        dragLockBtn   = document.getElementById('preview-drag-lock');
        dragUndoBtn   = document.getElementById('preview-drag-undo');
        dragRedoBtn   = document.getElementById('preview-drag-redo');
        dragHint      = document.getElementById('preview-drag-hint');
        dragNavParent = document.getElementById('ctx-drag-nav-parent');
        dragNavPrev   = document.getElementById('ctx-drag-nav-prev');
        dragNavNext   = document.getElementById('ctx-drag-nav-next');
        dragNavChild  = document.getElementById('ctx-drag-nav-child');

        // Bind toolbar button events (forward to iframe)
        if (dragLockBtn) dragLockBtn.addEventListener('click', function () { sendToIframe('dragToggleLock', {}); });
        if (dragUndoBtn) dragUndoBtn.addEventListener('click', function () { sendToIframe('dragUndo', {}); });
        if (dragRedoBtn) dragRedoBtn.addEventListener('click', function () { sendToIframe('dragRedo', {}); });
        if (dragNavParent) dragNavParent.addEventListener('click', function () { sendToIframe('dragNavParent', {}); });
        if (dragNavPrev)   dragNavPrev.addEventListener('click',   function () { sendToIframe('dragNavPrev', {}); });
        if (dragNavNext)   dragNavNext.addEventListener('click',   function () { sendToIframe('dragNavNext', {}); });
        if (dragNavChild)  dragNavChild.addEventListener('click',  function () { sendToIframe('dragNavChild', {}); });

        console.log('[PreviewDrag] Initialized');
    }

    // ==================== Public API ====================

    window.PreviewDrag = {
        init: init,
        onModeEnter: onModeEnter,
        handleMessage: handleMessage,
        handleKeydown: handleKeydown,

        // Dependency injection — called by preview.js during its init
        setShowContextualInfo:      function (fn) { showContextualInfoFn      = fn; },
        setUpdateGlobalElementInfo: function (fn) { updateGlobalElementInfoFn = fn; }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('[PreviewDrag] Module loaded');
})();
