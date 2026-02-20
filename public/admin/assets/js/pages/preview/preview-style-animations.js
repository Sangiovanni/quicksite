/**
 * Preview Style Animations Module
 * Handles the Animations tab in Style mode (keyframes, animated selectors)
 * 
 * Dependencies:
 * - PreviewConfig global (from preview-config.php)
 * - PreviewState global (from preview-state.js)
 * 
 * @version 1.0.0
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
            
            // Process keyframes
            if (keyframesData_response.status === 200 && keyframesData_response.data?.keyframes) {
                keyframesData = keyframesData_response.data.keyframes;
                populateKeyframesList();
            } else {
                keyframesData = [];
                populateKeyframesList();
            }
            
            // Process animated selectors
            if (selectorsData.status === 200 && selectorsData.data) {
                animatedSelectorsData = {
                    transitions: selectorsData.data.transitions || [],
                    animations: selectorsData.data.animations || [],
                    triggersWithoutTransition: selectorsData.data.triggersWithoutTransition || []
                };
                populateAnimatedSelectorsList();
            } else {
                animatedSelectorsData = { transitions: [], animations: [], triggersWithoutTransition: [] };
                populateAnimatedSelectorsList();
            }
            
            animationsLoaded = true;
            
            // Show content, hide loading
            animationsLoading.style.display = 'none';
            animationsContent.style.display = '';
            
        } catch (error) {
            console.error('Failed to load animations:', error);
            animationsLoading.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
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
        
        keyframesList.innerHTML = keyframesData.map(kf => `
            <div class="preview-keyframe-item" data-keyframe="${escapeHtml(kf.name)}">
                <div class="preview-keyframe-item__info">
                    <span class="preview-keyframe-item__name">@keyframes ${escapeHtml(kf.name)}</span>
                    <span class="preview-keyframe-item__frames">${kf.frameCount} ${kf.frameCount === 1 ? PreviewConfig.i18n.frame : PreviewConfig.i18n.frames}</span>
                </div>
                <div class="preview-keyframe-item__actions">
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--preview" 
                            data-action="preview" title="${PreviewConfig.i18n.previewAnimation}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--edit" 
                            data-action="edit" title="${PreviewConfig.i18n.edit}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </button>
                    <button type="button" class="preview-keyframe-item__btn preview-keyframe-item__btn--delete" 
                            data-action="delete" title="${PreviewConfig.i18n.delete}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');
        
        // Add event listeners
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
                    case 'edit':
                        editKeyframe(keyframeName);
                        break;
                    case 'delete':
                        deleteKeyframe(keyframeName);
                        break;
                }
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
            
            if (transitions.length === 0) {
                transitionsList.innerHTML = `<p class="preview-animated-empty">${PreviewConfig.i18n.noTransitions}</p>`;
            } else {
                transitionsList.innerHTML = transitions.map(item => 
                    renderAnimatedSelectorGroup(item, 'transition')
                ).join('');
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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <span>${PreviewConfig.i18n.mayBeTriggeredVia}: ${relatedList}</span>
                </div>
            `;
        } else if (item.isOrphan) {
            orphanHintHtml = `
                <div class="preview-animated-selector__orphan-hint preview-animated-selector__orphan-hint--warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
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
                        <svg class="preview-animated-selector__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>
                        </svg>
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
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <code class="preview-animated-selector__name">${escapeHtml(item.baseSelector)}</code>
                    <span class="preview-animated-selector__details">${detailsHtml}</span>
                    <span class="preview-animated-selector__state-count">${item.states.length} ${PreviewConfig.i18n.states}</span>
                    <svg class="preview-animated-selector__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                        <path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>
                    </svg>
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
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
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
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
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
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
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
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
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

    // ==================== Public API ====================
    
    window.PreviewStyleAnimations = {
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
