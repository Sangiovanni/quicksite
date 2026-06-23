/**
 * Preview Style Motion Module (renamed from Animations — A3-companion)
 *
 * Hosts the Motion tab in CSS mode. Section ordering: "Selectors with motion"
 * (transitions + animations + hover-state-only) primary; "Keyframes library"
 * secondary. The internal DOM ids (#animations-panel, #keyframes-*, etc.) and
 * the tab routing key (data-tab="animations") are intentionally kept on the
 * old "animations" name to avoid touching every CSS selector + JS lookup;
 * the user-facing label "Motion" comes through i18n.
 *
 * Dependencies:
 * - PreviewConfig global (from preview-config.php)
 * - PreviewState global (from preview-state.js)
 *
 * @version 1.1.0
 */
(function() {
    'use strict';

    // ==================== Module State ====================
    
    let animationsLoaded = false;
    let keyframesData = [];
    let animatedSelectorsData = {
        transitions: [],
        animations: [],
        triggersWithoutTransition: []
    };
    let keyframePreviewActive = null;
    
    // Keyframe Editor state
    let keyframeEditorMode = null;     // 'edit' or 'create'
    let editingKeyframeName = null;
    let keyframeFrames = [];
    let selectedFramePercent = null;
    let keyframePreviewStyleElement = null;
    
    // Animation Preview Modal state
    let currentPreviewKeyframe = null;
    
    // DOM element references (set during init)
    let animationsPanel = null;
    let animationsLoading = null;
    let animationsContent = null;
    let keyframesCount = null;
    let keyframesEmpty = null;
    let keyframesList = null;
    let keyframeAddBtn = null;
    let transitionsCount = null;
    let transitionsList = null;
    let animationsCount = null;
    let animationsList = null;
    let animatedEmpty = null;
    
    // Keyframe Editor Modal elements
    let keyframeModal = null;
    let keyframeModalTitle = null;
    let keyframeModalClose = null;
    let keyframeNameInput = null;
    let keyframeTimeline = null;
    let keyframeFramesContainer = null;
    let keyframeAddFrameBtn = null;
    let keyframePreviewBtn = null;
    let keyframeCancelBtn = null;
    let keyframeSaveBtn = null;
    
    // Animation Preview Modal elements
    let animationPreviewModal = null;
    let animationPreviewCloseBtn = null;
    let animationPreviewDoneBtn = null;
    let animationPreviewPlayBtn = null;
    let animationPreviewName = null;
    let animationPreviewStage = null;
    let animationPreviewDuration = null;
    let animationPreviewTiming = null;
    let animationPreviewDelay = null;
    let animationPreviewCount = null;
    let animationPreviewInfinite = null;
    let animationPreviewCss = null;
    
    // Shared references
    let managementUrl = '';
    let authToken = '';
    let iframe = null;

    // ==================== Utility Functions ====================
    
    function escapeHtml(text) {
        // Delegate to canonical PreviewState.utils.escapeHtml when available.
        if (window.PreviewState && PreviewState.utils && PreviewState.utils.escapeHtml) {
            return PreviewState.utils.escapeHtml(text);
        }
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showToast(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        } else if (window.QuickSiteAdmin?.showToast) {
            window.QuickSiteAdmin.showToast(message, type);
        } else {
            console.log(`[${type}] ${message}`);
        }
    }
    
    function showNotification(message, type) {
        showToast(message, type);
    }

    // ==================== Initialization ====================
    
    function init() {
        // Get DOM references
        animationsPanel = document.getElementById('animations-panel');
        animationsLoading = document.getElementById('animations-loading');
        animationsContent = document.getElementById('animations-content');
        keyframesCount = document.getElementById('keyframes-count');
        keyframesEmpty = document.getElementById('keyframes-empty');
        keyframesList = document.getElementById('keyframes-list');
        keyframeAddBtn = document.getElementById('keyframe-add-btn');
        transitionsCount = document.getElementById('transitions-count');
        transitionsList = document.getElementById('transitions-list');
        animationsCount = document.getElementById('animations-count');
        animationsList = document.getElementById('animations-list');
        animatedEmpty = document.getElementById('animated-empty');
        
        // Keyframe Editor Modal
        keyframeModal = document.getElementById('preview-keyframe-modal');
        keyframeModalTitle = document.getElementById('keyframe-modal-title');
        keyframeModalClose = document.getElementById('keyframe-modal-close');
        keyframeNameInput = document.getElementById('keyframe-name');
        keyframeTimeline = document.getElementById('keyframe-timeline');
        keyframeFramesContainer = document.getElementById('keyframe-frames');
        keyframeAddFrameBtn = document.getElementById('keyframe-add-frame');
        keyframePreviewBtn = document.getElementById('keyframe-preview-btn');
        keyframeCancelBtn = document.getElementById('keyframe-cancel');
        keyframeSaveBtn = document.getElementById('keyframe-save');
        
        // Animation Preview Modal
        animationPreviewModal = document.getElementById('animation-preview-modal');
        animationPreviewCloseBtn = document.getElementById('animation-preview-close');
        animationPreviewDoneBtn = document.getElementById('animation-preview-done');
        animationPreviewPlayBtn = document.getElementById('animation-preview-play');
        animationPreviewName = document.getElementById('animation-preview-name');
        animationPreviewStage = document.getElementById('animation-preview-stage');
        animationPreviewDuration = document.getElementById('animation-preview-duration');
        animationPreviewTiming = document.getElementById('animation-preview-timing');
        animationPreviewDelay = document.getElementById('animation-preview-delay');
        animationPreviewCount = document.getElementById('animation-preview-count');
        animationPreviewInfinite = document.getElementById('animation-preview-infinite');
        animationPreviewCss = document.getElementById('animation-preview-css');
        
        // Get shared references from PreviewConfig
        managementUrl = PreviewConfig.managementUrl;
        authToken = PreviewConfig.authToken;
        iframe = document.getElementById('preview-iframe');
        
        // Initialize components
        initKeyframeEditor();
        initAnimationPreviewModal();
        initAnimationsGroups();
    }

    // ==================== Animations Groups ====================
    
    function initAnimationsGroups() {
        const groups = document.querySelectorAll('.preview-animations-group');
        groups.forEach(group => {
            const header = group.querySelector('.preview-animations-group__header');
            const list = group.querySelector('.preview-animations-group__list');
            
            if (header && list) {
                header.addEventListener('click', () => {
                    const isExpanded = header.classList.contains('preview-animations-group__header--expanded');
                    header.classList.toggle('preview-animations-group__header--expanded');
                    list.classList.toggle('preview-animations-group__list--collapsed', isExpanded);
                });
            }
        });
    }

    // ==================== Load Animations Tab ====================
    
    async function loadAnimationsTab() {
        if (!animationsLoading || !animationsContent) return;
        
        // Show loading state
        animationsLoading.style.display = '';
        animationsContent.style.display = 'none';
        
        try {
            // Fetch both keyframes and animated selectors in parallel
            const [keyframesResponse, selectorsResponse] = await Promise.all([
                fetch(managementUrl + 'listKeyframes', {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                }),
                fetch(managementUrl + 'getAnimatedSelectors', {
                    headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
                })
            ]);
            
            const keyframesData_response = await keyframesResponse.json();
            const selectorsData = await selectorsResponse.json();

            // Process animated selectors FIRST — populateKeyframesList()
            // (below) reads animatedSelectorsData to compute the
            // keyframe→users map for the "used by N" badges. Reversing
            // the order means the badges would render against stale /
            // empty cache and stay wrong until the next refresh.
            if (selectorsData.status === 200 && selectorsData.data) {
                animatedSelectorsData = {
                    transitions: selectorsData.data.transitions || [],
                    animations: selectorsData.data.animations || [],
                    triggersWithoutTransition: selectorsData.data.triggersWithoutTransition || []
                };
            } else {
                animatedSelectorsData = { transitions: [], animations: [], triggersWithoutTransition: [] };
            }
            populateAnimatedSelectorsList();

            // Process keyframes — now animatedSelectorsData is fresh, so
            // the used-by map is accurate.
            if (keyframesData_response.status === 200 && keyframesData_response.data?.keyframes) {
                keyframesData = keyframesData_response.data.keyframes;
            } else {
                keyframesData = [];
            }
            populateKeyframesList();
            
            animationsLoaded = true;
            
            // Show content, hide loading
            animationsLoading.style.display = 'none';
            animationsContent.style.display = '';
            
        } catch (error) {
            console.error('Failed to load animations:', error);
            animationsLoading.innerHTML = `
                ${QuickSiteUtils.iconAlertCircle(24)}
                <span>${PreviewConfig.i18n.loadAnimationsFailed}</span>
            `;
        }
    }

    // ==================== Populate Keyframes List ====================
    
    function populateKeyframesList() {
        if (!keyframesList || !keyframesCount || !keyframesEmpty) return;
        
        const count = keyframesData.length;
        keyframesCount.textContent = count;
        
        if (count === 0) {
            keyframesList.innerHTML = '';
            keyframesEmpty.style.display = '';
            return;
        }
        
        keyframesEmpty.style.display = 'none';

        // Build keyframe→users map from cached animatedSelectorsData
        // (no extra fetch — same data drives the Animations sub-group).
        const usersMap = computeKeyframeUsersMap();

        keyframesList.innerHTML = keyframesData.map(kf => {
            const users = usersMap[kf.name] || [];
            const usedByCount = users.length;
            const tplCount = PreviewConfig.i18n.keyframeUsedByCount || 'used by {n}';
            const usedByLabel = tplCount.replace('{n}', String(usedByCount));
            const usedByTitle = PreviewConfig.i18n.keyframeUsedByTitle || 'Show selectors using this keyframe';
            const removeTitle = PreviewConfig.i18n.keyframeRemoveFromSelector || 'Remove animation from this selector';
            const usedByBadge = usedByCount > 0
                ? `<button type="button" class="preview-keyframe-item__used-by" data-action="toggle-users" title="${escapeHtml(usedByTitle)}">${escapeHtml(usedByLabel)}</button>`
                : '';
            const usersListHtml = usedByCount > 0
                ? `<div class="preview-keyframe-item__users" data-users hidden>
                    ${users.map(sel => `
                        <div class="preview-keyframe-item__user">
                            <code>${escapeHtml(sel)}</code>
                            <button type="button" class="preview-keyframe-item__user-remove" data-action="remove-user" data-target="${escapeHtml(sel)}" title="${escapeHtml(removeTitle)}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="12" height="12">
                                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                    `).join('')}
                </div>`
                : '';
        return `
            <div class="preview-keyframe-item" data-keyframe="${escapeHtml(kf.name)}">
                <div class="preview-keyframe-item__info">
                    <span class="preview-keyframe-item__name">@keyframes ${escapeHtml(kf.name)}</span>
                    <span class="preview-keyframe-item__frames">${kf.frameCount} ${kf.frameCount === 1 ? PreviewConfig.i18n.frame : PreviewConfig.i18n.frames}</span>
                    ${usedByBadge}
                </div>
                <div class="preview-keyframe-item__actions">
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--preview"
                            data-action="preview" title="${PreviewConfig.i18n.previewAnimation}">
                        ${QuickSiteUtils.iconPlay(14)}
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--apply"
                            data-action="apply" title="${PreviewConfig.i18n.applyKeyframeToSelector || 'Apply to selector…'}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="13 6 19 12 13 18"/>
                        </svg>
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--edit"
                            data-action="edit" title="${PreviewConfig.i18n.edit}">
                        ${QuickSiteUtils.iconEdit()}
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--delete"
                            data-action="delete" title="${PreviewConfig.i18n.delete}">
                        ${QuickSiteUtils.iconTrash()}
                    </button>
                </div>
                ${usersListHtml}
            </div>
        `;
        }).join('');
        
        // Add event listeners — row actions (preview / apply / edit / delete)
        keyframesList.querySelectorAll('.preview-keyframe-item__btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const item = btn.closest('.preview-keyframe-item');
                const keyframeName = item?.dataset.keyframe;
                const action = btn.dataset.action;

                if (!keyframeName) return;

                switch (action) {
                    case 'preview':
                        previewKeyframe(keyframeName);
                        break;
                    case 'apply':
                        applyKeyframeToSelector(keyframeName);
                        break;
                    case 'edit':
                        editKeyframe(keyframeName);
                        break;
                    case 'delete':
                        deleteKeyframe(keyframeName);
                        break;
                }
            });
        });

        // Slice 2b — "used by N" badge toggles the inline user list.
        keyframesList.querySelectorAll('.preview-keyframe-item__used-by').forEach(badge => {
            badge.addEventListener('click', (e) => {
                e.stopPropagation();
                const item = badge.closest('.preview-keyframe-item');
                const usersDiv = item?.querySelector('[data-users]');
                if (!usersDiv) return;
                usersDiv.hidden = !usersDiv.hidden;
                badge.classList.toggle('preview-keyframe-item__used-by--open', !usersDiv.hidden);
            });
        });

        // Slice 2b — ✕ button on each user row removes the animation from
        // that selector via setStyleRule + removeProperties.
        keyframesList.querySelectorAll('.preview-keyframe-item__user-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const item = btn.closest('.preview-keyframe-item');
                const keyframeName = item?.dataset.keyframe;
                const targetSelector = btn.dataset.target;
                if (!keyframeName || !targetSelector) return;
                removeKeyframeFromSelector(keyframeName, targetSelector);
            });
        });
    }

    // ==================== Keyframe Editor Initialization ====================
    
    function initKeyframeEditor() {
        // Add click listener to keyframe-add-btn
        if (keyframeAddBtn) {
            keyframeAddBtn.addEventListener('click', () => createNewKeyframe());
        }
        
        // Modal close button
        if (keyframeModalClose) {
            keyframeModalClose.addEventListener('click', closeKeyframeModal);
        }
        
        // Cancel button
        if (keyframeCancelBtn) {
            keyframeCancelBtn.addEventListener('click', closeKeyframeModal);
        }
        
        // Save button
        if (keyframeSaveBtn) {
            keyframeSaveBtn.addEventListener('click', saveKeyframe);
        }
        
        // Preview button
        if (keyframePreviewBtn) {
            keyframePreviewBtn.addEventListener('click', previewKeyframeAnimation);
        }
        
        // Add frame button
        if (keyframeAddFrameBtn) {
            keyframeAddFrameBtn.addEventListener('click', () => promptAddFrame());
        }
        
        // Backdrop click to close
        if (keyframeModal) {
            const backdrop = keyframeModal.querySelector('.preview-keyframe-modal__backdrop');
            if (backdrop) {
                backdrop.addEventListener('click', closeKeyframeModal);
            }
        }
    }

    // ==================== Populate Animated Selectors List ====================
    
    function populateAnimatedSelectorsList() {
        // Transitions
        if (transitionsList && transitionsCount) {
            const transitions = animatedSelectorsData.transitions || [];
            transitionsCount.textContent = transitions.length;

            // Slice 4 — "+ Add transition" button prepended to the list so it
            // shows whether the group has existing transitions or not, and
            // hides naturally when the group is collapsed (same parent).
            const addBtnHtml = `
                <button type="button" class="preview-animations-group__add" data-action="add-transition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>${PreviewConfig.i18n.addTransition || 'Add transition'}</span>
                </button>
            `;

            if (transitions.length === 0) {
                transitionsList.innerHTML = addBtnHtml +
                    `<p class="preview-animated-empty">${PreviewConfig.i18n.noTransitions}</p>`;
            } else {
                transitionsList.innerHTML = addBtnHtml + transitions.map(item =>
                    renderAnimatedSelectorGroup(item, 'transition')
                ).join('');
            }

            // Wire the add-transition trigger (re-queried because innerHTML
            // just rebuilt the list).
            const addBtn = transitionsList.querySelector('[data-action="add-transition"]');
            if (addBtn) {
                addBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openTransitionWizard();
                });
            }
        }
        
        // Animations
        if (animationsList && animationsCount) {
            const animations = animatedSelectorsData.animations || [];
            animationsCount.textContent = animations.length;
            
            if (animations.length === 0) {
                animationsList.innerHTML = `<p class="preview-animated-empty">${PreviewConfig.i18n.noAnimations}</p>`;
            } else {
                animationsList.innerHTML = animations.map(item => 
                    renderAnimatedSelectorGroup(item, 'animation')
                ).join('');
            }
        }
        
        // Show/hide empty message
        const totalCount = (animatedSelectorsData.transitions?.length || 0) + 
                          (animatedSelectorsData.animations?.length || 0);
        if (animatedEmpty) {
            animatedEmpty.style.display = totalCount === 0 ? '' : 'none';
        }
        
        // Add click listeners for selector items
        document.querySelectorAll('.preview-animated-selector--clickable, .preview-animated-selector-group__header--clickable, .preview-animated-selector-state--clickable').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.preview-animated-selector-group__toggle')) return;
                
                const selector = item.dataset.selector;
                if (selector) {
                    // Open transition editor (delegate to main preview.js)
                    const baseSelector = selector.replace(/:(hover|focus|active|visited|focus-within|focus-visible)$/i, '');
                    if (typeof window.openTransitionEditor === 'function') {
                        window.openTransitionEditor(baseSelector);
                    }
                }
            });
        });
        
        // Add toggle listeners for groups with states
        document.querySelectorAll('.preview-animated-selector-group__toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const group = toggle.closest('.preview-animated-selector-group');
                if (group) {
                    group.classList.toggle('preview-animated-selector-group--collapsed');
                }
            });
        });
    }

    // ==================== Render Animated Selector Group ====================
    
    function renderAnimatedSelectorGroup(item, type) {
        const hasStates = item.states && item.states.length > 0;
        
        // Details for the base selector
        let detailsHtml = '';
        if (type === 'transition' && item.parsed) {
            detailsHtml = formatTransitionDetails(item.parsed);
        } else if (type === 'animation' && item.parsed) {
            detailsHtml = formatAnimationDetails(item.parsed);
        } else if (item.properties) {
            const prop = type === 'transition' ? item.properties.transition : item.properties.animation;
            detailsHtml = escapeHtml(prop || '');
        }
        
        // Orphan hint HTML
        let orphanHintHtml = '';
        if (item.isOrphan && item.relatedTriggers && item.relatedTriggers.length > 0) {
            const relatedList = item.relatedTriggers.map(r => `<code>${escapeHtml(r.selector)}</code>`).join(', ');
            orphanHintHtml = `
                <div class="preview-animated-selector__orphan-hint">
                    ${QuickSiteUtils.iconInfo(12)}
                    <span>${PreviewConfig.i18n.mayBeTriggeredVia}: ${relatedList}</span>
                </div>
            `;
        } else if (item.isOrphan) {
            orphanHintHtml = `
                <div class="preview-animated-selector__orphan-hint preview-animated-selector__orphan-hint--warning">
                    ${QuickSiteUtils.iconWarning(12)}
                    <span>${PreviewConfig.i18n.noDirectTrigger}</span>
                </div>
            `;
        }
        
        if (!hasStates) {
            return `
                <div class="preview-animated-selector preview-animated-selector--clickable ${item.isOrphan ? 'preview-animated-selector--orphan' : ''}" data-selector="${escapeHtml(item.selector || item.baseSelector)}" data-type="${type}">
                    <div class="preview-animated-selector__main">
                        <code class="preview-animated-selector__name">${escapeHtml(item.baseSelector)}</code>
                        <span class="preview-animated-selector__details">${detailsHtml}</span>
                        ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.arrowRight, 12, 'preview-animated-selector__arrow')}
                    </div>
                    ${orphanHintHtml}
                </div>
            `;
        }
        
        // Group with states - render as tree
        const statesHtml = item.states.map(state => {
            const changedProps = Object.entries(state.properties || {})
                .filter(([prop]) => !prop.startsWith('transition') && !prop.startsWith('animation'))
                .map(([prop, val]) => `${prop}: ${val}`)
                .slice(0, 3);
            
            const propsDisplay = changedProps.length > 0 
                ? changedProps.join('; ') + (Object.keys(state.properties || {}).length > 3 ? '...' : '')
                : '';
                
            return `
                <div class="preview-animated-selector-state preview-animated-selector-state--clickable" data-selector="${escapeHtml(state.selector)}">
                    <code class="preview-animated-selector-state__pseudo">${escapeHtml(state.pseudo)}</code>
                    <span class="preview-animated-selector-state__props">${escapeHtml(propsDisplay)}</span>
                </div>
            `;
        }).join('');
        
        return `
            <div class="preview-animated-selector-group" data-type="${type}">
                <div class="preview-animated-selector-group__header preview-animated-selector-group__header--clickable" data-selector="${escapeHtml(item.selector || item.baseSelector)}">
                    <button type="button" class="preview-animated-selector-group__toggle" title="${PreviewConfig.i18n.toggleStates}">
                        ${QuickSiteUtils.iconChevronDown(12)}
                    </button>
                    <code class="preview-animated-selector__name">${escapeHtml(item.baseSelector)}</code>
                    <span class="preview-animated-selector__details">${detailsHtml}</span>
                    <span class="preview-animated-selector__state-count">${item.states.length} ${PreviewConfig.i18n.states}</span>
                    ${QuickSiteUtils.svgIcon(QuickSiteUtils.ICON_PATHS.arrowRight, 12, 'preview-animated-selector__arrow')}
                </div>
                <div class="preview-animated-selector-group__states">
                    ${statesHtml}
                </div>
            </div>
        `;
    }

    // ==================== Format Details ====================
    
    function formatTransitionDetails(parsed) {
        if (!Array.isArray(parsed) || parsed.length === 0) return '';
        
        return parsed.map(t => {
            const parts = [];
            if (t.property && t.property !== 'all') parts.push(t.property);
            if (t.duration) parts.push(t.duration);
            if (t.timing && t.timing !== 'ease') parts.push(t.timing);
            return parts.join(' ');
        }).filter(Boolean).join(', ') || 'transition';
    }
    
    function formatAnimationDetails(parsed) {
        if (!Array.isArray(parsed) || parsed.length === 0) return '';
        
        return parsed.map(a => {
            const parts = [];
            if (a.name) parts.push(a.name);
            if (a.duration) parts.push(a.duration);
            if (a.iterationCount && a.iterationCount !== '1') parts.push(a.iterationCount + 'x');
            return parts.join(' ');
        }).filter(Boolean).join(', ') || 'animation';
    }

    // ==================== Preview Keyframe ====================
    
    async function previewKeyframe(keyframeName) {
        if (!animationPreviewModal) return;
        
        currentPreviewKeyframe = keyframeName;
        
        // Update modal header
        if (animationPreviewName) {
            animationPreviewName.textContent = `@keyframes ${keyframeName}`;
        }
        
        // Reset controls to defaults
        if (animationPreviewDuration) animationPreviewDuration.value = 1000;
        if (animationPreviewTiming) animationPreviewTiming.value = 'ease';
        if (animationPreviewDelay) animationPreviewDelay.value = 0;
        if (animationPreviewCount) animationPreviewCount.value = 1;
        if (animationPreviewInfinite) animationPreviewInfinite.checked = false;
        
        // Inject the keyframe CSS
        await injectKeyframeForPreview(keyframeName);
        
        // Update CSS preview
        updateAnimationPreviewCss();
        
        // Show modal
        animationPreviewModal.classList.add('preview-keyframe-modal--visible');
        
        // Auto-play the animation
        setTimeout(() => playAnimationPreview(), 100);
    }
    
    async function injectKeyframeForPreview(keyframeName) {
        const existingStyle = document.getElementById('animation-preview-keyframe-style');
        if (existingStyle) existingStyle.remove();
        
        const keyframeData = keyframesData.find(kf => kf.name === keyframeName);
        if (!keyframeData) return;
        
        try {
            const response = await fetch(managementUrl + 'getKeyframes/' + encodeURIComponent(keyframeName), {
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.status === 200 && data.data?.frames) {
                    let css = `@keyframes ${keyframeName} {\n`;
                    for (const [key, value] of Object.entries(data.data.frames)) {
                        css += `  ${key} { ${value} }\n`;
                    }
                    css += '}';
                    
                    const style = document.createElement('style');
                    style.id = 'animation-preview-keyframe-style';
                    style.textContent = css;
                    document.head.appendChild(style);
                }
            }
        } catch (e) {
            console.error('Failed to fetch keyframe for preview:', e);
        }
    }
    
    function updateAnimationPreviewCss() {
        if (!animationPreviewCss || !currentPreviewKeyframe) return;
        
        const duration = animationPreviewDuration?.value || 1000;
        const timing = animationPreviewTiming?.value || 'ease';
        const delay = animationPreviewDelay?.value || 0;
        const isInfinite = animationPreviewInfinite?.checked;
        const count = isInfinite ? 'infinite' : (animationPreviewCount?.value || 1);
        
        animationPreviewCss.textContent = `animation: ${duration}ms ${timing} ${delay}ms ${currentPreviewKeyframe};\nanimation-iteration-count: ${count};`;
    }
    
    function playAnimationPreview() {
        if (!animationPreviewStage || !currentPreviewKeyframe) return;
        
        const duration = animationPreviewDuration?.value || 1000;
        const timing = animationPreviewTiming?.value || 'ease';
        const delay = animationPreviewDelay?.value || 0;
        const isInfinite = animationPreviewInfinite?.checked;
        const count = isInfinite ? 'infinite' : (animationPreviewCount?.value || 1);
        
        let logo = document.getElementById('animation-preview-logo');
        
        if (logo) {
            const logoSrc = logo.src;
            const logoAlt = logo.alt;
            
            logo.remove();
            
            requestAnimationFrame(() => {
                const newLogo = document.createElement('img');
                newLogo.id = 'animation-preview-logo';
                newLogo.className = 'animation-preview__logo';
                newLogo.src = logoSrc;
                newLogo.alt = logoAlt;
                
                newLogo.style.animation = `${currentPreviewKeyframe} ${duration}ms ${timing} ${delay}ms`;
                newLogo.style.animationIterationCount = count;
                newLogo.style.animationFillMode = 'forwards';
                
                animationPreviewStage.appendChild(newLogo);
            });
        }
    }
    
    function closeAnimationPreview() {
        if (animationPreviewModal) {
            animationPreviewModal.classList.remove('preview-keyframe-modal--visible');
        }
        
        const logo = document.getElementById('animation-preview-logo');
        if (logo) {
            logo.style.animation = '';
        }
        
        currentPreviewKeyframe = null;
    }
    
    function initAnimationPreviewModal() {
        if (animationPreviewCloseBtn) {
            animationPreviewCloseBtn.addEventListener('click', closeAnimationPreview);
        }
        if (animationPreviewDoneBtn) {
            animationPreviewDoneBtn.addEventListener('click', closeAnimationPreview);
        }
        
        const backdrop = animationPreviewModal?.querySelector('.preview-keyframe-modal__backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closeAnimationPreview);
        }
        
        if (animationPreviewPlayBtn) {
            animationPreviewPlayBtn.addEventListener('click', playAnimationPreview);
        }
        
        const controls = [animationPreviewDuration, animationPreviewTiming, animationPreviewDelay, animationPreviewCount, animationPreviewInfinite];
        controls.forEach(ctrl => {
            if (ctrl) {
                ctrl.addEventListener('change', updateAnimationPreviewCss);
                ctrl.addEventListener('input', updateAnimationPreviewCss);
            }
        });
        
        // A3-companion Motion Slice 3 — wire "Custom curve…" button to the
        // QSEasingPicker. Pre-loads the picker with the select's current
        // value; on confirm, adds the resulting cubic-bezier as an <option>
        // (if not already present) + selects it + dispatches change so the
        // existing updateAnimationPreviewCss listener re-fires.
        const timingCustomBtn = document.getElementById('animation-preview-timing-custom');
        if (timingCustomBtn && animationPreviewTiming && window.QSEasingPicker) {
            timingCustomBtn.addEventListener('click', () => {
                const currentValue = animationPreviewTiming.value || 'ease';
                QSEasingPicker.open({
                    anchor: timingCustomBtn,
                    value:  currentValue,
                    onConfirm: (newValue) => {
                        // Ensure an option exists for the chosen value.
                        let opt = Array.from(animationPreviewTiming.options)
                            .find(o => o.value === newValue);
                        if (!opt) {
                            opt = document.createElement('option');
                            opt.value = newValue;
                            opt.textContent = newValue;
                            animationPreviewTiming.appendChild(opt);
                        }
                        animationPreviewTiming.value = newValue;
                        animationPreviewTiming.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        }

        if (animationPreviewInfinite) {
            animationPreviewInfinite.addEventListener('change', () => {
                if (animationPreviewCount) {
                    animationPreviewCount.disabled = animationPreviewInfinite.checked;
                }
            });
        }
    }

    // ==================== Edit/Create Keyframe ====================
    
    async function editKeyframe(keyframeName) {
        try {
            const response = await fetch(managementUrl + 'getKeyframes/' + encodeURIComponent(keyframeName), {
                method: 'GET',
                headers: authToken ? { 'Authorization': `Bearer ${authToken}` } : {}
            });
            
            const data = await response.json();
            
            if (data.status === 200 && data.data && data.data.frames) {
                const keyframeData = data.data.frames;
                const frames = parseKeyframeData(keyframeData);
                openKeyframeModal(keyframeName, frames, 'edit');
            } else {
                throw new Error(data.message || 'Keyframe not found');
            }
        } catch (error) {
            console.error('Failed to load keyframe:', error);
            showToast(PreviewConfig.i18n.loadKeyframeFailed, 'error');
        }
    }
    
    function createNewKeyframe() {
        const defaultFrames = [
            { percent: 0, properties: [{ property: 'opacity', value: '0' }] },
            { percent: 100, properties: [{ property: 'opacity', value: '1' }] }
        ];
        openKeyframeModal('', defaultFrames, 'create');
    }
    
    function parseKeyframeData(keyframeData) {
        const frames = [];
        
        if (typeof keyframeData === 'object' && keyframeData !== null) {
            for (const [key, value] of Object.entries(keyframeData)) {
                let percent = key.trim();
                if (percent.toLowerCase() === 'from') percent = 0;
                else if (percent.toLowerCase() === 'to') percent = 100;
                else percent = parseFloat(percent);
                
                const properties = [];
                const propRegex = /([a-z-]+)\s*:\s*([^;]+);?/gi;
                let propMatch;
                while ((propMatch = propRegex.exec(value)) !== null) {
                    properties.push({
                        property: propMatch[1].trim(),
                        value: propMatch[2].trim()
                    });
                }
                
                frames.push({ percent, properties });
            }
        } else if (typeof keyframeData === 'string') {
            const frameRegex = /([\d.]+%|from|to)\s*\{([^}]*)\}/gi;
            let match;
            
            while ((match = frameRegex.exec(keyframeData)) !== null) {
                let percent = match[1].trim();
                if (percent.toLowerCase() === 'from') percent = 0;
                else if (percent.toLowerCase() === 'to') percent = 100;
                else percent = parseFloat(percent);
                
                const propertiesStr = match[2].trim();
                const properties = [];
                
                const propRegex = /([a-z-]+)\s*:\s*([^;]+);?/gi;
                let propMatch;
                while ((propMatch = propRegex.exec(propertiesStr)) !== null) {
                    properties.push({
                        property: propMatch[1].trim(),
                        value: propMatch[2].trim()
                    });
                }
                
                frames.push({ percent, properties });
            }
        }
        
        frames.sort((a, b) => a.percent - b.percent);
        return frames;
    }
    
    function openKeyframeModal(name, frames, mode) {
        keyframeEditorMode = mode;
        editingKeyframeName = name;
        keyframeFrames = JSON.parse(JSON.stringify(frames));
        selectedFramePercent = frames.length > 0 ? frames[0].percent : 0;
        
        keyframeModalTitle.textContent = mode === 'edit' 
            ? (PreviewConfig.i18n.editKeyframe)
            : (PreviewConfig.i18n.createKeyframe);
        
        keyframeNameInput.value = name;
        keyframeNameInput.readOnly = (mode === 'edit');
        
        renderKeyframeTimeline();
        renderKeyframeFrames();
        
        keyframeModal.classList.add('preview-keyframe-modal--visible');
        document.body.style.overflow = 'hidden';
    }
    
    function closeKeyframeModal() {
        keyframeModal.classList.remove('preview-keyframe-modal--visible');
        document.body.style.overflow = '';
        keyframeEditorMode = null;
        editingKeyframeName = null;
        keyframeFrames = [];
        selectedFramePercent = null;
        
        stopKeyframePreviewInIframe();
    }

    // ==================== Keyframe Timeline ====================
    
    function renderKeyframeTimeline() {
        keyframeTimeline.innerHTML = '';
        
        const bar = document.createElement('div');
        bar.className = 'preview-keyframe-modal__timeline-bar';
        keyframeTimeline.appendChild(bar);
        
        keyframeFrames.forEach((frame, index) => {
            const marker = document.createElement('div');
            marker.className = 'preview-keyframe-modal__timeline-marker';
            if (frame.percent === selectedFramePercent) {
                marker.classList.add('preview-keyframe-modal__timeline-marker--selected');
            }
            marker.style.left = frame.percent + '%';
            marker.title = frame.percent + '%';
            marker.dataset.percent = frame.percent;
            marker.draggable = false;
            
            marker.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!marker.dataset.dragging) {
                    selectFrame(frame.percent);
                }
            });
            
            // Drag to move marker
            let isDragging = false;
            let startX = 0;
            
            marker.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                isDragging = true;
                startX = e.clientX;
                marker.classList.add('preview-keyframe-modal__timeline-marker--dragging');
                marker.dataset.dragging = 'true';
                
                const originalPercent = frame.percent;
                const barRect = bar.getBoundingClientRect();
                
                const onMouseMove = (moveEvent) => {
                    if (!isDragging) return;
                    const newPercent = Math.round(((moveEvent.clientX - barRect.left) / barRect.width) * 100);
                    const clampedPercent = Math.max(0, Math.min(100, newPercent));
                    marker.style.left = clampedPercent + '%';
                    marker.title = clampedPercent + '%';
                };
                
                const onMouseUp = (upEvent) => {
                    if (!isDragging) return;
                    isDragging = false;
                    marker.classList.remove('preview-keyframe-modal__timeline-marker--dragging');
                    
                    const newPercent = Math.round(((upEvent.clientX - barRect.left) / barRect.width) * 100);
                    const clampedPercent = Math.max(0, Math.min(100, newPercent));
                    
                    if (Math.abs(upEvent.clientX - startX) < 5) {
                        delete marker.dataset.dragging;
                        selectFrame(frame.percent);
                    } else if (clampedPercent !== originalPercent) {
                        const existingFrame = keyframeFrames.find(f => f.percent === clampedPercent && f !== frame);
                        if (existingFrame) {
                            marker.style.left = originalPercent + '%';
                            showNotification(PreviewConfig.i18n.frameExists, 'error');
                        } else {
                            frame.percent = clampedPercent;
                            if (selectedFramePercent === originalPercent) {
                                selectedFramePercent = clampedPercent;
                            }
                            keyframeFrames.sort((a, b) => a.percent - b.percent);
                            renderKeyframeTimeline();
                            renderKeyframeFrames();
                        }
                    }
                    
                    setTimeout(() => delete marker.dataset.dragging, 10);
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
            
            keyframeTimeline.appendChild(marker);
        });
        
        bar.addEventListener('click', (e) => {
            const rect = bar.getBoundingClientRect();
            const percent = Math.round(((e.clientX - rect.left) / rect.width) * 100);
            if (!keyframeFrames.find(f => f.percent === percent)) {
                addKeyframeFrame(percent);
            } else {
                selectFrame(percent);
            }
        });
    }
    
    function selectFrame(percent) {
        selectedFramePercent = percent;
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }

    // ==================== Keyframe Frames Editor ====================
    
    function renderKeyframeFrames() {
        keyframeFramesContainer.innerHTML = '';
        
        const commonProperties = [
            '', 'opacity', 'transform', 'background-color', 'color', 'width', 'height',
            'top', 'left', 'right', 'bottom', 'margin', 'padding', 'border-radius',
            'box-shadow', 'filter', 'clip-path', 'scale', 'rotate', 'translate', 'skew',
            'visibility', 'z-index', 'font-size', 'letter-spacing', 'line-height', 'text-shadow'
        ];
        
        keyframeFrames.forEach((frame, frameIndex) => {
            const frameEl = document.createElement('div');
            frameEl.className = 'preview-keyframe-modal__frame';
            if (frame.percent === selectedFramePercent) {
                frameEl.classList.add('preview-keyframe-modal__frame--selected');
            }
            
            // Frame header
            const header = document.createElement('div');
            header.className = 'preview-keyframe-modal__frame-header';
            header.innerHTML = `
                <div class="preview-keyframe-modal__frame-percent-group">
                    <input type="number" class="preview-keyframe-modal__frame-percent-input" 
                           value="${frame.percent}" min="0" max="100" step="1"
                           title="${PreviewConfig.i18n.enterFramePercent}">
                    <span class="preview-keyframe-modal__frame-percent-symbol">%</span>
                </div>
                <div class="preview-keyframe-modal__frame-actions">
                    ${keyframeFrames.length > 1 ? `
                        <button type="button" class="preview-keyframe-modal__delete-frame" title="${PreviewConfig.i18n.deleteFrame}">
                            ${QuickSiteUtils.iconTrash()}
                        </button>
                    ` : ''}
                </div>
            `;
            
            // Percent input change handler
            const percentInput = header.querySelector('.preview-keyframe-modal__frame-percent-input');
            percentInput.addEventListener('change', (e) => {
                const newPercent = parseInt(e.target.value, 10);
                if (isNaN(newPercent) || newPercent < 0 || newPercent > 100) {
                    e.target.value = frame.percent;
                    return;
                }
                const existingFrame = keyframeFrames.find(f => f.percent === newPercent && f !== frame);
                if (existingFrame) {
                    e.target.value = frame.percent;
                    showNotification(PreviewConfig.i18n.frameExists, 'error');
                    return;
                }
                const oldPercent = frame.percent;
                frame.percent = newPercent;
                if (selectedFramePercent === oldPercent) {
                    selectedFramePercent = newPercent;
                }
                keyframeFrames.sort((a, b) => a.percent - b.percent);
                renderKeyframeTimeline();
                renderKeyframeFrames();
            });
            
            header.addEventListener('click', (e) => {
                if (!e.target.closest('input') && !e.target.closest('button')) {
                    selectFrame(frame.percent);
                }
            });
            
            const deleteBtn = header.querySelector('.preview-keyframe-modal__delete-frame');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    deleteKeyframeFrame(frame.percent);
                });
            }
            
            frameEl.appendChild(header);
            
            // Properties container
            const propsContainer = document.createElement('div');
            propsContainer.className = 'preview-keyframe-modal__properties';
            
            frame.properties.forEach((prop, propIndex) => {
                const propEl = document.createElement('div');
                propEl.className = 'preview-keyframe-modal__property';
                
                const isCommonProp = commonProperties.includes(prop.property);
                
                let selectOptions = commonProperties.map(p => {
                    if (p === '') {
                        return `<option value="" ${!isCommonProp ? 'selected' : ''}>${PreviewConfig.i18n.customProperty}</option>`;
                    }
                    return `<option value="${p}" ${prop.property === p ? 'selected' : ''}>${p}</option>`;
                }).join('');
                
                propEl.innerHTML = `
                    <div class="preview-keyframe-modal__property-name-group">
                        <select class="preview-keyframe-modal__property-select" 
                                data-frame="${frameIndex}" data-prop="${propIndex}">
                            ${selectOptions}
                        </select>
                        <input type="text" class="preview-keyframe-modal__property-name ${isCommonProp ? 'preview-keyframe-modal__property-name--hidden' : ''}" 
                               value="${escapeHtml(prop.property)}" 
                               placeholder="${PreviewConfig.i18n.keyframePropertyName}"
                               data-frame="${frameIndex}" data-prop="${propIndex}" data-field="property">
                    </div>
                    <span class="preview-keyframe-modal__property-colon">:</span>
                    <input type="text" class="preview-keyframe-modal__property-value" 
                           value="${escapeHtml(prop.value)}" 
                           placeholder="${PreviewConfig.i18n.keyframePropertyValue}"
                           data-frame="${frameIndex}" data-prop="${propIndex}" data-field="value">
                    <button type="button" class="preview-keyframe-modal__delete-property" title="${PreviewConfig.i18n.deleteKeyframeProperty}">
                        ${QuickSiteUtils.iconClose(12)}
                    </button>
                `;
                
                const propSelect = propEl.querySelector('.preview-keyframe-modal__property-select');
                const nameInput = propEl.querySelector('.preview-keyframe-modal__property-name');
                const valueInput = propEl.querySelector('.preview-keyframe-modal__property-value');
                const deletePropertyBtn = propEl.querySelector('.preview-keyframe-modal__delete-property');
                
                propSelect.addEventListener('change', (e) => {
                    if (e.target.value === '') {
                        nameInput.classList.remove('preview-keyframe-modal__property-name--hidden');
                        nameInput.focus();
                    } else {
                        nameInput.classList.add('preview-keyframe-modal__property-name--hidden');
                        nameInput.value = e.target.value;
                        keyframeFrames[frameIndex].properties[propIndex].property = e.target.value;
                    }
                });
                
                nameInput.addEventListener('input', (e) => {
                    keyframeFrames[frameIndex].properties[propIndex].property = e.target.value;
                });
                
                valueInput.addEventListener('input', (e) => {
                    keyframeFrames[frameIndex].properties[propIndex].value = e.target.value;
                });
                
                deletePropertyBtn.addEventListener('click', () => {
                    deleteKeyframeProperty(frameIndex, propIndex);
                });
                
                propsContainer.appendChild(propEl);
            });
            
            // Add property button
            const addPropBtn = document.createElement('button');
            addPropBtn.type = 'button';
            addPropBtn.className = 'preview-keyframe-modal__add-property';
            addPropBtn.innerHTML = `
                ${QuickSiteUtils.iconPlus(12)}
                ${PreviewConfig.i18n.addKeyframeProperty}
            `;
            addPropBtn.addEventListener('click', () => addKeyframeProperty(frameIndex));
            
            propsContainer.appendChild(addPropBtn);
            frameEl.appendChild(propsContainer);
            
            keyframeFramesContainer.appendChild(frameEl);
        });
        
        // Add frame button at the end
        const addFrameBtn = document.createElement('button');
        addFrameBtn.type = 'button';
        addFrameBtn.className = 'preview-keyframe-modal__add-frame';
        addFrameBtn.innerHTML = `
            ${QuickSiteUtils.iconPlus(14)}
            ${PreviewConfig.i18n.addFrame}
        `;
        addFrameBtn.addEventListener('click', () => promptAddFrame());
        keyframeFramesContainer.appendChild(addFrameBtn);
    }
    
    function promptAddFrame() {
        const percent = prompt(PreviewConfig.i18n.enterFramePercent, '50');
        if (percent !== null) {
            const percentNum = parseInt(percent, 10);
            if (!isNaN(percentNum) && percentNum >= 0 && percentNum <= 100) {
                if (keyframeFrames.find(f => f.percent === percentNum)) {
                    showToast(PreviewConfig.i18n.frameExists, 'warning');
                } else {
                    addKeyframeFrame(percentNum);
                }
            } else {
                showToast(PreviewConfig.i18n.invalidPercent, 'error');
            }
        }
    }
    
    function addKeyframeFrame(percent) {
        let beforeFrame = null;
        let afterFrame = null;
        
        for (const frame of keyframeFrames) {
            if (frame.percent < percent) {
                beforeFrame = frame;
            } else if (frame.percent > percent && !afterFrame) {
                afterFrame = frame;
            }
        }
        
        const newFrame = {
            percent: percent,
            properties: []
        };
        
        const sourceFrame = beforeFrame || afterFrame || keyframeFrames[0];
        if (sourceFrame) {
            newFrame.properties = sourceFrame.properties.map(p => ({ ...p }));
        } else {
            newFrame.properties = [{ property: 'opacity', value: '1' }];
        }
        
        keyframeFrames.push(newFrame);
        keyframeFrames.sort((a, b) => a.percent - b.percent);
        
        selectedFramePercent = percent;
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }
    
    function deleteKeyframeFrame(percent) {
        if (keyframeFrames.length <= 1) {
            showToast(PreviewConfig.i18n.cannotDeleteLastFrame, 'warning');
            return;
        }
        
        keyframeFrames = keyframeFrames.filter(f => f.percent !== percent);
        
        if (selectedFramePercent === percent) {
            selectedFramePercent = keyframeFrames[0].percent;
        }
        
        renderKeyframeTimeline();
        renderKeyframeFrames();
    }
    
    function addKeyframeProperty(frameIndex) {
        keyframeFrames[frameIndex].properties.push({
            property: '',
            value: ''
        });
        renderKeyframeFrames();
        
        setTimeout(() => {
            const inputs = keyframeFramesContainer.querySelectorAll(`.preview-keyframe-modal__property-name[data-frame="${frameIndex}"]`);
            const lastInput = inputs[inputs.length - 1];
            if (lastInput) lastInput.focus();
        }, 0);
    }
    
    function deleteKeyframeProperty(frameIndex, propIndex) {
        if (keyframeFrames[frameIndex].properties.length <= 1) {
            showToast(PreviewConfig.i18n.cannotDeleteLastProperty, 'warning');
            return;
        }
        
        keyframeFrames[frameIndex].properties.splice(propIndex, 1);
        renderKeyframeFrames();
    }

    // ==================== Generate/Preview/Save Keyframe ====================
    
    function generateKeyframeCSS(name, frames) {
        let css = `@keyframes ${name} {\n`;
        
        frames.forEach(frame => {
            const percent = frame.percent + '%';
            css += `    ${percent} {\n`;
            
            frame.properties.forEach(prop => {
                if (prop.property && prop.value) {
                    css += `        ${prop.property}: ${prop.value};\n`;
                }
            });
            
            css += `    }\n`;
        });
        
        css += `}`;
        return css;
    }
    
    function previewKeyframeAnimation() {
        const name = keyframeNameInput.value.trim() || 'preview-temp-animation';
        
        if (keyframeFrames.length === 0) {
            showToast(PreviewConfig.i18n.noFramesToPreview, 'warning');
            return;
        }
        
        const keyframeCSS = generateKeyframeCSS(name, keyframeFrames);
        
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            stopKeyframePreviewInIframe();
            
            keyframePreviewStyleElement = iframeDoc.createElement('style');
            keyframePreviewStyleElement.id = 'preview-keyframe-animation-style';
            keyframePreviewStyleElement.textContent = keyframeCSS;
            iframeDoc.head.appendChild(keyframePreviewStyleElement);
            
            let targetElement = null;
            const selectedElement = window.PreviewState?.get('selectedElement');
            if (selectedElement && selectedElement.element) {
                targetElement = selectedElement.element;
            } else {
                targetElement = iframeDoc.body;
            }
            
            if (targetElement) {
                const originalAnimation = targetElement.style.animation;
                targetElement.style.animation = `${name} 1s ease-in-out infinite`;
                targetElement.dataset.previewOriginalAnimation = originalAnimation;
                targetElement.dataset.previewAnimating = 'true';
                
                showToast(PreviewConfig.i18n.previewingAnimation, 'info');
            }
        } catch (error) {
            console.error('Failed to preview animation:', error);
            showToast(PreviewConfig.i18n.previewFailed, 'error');
        }
    }
    
    function stopKeyframePreviewInIframe() {
        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            const existingStyle = iframeDoc.getElementById('preview-keyframe-animation-style');
            if (existingStyle) {
                existingStyle.remove();
            }
            
            const animatingElements = iframeDoc.querySelectorAll('[data-preview-animating="true"]');
            animatingElements.forEach(el => {
                el.style.animation = el.dataset.previewOriginalAnimation || '';
                delete el.dataset.previewOriginalAnimation;
                delete el.dataset.previewAnimating;
            });
            
            keyframePreviewStyleElement = null;
        } catch (error) {
            console.error('Failed to stop preview:', error);
        }
    }
    
    async function saveKeyframe() {
        const name = keyframeNameInput.value.trim();
        
        if (!name) {
            showToast(PreviewConfig.i18n.keyframeNameRequired, 'error');
            keyframeNameInput.focus();
            return;
        }
        
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(name)) {
            showToast(PreviewConfig.i18n.invalidKeyframeName, 'error');
            keyframeNameInput.focus();
            return;
        }
        
        let allowOverwrite = keyframeEditorMode === 'edit';
        
        if (keyframeEditorMode === 'create') {
            const existingKeyframe = keyframesData.find(kf => kf.name === name);
            if (existingKeyframe) {
                const confirmOverwrite = confirm(
                    PreviewConfig.i18n.keyframeExistsConfirm +
                    `\n\n@keyframes ${name}`
                );
                if (!confirmOverwrite) {
                    keyframeNameInput.focus();
                    keyframeNameInput.select();
                    return;
                }
                allowOverwrite = true;
            }
        }
        
        const validFrames = keyframeFrames.filter(f => 
            f.properties.some(p => p.property && p.value)
        );
        
        if (validFrames.length === 0) {
            showToast(PreviewConfig.i18n.atLeastOneFrame, 'error');
            return;
        }
        
        const framesObj = {};
        validFrames.forEach(frame => {
            const key = frame.percent + '%';
            const propsStr = frame.properties
                .filter(p => p.property && p.value)
                .map(p => `${p.property}: ${p.value};`)
                .join(' ');
            framesObj[key] = propsStr;
        });
        
        try {
            stopKeyframePreviewInIframe();
            
            const response = await fetch(managementUrl + 'setKeyframes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({
                    name: name,
                    frames: framesObj,
                    allowOverwrite: allowOverwrite
                })
            });
            
            const data = await response.json();
            
            if (data.status === 409) {
                const confirmOverwrite = confirm(
                    PreviewConfig.i18n.keyframeExistsConfirm +
                    `\n\n@keyframes ${name}`
                );
                if (confirmOverwrite) {
                    const retryResponse = await fetch(managementUrl + 'setKeyframes', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                        },
                        body: JSON.stringify({
                            name: name,
                            frames: framesObj,
                            allowOverwrite: true
                        })
                    });
                    const retryData = await retryResponse.json();
                    if (retryData.status !== 200) {
                        throw new Error(retryData.message || 'Failed to save keyframe');
                    }
                } else {
                    keyframeNameInput.focus();
                    keyframeNameInput.select();
                    return;
                }
            } else if (data.status !== 200) {
                throw new Error(data.message || 'Failed to save keyframe');
            }
            
            showToast(
                keyframeEditorMode === 'create' 
                    ? (PreviewConfig.i18n.keyframeCreated)
                    : (PreviewConfig.i18n.keyframeSaved),
                'success'
            );
            
            closeKeyframeModal();
            
            animationsLoaded = false;
            loadAnimationsTab();
            
            // Hot-reload CSS to show saved keyframe
            if (window.PreviewState?.hotReloadCss) {
                PreviewState.hotReloadCss();
            } else if (window.PreviewState) {
                PreviewState.reloadPreview();
            }
            
        } catch (error) {
            console.error('Failed to save keyframe:', error);
            showToast(PreviewConfig.i18n.saveKeyframeFailed, 'error');
        }
    }
    
    async function deleteKeyframe(keyframeName) {
        if (!confirm(PreviewConfig.i18n.confirmDeleteKeyframe + `\n\n@keyframes ${keyframeName}`)) {
            return;
        }
        
        try {
            const response = await fetch(managementUrl + 'deleteKeyframes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(authToken ? { 'Authorization': `Bearer ${authToken}` } : {})
                },
                body: JSON.stringify({ name: keyframeName })
            });
            
            const data = await response.json();
            
            if (data.status === 200) {
                showToast(PreviewConfig.i18n.keyframeDeleted, 'success');
                animationsLoaded = false;
                loadAnimationsTab();
                
                // Hot-reload CSS to reflect keyframe deletion
                if (window.PreviewState?.hotReloadCss) {
                    PreviewState.hotReloadCss();
                } else if (window.PreviewState) {
                    PreviewState.reloadPreview();
                }
            } else {
                throw new Error(data.message || 'Failed to delete keyframe');
            }
        } catch (error) {
            console.error('Failed to delete keyframe:', error);
            showToast(PreviewConfig.i18n.deleteKeyframeFailed, 'error');
        }
    }

    // ==================== Apply-keyframe-to-selector (Motion Slice 2) ====================
    // Modal that lists every selector in style.css (sourced from
    // PreviewSelectorBrowser's cache, same data the Selectors tab uses),
    // filtered by substring. On confirm, writes `animation: <name> 1s ease;`
    // to the chosen selector via setStyleRule + hot-reloads the iframe.
    // Default value is one-shot (no iteration count); users can refine via
    // Selectors → Edit Styles afterwards.

    const _apply = {
        modal:        null,
        backdrop:     null,
        closeBtn:     null,
        cancelBtn:    null,
        applyBtn:     null,
        nameEl:       null,
        searchInput:  null,
        list:         null,
        keyframe:     null,    // name of the keyframe being applied
        selected:     null,    // currently-highlighted selector
        wired:        false
    };

    function initApplyKeyframeModal() {
        if (_apply.wired) return;
        _apply.modal       = document.getElementById('apply-keyframe-modal');
        _apply.backdrop    = document.getElementById('apply-keyframe-modal-backdrop');
        _apply.closeBtn    = document.getElementById('apply-keyframe-modal-close');
        _apply.cancelBtn   = document.getElementById('apply-keyframe-modal-cancel');
        _apply.applyBtn    = document.getElementById('apply-keyframe-modal-apply');
        _apply.nameEl      = document.getElementById('apply-keyframe-modal-name');
        _apply.searchInput = document.getElementById('apply-keyframe-modal-search');
        _apply.list        = document.getElementById('apply-keyframe-modal-list');
        if (!_apply.modal) return;

        _apply.closeBtn   && _apply.closeBtn.addEventListener('click', closeApplyKeyframeModal);
        _apply.cancelBtn  && _apply.cancelBtn.addEventListener('click', closeApplyKeyframeModal);
        _apply.backdrop   && _apply.backdrop.addEventListener('click', closeApplyKeyframeModal);
        _apply.searchInput && _apply.searchInput.addEventListener('input', () => {
            renderApplyKeyframeList(_apply.searchInput.value);
        });
        _apply.searchInput && _apply.searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); closeApplyKeyframeModal(); }
            else if (e.key === 'Enter' && _apply.selected) {
                e.preventDefault();
                submitApplyKeyframe();
            }
        });
        _apply.applyBtn && _apply.applyBtn.addEventListener('click', submitApplyKeyframe);
        _apply.wired = true;
    }

    async function applyKeyframeToSelector(keyframeName) {
        initApplyKeyframeModal();
        if (!_apply.modal) return;
        _apply.keyframe = keyframeName;
        _apply.selected = null;
        if (_apply.nameEl) _apply.nameEl.textContent = '@keyframes ' + keyframeName;
        if (_apply.searchInput) _apply.searchInput.value = '';
        if (_apply.applyBtn) _apply.applyBtn.disabled = true;
        // Show modal first with a loading placeholder — the selector cache
        // is lazy-loaded on first Selectors-tab visit; if the user jumped
        // straight from Motion → Apply without going through Selectors,
        // we need to fetch the data before rendering. Otherwise the list
        // would show the "No selectors yet" empty state spuriously.
        _apply.modal.hidden = false;
        if (_apply.list) {
            _apply.list.innerHTML = '';
            const loading = document.createElement('div');
            loading.className = 'apply-keyframe-modal__empty';
            loading.textContent = (PreviewConfig.i18n && PreviewConfig.i18n.loading) || 'Loading…';
            _apply.list.appendChild(loading);
        }
        await ensureSelectorsLoaded();
        renderApplyKeyframeList('');
        if (_apply.searchInput) _apply.searchInput.focus();
    }

    async function ensureSelectorsLoaded() {
        if (!window.PreviewSelectorBrowser) return;
        const loadedFn = PreviewSelectorBrowser.isLoaded;
        if (loadedFn && loadedFn()) return;
        if (PreviewSelectorBrowser.loadData) {
            try { await PreviewSelectorBrowser.loadData(); }
            catch (e) { /* renderApplyKeyframeList will show empty state */ }
        }
    }

    function closeApplyKeyframeModal() {
        if (!_apply.modal) return;
        _apply.modal.hidden = true;
        _apply.keyframe = null;
        _apply.selected = null;
    }

    function renderApplyKeyframeList(filter) {
        if (!_apply.list) return;
        const i18n = PreviewConfig.i18n || {};
        // Source the selector list from PreviewSelectorBrowser if available.
        // It exposes getAllSelectors() returning the unified list the
        // Selectors tab uses — which matches the user's mental model
        // ("apply to any selector I can otherwise edit").
        let selectors = [];
        if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.getAllSelectors) {
            try { selectors = PreviewSelectorBrowser.getAllSelectors() || []; }
            catch (e) { selectors = []; }
        }
        if (!selectors.length) {
            _apply.list.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'apply-keyframe-modal__empty';
            empty.textContent = i18n.applyKeyframeNoSelectors || 'No selectors yet — add one via the Selectors tab.';
            _apply.list.appendChild(empty);
            return;
        }
        const needle = String(filter || '').toLowerCase().trim();
        const matched = needle
            ? selectors.filter(s => (s.selector || s).toLowerCase().indexOf(needle) !== -1)
            : selectors.slice();

        _apply.list.innerHTML = '';
        if (!matched.length) {
            const empty = document.createElement('div');
            empty.className = 'apply-keyframe-modal__empty';
            empty.textContent = i18n.applyKeyframeNoMatch || 'No selector matches.';
            _apply.list.appendChild(empty);
            return;
        }
        const frag = document.createDocumentFragment();
        matched.forEach(s => {
            const sel = s.selector || s;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'apply-keyframe-modal__item';
            item.dataset.selector = sel;
            item.setAttribute('role', 'option');
            const code = document.createElement('code');
            code.textContent = sel;
            item.appendChild(code);
            // Show a small media-query badge if the selector lives inside one,
            // so the user picks the right occurrence.
            const mq = s.mediaQuery;
            if (mq) {
                const badge = document.createElement('span');
                badge.className = 'apply-keyframe-modal__item-mq';
                badge.textContent = '@media ' + mq;
                item.appendChild(badge);
            }
            item.addEventListener('click', () => selectApplyKeyframeItem(sel, item));
            frag.appendChild(item);
        });
        _apply.list.appendChild(frag);
    }

    function selectApplyKeyframeItem(selector, itemEl) {
        // Clear previous highlight
        _apply.list.querySelectorAll('.apply-keyframe-modal__item--selected')
            .forEach(el => el.classList.remove('apply-keyframe-modal__item--selected'));
        itemEl.classList.add('apply-keyframe-modal__item--selected');
        _apply.selected = selector;
        if (_apply.applyBtn) _apply.applyBtn.disabled = false;
    }

    async function submitApplyKeyframe() {
        if (!_apply.keyframe || !_apply.selected) return;
        const i18n = PreviewConfig.i18n || {};
        const keyframe = _apply.keyframe;
        const selector = _apply.selected;
        if (_apply.applyBtn) _apply.applyBtn.disabled = true;
        try {
            // Sensible defaults — one-shot at 1s with `ease`. Users can fine-tune
            // via Selectors → Edit Styles, where the full animation shorthand is
            // editable.
            const body = {
                selector: selector,
                styles:   'animation: ' + keyframe + ' 1s ease;'
            };
            let ok = false;
            let errMsg = '';
            if (window.QuickSiteAPI && QuickSiteAPI.request) {
                const result = await QuickSiteAPI.request('setStyleRule', 'POST', body);
                ok = !!result.ok;
                errMsg = (result.data && (result.data.message || result.data.error)) || '';
            } else {
                const response = await fetch(managementUrl + 'setStyleRule', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(authToken ? { 'Authorization': 'Bearer ' + authToken } : {})
                    },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                ok = (data.status === 200 || data.status === 201);
                errMsg = data.message || '';
            }
            if (!ok) throw new Error(errMsg || 'setStyleRule failed');

            const tpl = i18n.applyKeyframeAdded || '{name} applied to {selector}';
            showToast(tpl.replace('{name}', keyframe).replace('{selector}', selector), 'success');
            closeApplyKeyframeModal();

            // Refresh the Selectors / Motion caches so the new animation
            // shows up under "Animations" in this same tab + any selector
            // listings elsewhere.
            if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.reset) {
                PreviewSelectorBrowser.reset();
            }
            animationsLoaded = false;
            loadAnimationsTab();

            // Live preview — the iframe needs to pick up the new animation
            // rule from style.css.
            if (window.PreviewState && PreviewState.hotReloadCss) {
                PreviewState.hotReloadCss();
            }
        } catch (err) {
            const tpl = i18n.applyKeyframeError || 'Failed to apply: {error}';
            showToast(tpl.replace('{error}', (err && err.message) || ''), 'error');
            if (_apply.applyBtn) _apply.applyBtn.disabled = false;
        }
    }

    // ==================== Used-by + remove (Motion Slice 2b) ====================
    // The Apply flow has an inverse: from a keyframe, see which selectors
    // are using it and remove the `animation:` property from any of them.
    // The data is already in `animatedSelectorsData.animations`
    // (parsed[].name carries the keyframe name) — no extra fetch needed.

    function computeKeyframeUsersMap() {
        const map = {};
        const animations = (animatedSelectorsData && animatedSelectorsData.animations) || [];
        animations.forEach(item => {
            const sel = item.baseSelector || item.selector;
            if (!sel) return;
            const parsedArr = Array.isArray(item.parsed) ? item.parsed : [];
            parsedArr.forEach(p => {
                if (!p || !p.name) return;
                if (!map[p.name]) map[p.name] = [];
                // De-duplicate — a selector with two animations referencing
                // the same keyframe shouldn't appear twice in this list.
                if (map[p.name].indexOf(sel) === -1) map[p.name].push(sel);
            });
        });
        return map;
    }

    async function removeKeyframeFromSelector(keyframeName, selector) {
        const i18n = PreviewConfig.i18n || {};
        const confirmTpl = i18n.keyframeRemoveConfirm || 'Remove animation from {selector}?';
        if (!confirm(confirmTpl.replace('{selector}', selector))) return;

        try {
            // Empty styles + removeProperties:['animation'] tells setStyleRule
            // to strip the `animation` declaration while leaving every other
            // declaration on the rule untouched. The whole rule is removed
            // server-side only if the resulting declaration list is empty.
            const body = {
                selector: selector,
                styles: '',
                removeProperties: ['animation']
            };
            let ok = false;
            let errMsg = '';
            if (window.QuickSiteAPI && QuickSiteAPI.request) {
                const result = await QuickSiteAPI.request('setStyleRule', 'POST', body);
                ok = !!result.ok;
                errMsg = (result.data && (result.data.message || result.data.error)) || '';
            } else {
                const response = await fetch(managementUrl + 'setStyleRule', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(authToken ? { 'Authorization': 'Bearer ' + authToken } : {})
                    },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                ok = (data.status === 200 || data.status === 201);
                errMsg = data.message || '';
            }
            if (!ok) throw new Error(errMsg || 'setStyleRule failed');

            const tpl = i18n.keyframeRemoved || 'Animation removed from {selector}';
            showToast(tpl.replace('{selector}', selector), 'success');

            // Refresh: animatedSelectorsData feeds the users map; reload it
            // so the badge count drops + the row disappears.
            if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.reset) {
                PreviewSelectorBrowser.reset();
            }
            animationsLoaded = false;
            loadAnimationsTab();

            // Live preview: iframe re-fetches style.css.
            if (window.PreviewState && PreviewState.hotReloadCss) {
                PreviewState.hotReloadCss();
            }
        } catch (err) {
            const tpl = i18n.keyframeRemoveError || 'Failed to remove: {error}';
            showToast(tpl.replace('{error}', (err && err.message) || ''), 'error');
        }
    }

    // ==================== Transition Wizard (Motion Slice 4) ====================
    // "+ Add transition" → modal. Pick a selector, a property (preset chips
    // + free-text), duration, easing (via QSEasingPicker), delay. Submit
    // writes `transition: <prop> <dur>ms <ease> <delay>ms` via setStyleRule.
    // If the selected selector already has a transition, pre-fill from
    // animatedSelectorsData.transitions[].parsed[0] + show a "will overwrite"
    // hint. No new server fetch — same cache the rest of Motion tab uses.

    const _wizard = {
        modal:         null,
        backdrop:      null,
        closeBtn:      null,
        cancelBtn:     null,
        submitBtn:     null,
        selectorSearch: null,
        selectorList:  null,
        existingHint:  null,
        propertyChips: null,
        propertyInput: null,
        durationInput: null,
        delayInput:    null,
        easingBtn:     null,
        easingValueEl: null,
        previewEl:     null,
        // State
        selectedSelector: null,
        property:    'opacity',
        duration:    300,
        easing:      'ease',
        delay:       0,
        existingTransition: false,
        wired: false
    };

    function initTransitionWizard() {
        if (_wizard.wired) return;
        _wizard.modal          = document.getElementById('add-transition-modal');
        _wizard.backdrop       = document.getElementById('add-transition-modal-backdrop');
        _wizard.closeBtn       = document.getElementById('add-transition-modal-close');
        _wizard.cancelBtn      = document.getElementById('add-transition-modal-cancel');
        _wizard.submitBtn      = document.getElementById('add-transition-modal-submit');
        _wizard.selectorSearch = document.getElementById('add-transition-selector-search');
        _wizard.selectorList   = document.getElementById('add-transition-selector-list');
        _wizard.existingHint   = document.getElementById('add-transition-existing-hint');
        _wizard.propertyChips  = document.getElementById('add-transition-property-chips');
        _wizard.propertyInput  = document.getElementById('add-transition-property-input');
        _wizard.durationInput  = document.getElementById('add-transition-duration');
        _wizard.delayInput     = document.getElementById('add-transition-delay');
        _wizard.easingBtn      = document.getElementById('add-transition-easing-btn');
        _wizard.easingValueEl  = document.getElementById('add-transition-easing-value');
        _wizard.previewEl      = document.getElementById('add-transition-preview-value');
        if (!_wizard.modal) return;

        _wizard.closeBtn  && _wizard.closeBtn .addEventListener('click', closeTransitionWizard);
        _wizard.cancelBtn && _wizard.cancelBtn.addEventListener('click', closeTransitionWizard);
        _wizard.backdrop  && _wizard.backdrop .addEventListener('click', closeTransitionWizard);
        _wizard.submitBtn && _wizard.submitBtn.addEventListener('click', submitTransitionWizard);

        _wizard.selectorSearch && _wizard.selectorSearch.addEventListener('input', () => {
            renderWizardSelectorList(_wizard.selectorSearch.value);
        });

        // Property chips: clicking sets the active chip + property + clears input.
        _wizard.propertyChips && _wizard.propertyChips.querySelectorAll('[data-property]').forEach(chip => {
            chip.addEventListener('click', () => {
                _wizard.property = chip.dataset.property;
                if (_wizard.propertyInput) _wizard.propertyInput.value = '';
                refreshWizardChips();
                updateWizardPreview();
            });
        });
        // Free-text input: typing deselects chips + sets property.
        _wizard.propertyInput && _wizard.propertyInput.addEventListener('input', () => {
            _wizard.property = (_wizard.propertyInput.value || '').trim();
            refreshWizardChips();
            updateWizardPreview();
        });

        _wizard.durationInput && _wizard.durationInput.addEventListener('input', () => {
            const v = parseInt(_wizard.durationInput.value, 10);
            _wizard.duration = isFinite(v) ? Math.max(0, v) : 0;
            updateWizardPreview();
        });
        _wizard.delayInput && _wizard.delayInput.addEventListener('input', () => {
            const v = parseInt(_wizard.delayInput.value, 10);
            _wizard.delay = isFinite(v) ? Math.max(0, v) : 0;
            updateWizardPreview();
        });

        _wizard.easingBtn && _wizard.easingBtn.addEventListener('click', () => {
            if (!window.QSEasingPicker) return;
            QSEasingPicker.open({
                anchor: _wizard.easingBtn,
                value:  _wizard.easing,
                onConfirm: (newValue) => {
                    _wizard.easing = newValue;
                    if (_wizard.easingValueEl) _wizard.easingValueEl.textContent = newValue;
                    updateWizardPreview();
                }
            });
        });

        _wizard.wired = true;
    }

    async function openTransitionWizard() {
        initTransitionWizard();
        if (!_wizard.modal) return;
        // Reset form to defaults each open.
        _wizard.selectedSelector = null;
        _wizard.property = 'opacity';
        _wizard.duration = 300;
        _wizard.easing   = 'ease';
        _wizard.delay    = 0;
        _wizard.existingTransition = false;
        if (_wizard.selectorSearch) _wizard.selectorSearch.value = '';
        if (_wizard.propertyInput)  _wizard.propertyInput.value  = '';
        if (_wizard.durationInput)  _wizard.durationInput.value  = '300';
        if (_wizard.delayInput)     _wizard.delayInput.value     = '0';
        if (_wizard.easingValueEl)  _wizard.easingValueEl.textContent = 'ease';
        if (_wizard.existingHint)   _wizard.existingHint.hidden = true;
        if (_wizard.submitBtn)      _wizard.submitBtn.disabled = true;
        refreshWizardChips();
        updateWizardPreview();
        _wizard.modal.hidden = false;
        // Selector list shows "Loading…" until the cache is ready.
        if (_wizard.selectorList) {
            _wizard.selectorList.innerHTML = '';
            const loading = document.createElement('div');
            loading.className = 'add-transition-modal__empty';
            loading.textContent = (PreviewConfig.i18n && PreviewConfig.i18n.loading) || 'Loading…';
            _wizard.selectorList.appendChild(loading);
        }
        await ensureSelectorsLoaded();
        renderWizardSelectorList('');
        if (_wizard.selectorSearch) _wizard.selectorSearch.focus();
    }

    function closeTransitionWizard() {
        if (_wizard.modal) _wizard.modal.hidden = true;
    }

    function renderWizardSelectorList(filter) {
        if (!_wizard.selectorList) return;
        const i18n = PreviewConfig.i18n || {};
        let selectors = [];
        if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.getAllSelectors) {
            try { selectors = PreviewSelectorBrowser.getAllSelectors() || []; }
            catch (e) { selectors = []; }
        }
        if (!selectors.length) {
            _wizard.selectorList.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'add-transition-modal__empty';
            empty.textContent = i18n.applyKeyframeNoSelectors || 'No selectors yet — add one via the Selectors tab.';
            _wizard.selectorList.appendChild(empty);
            return;
        }
        const needle = String(filter || '').toLowerCase().trim();
        const matched = needle
            ? selectors.filter(s => (s.selector || s).toLowerCase().indexOf(needle) !== -1)
            : selectors.slice();

        _wizard.selectorList.innerHTML = '';
        if (!matched.length) {
            const empty = document.createElement('div');
            empty.className = 'add-transition-modal__empty';
            empty.textContent = i18n.applyKeyframeNoMatch || 'No selector matches.';
            _wizard.selectorList.appendChild(empty);
            return;
        }
        const frag = document.createDocumentFragment();
        matched.forEach(s => {
            const sel = s.selector || s;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'add-transition-modal__item';
            item.dataset.selector = sel;
            item.setAttribute('role', 'option');
            const code = document.createElement('code');
            code.textContent = sel;
            item.appendChild(code);
            if (s.mediaQuery) {
                const badge = document.createElement('span');
                badge.className = 'add-transition-modal__item-mq';
                badge.textContent = '@media ' + s.mediaQuery;
                item.appendChild(badge);
            }
            item.addEventListener('click', () => selectWizardSelector(sel, item));
            frag.appendChild(item);
        });
        _wizard.selectorList.appendChild(frag);
    }

    function selectWizardSelector(selector, itemEl) {
        _wizard.selectorList.querySelectorAll('.add-transition-modal__item--selected')
            .forEach(el => el.classList.remove('add-transition-modal__item--selected'));
        itemEl.classList.add('add-transition-modal__item--selected');
        _wizard.selectedSelector = selector;

        // Check if this selector already has a transition; pre-fill from it
        // if so, and surface the overwrite hint.
        const existing = findExistingTransitionFor(selector);
        if (existing) {
            _wizard.existingTransition = true;
            applyExistingTransition(existing);
            if (_wizard.existingHint) _wizard.existingHint.hidden = false;
        } else {
            _wizard.existingTransition = false;
            if (_wizard.existingHint) _wizard.existingHint.hidden = true;
        }

        if (_wizard.submitBtn) _wizard.submitBtn.disabled = false;
        updateWizardPreview();
    }

    function findExistingTransitionFor(selector) {
        const transitions = (animatedSelectorsData && animatedSelectorsData.transitions) || [];
        for (let i = 0; i < transitions.length; i++) {
            const item = transitions[i];
            const itemSel = item.baseSelector || item.selector;
            if (itemSel === selector && Array.isArray(item.parsed) && item.parsed.length) {
                return item.parsed[0]; // first transition entry
            }
        }
        return null;
    }

    function applyExistingTransition(parsed) {
        // parsed: { property, duration, timingFunction, delay } — durations
        // are CSS strings like "1s" or "300ms"; normalise to ms numbers.
        if (parsed.property) {
            _wizard.property = parsed.property;
            // Match a chip if possible, otherwise drop the value into the input.
            const knownChips = ['opacity', 'transform', 'color', 'background-color', 'border-color', 'box-shadow', 'all'];
            if (knownChips.indexOf(parsed.property) !== -1) {
                if (_wizard.propertyInput) _wizard.propertyInput.value = '';
            } else if (_wizard.propertyInput) {
                _wizard.propertyInput.value = parsed.property;
            }
            refreshWizardChips();
        }
        if (parsed.duration) {
            _wizard.duration = cssTimeToMs(parsed.duration);
            if (_wizard.durationInput) _wizard.durationInput.value = String(_wizard.duration);
        }
        if (parsed.timingFunction) {
            _wizard.easing = parsed.timingFunction;
            if (_wizard.easingValueEl) _wizard.easingValueEl.textContent = parsed.timingFunction;
        }
        if (parsed.delay) {
            _wizard.delay = cssTimeToMs(parsed.delay);
            if (_wizard.delayInput) _wizard.delayInput.value = String(_wizard.delay);
        }
    }

    // Converts CSS time tokens ('1s' / '300ms') to integer ms.
    function cssTimeToMs(t) {
        if (t == null) return 0;
        const s = String(t).trim();
        const m = s.match(/^([\d.]+)\s*(ms|s)?$/i);
        if (!m) return 0;
        const num = parseFloat(m[1]);
        if (!isFinite(num)) return 0;
        return ((m[2] || 'ms').toLowerCase() === 's') ? Math.round(num * 1000) : Math.round(num);
    }

    function refreshWizardChips() {
        if (!_wizard.propertyChips) return;
        const chips = _wizard.propertyChips.querySelectorAll('[data-property]');
        const inputVal = (_wizard.propertyInput && _wizard.propertyInput.value || '').trim();
        chips.forEach(chip => {
            // Active iff property matches AND input is empty (input wins when populated)
            const active = !inputVal && chip.dataset.property === _wizard.property;
            chip.classList.toggle('add-transition-modal__chip--active', active);
        });
    }

    function effectivePropertyName() {
        const inputVal = (_wizard.propertyInput && _wizard.propertyInput.value || '').trim();
        return inputVal || _wizard.property || '';
    }

    function updateWizardPreview() {
        if (!_wizard.previewEl) return;
        const prop = effectivePropertyName() || '<property>';
        const value = buildTransitionValue(prop);
        _wizard.previewEl.textContent = 'transition: ' + value + ';';
    }

    function buildTransitionValue(prop) {
        const parts = [prop, _wizard.duration + 'ms', _wizard.easing];
        if (_wizard.delay && _wizard.delay > 0) parts.push(_wizard.delay + 'ms');
        return parts.join(' ');
    }

    async function submitTransitionWizard() {
        const i18n = PreviewConfig.i18n || {};
        if (!_wizard.selectedSelector) {
            showToast(i18n.addTransitionSelectorRequired || 'Pick a selector first', 'warning');
            return;
        }
        const prop = effectivePropertyName();
        if (!prop) {
            showToast(i18n.addTransitionPropertyRequired || 'Property is required', 'warning');
            return;
        }
        const value = buildTransitionValue(prop);
        const selector = _wizard.selectedSelector;
        if (_wizard.submitBtn) _wizard.submitBtn.disabled = true;
        try {
            const body = {
                selector: selector,
                styles:   'transition: ' + value + ';'
            };
            let ok = false;
            let errMsg = '';
            if (window.QuickSiteAPI && QuickSiteAPI.request) {
                const result = await QuickSiteAPI.request('setStyleRule', 'POST', body);
                ok = !!result.ok;
                errMsg = (result.data && (result.data.message || result.data.error)) || '';
            } else {
                const response = await fetch(managementUrl + 'setStyleRule', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...(authToken ? { 'Authorization': 'Bearer ' + authToken } : {})
                    },
                    body: JSON.stringify(body)
                });
                const data = await response.json();
                ok = (data.status === 200 || data.status === 201);
                errMsg = data.message || '';
            }
            if (!ok) throw new Error(errMsg || 'setStyleRule failed');

            const tpl = i18n.addTransitionAdded || 'Transition added to {selector}';
            showToast(tpl.replace('{selector}', selector), 'success');
            closeTransitionWizard();

            // Refresh the data + iframe so the new transition surfaces in
            // the Transitions group and any visible elements pick it up.
            if (window.PreviewSelectorBrowser && PreviewSelectorBrowser.reset) {
                PreviewSelectorBrowser.reset();
            }
            animationsLoaded = false;
            loadAnimationsTab();
            if (window.PreviewState && PreviewState.hotReloadCss) {
                PreviewState.hotReloadCss();
            }
        } catch (err) {
            const tpl = i18n.addTransitionError || 'Failed to add transition: {error}';
            showToast(tpl.replace('{error}', (err && err.message) || ''), 'error');
            if (_wizard.submitBtn) _wizard.submitBtn.disabled = false;
        }
    }

    // ==================== Public API ====================

    window.PreviewStyleMotion = {
        init: init,
        load: loadAnimationsTab,
        isLoaded: function() { return animationsLoaded; },
        reset: function() {
            animationsLoaded = false;
            keyframesData = [];
            animatedSelectorsData = { transitions: [], animations: [], triggersWithoutTransition: [] };
        },
        getKeyframesData: function() { return keyframesData; },
        getAnimatedSelectorsData: function() { return animatedSelectorsData; },
        openAnimationPreviewModal: previewKeyframe
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
