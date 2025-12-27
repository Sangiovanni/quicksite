/**
 * QuickSite Tutorial System
 * Floating guide with focus block overlay
 */

class QuickSiteTutorial {
    constructor() {
        this.isActive = false;
        this.isInitialized = false;
        this.currentStep = 1;
        this.currentSubstep = 1;
        this.status = 'pending';
        this.steps = {};
        this.stepInfo = null;
        this.overlayElement = null;
        this.bubbleElement = null;
        this.focusRingElement = null;
        this.minimizedElement = null;
        this.isMinimized = false;
        this.targetElement = null;
        this.externalLinkClicked = false;  // Flag to track if user clicked external link
        
        // Drag state
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };
        this.userDragPosition = null; // User's custom position from dragging
        
        // Translations (will be set from PHP)
        this.translations = window.TUTORIAL_TRANSLATIONS || {};
    }
    
    /**
     * Initialize from server data
     */
    init(data) {
        if (!data) return;
        
        // Prevent double initialization
        if (this.isInitialized) {
            console.log('Tutorial: Already initialized, skipping');
            return;
        }
        this.isInitialized = true;
        
        this.status = data.status || 'pending';
        this.currentStep = data.currentStep || 1;
        this.currentSubstep = data.currentSubstep || 1;
        this.steps = data.steps || {};
        this.stepInfo = data.stepInfo;
        this.suggestedRoute = data.suggestedRoute || 'test-quicksite';
        
        console.log(`Tutorial: Initialized with server progress: step ${this.currentStep}, substep ${this.currentSubstep}, status: ${this.status}`);
        
        // Check if localStorage has more recent progress (for navigation scenarios)
        const localProgress = this.getLocalProgress();
        if (localProgress && localProgress.step && localProgress.substep) {
            // Use multiplier of 100 to handle steps with many substeps (e.g., step 5 has 17, step 6 has 36)
            const localTotal = (localProgress.step - 1) * 100 + localProgress.substep;
            const serverTotal = (this.currentStep - 1) * 100 + this.currentSubstep;
            
            console.log(`Tutorial: localStorage progress: step ${localProgress.step}, substep ${localProgress.substep}, status: ${localProgress.status}`);
            
            if (localTotal > serverTotal && localProgress.status === 'active') {
                console.log('Tutorial: Using localStorage progress (ahead of server)', localProgress);
                this.currentStep = localProgress.step;
                this.currentSubstep = localProgress.substep;
                this.status = localProgress.status;
                this.saveProgress();
            }
        }
        
        // Create base elements (but keep them hidden)
        this.createOverlay();
        this.createBubble();
        this.createMinimizedWidget();
        
        // IMPORTANT: Hide everything initially
        this.hideAll();
        
        // Determine what to show based on status
        // Status meanings:
        // - 'pending': Never started or legacy state → check if first time
        // - 'active': Currently doing tutorial → show current step immediately
        // - 'paused': User clicked "Continue Later" → show minimized widget (not modal)
        // - 'skipped': User skipped tutorial → show minimized widget
        // - 'completed': Tutorial finished → show minimized widget
        
        if (this.status === 'active') {
            // Active tutorial - show current step immediately
            this.isActive = true;
            this.showCurrentStep();
        } else if (this.status === 'pending') {
            // Check if this is truly first time (step/substep null or 1/1 AND never started before)
            const isFirstTime = (data.currentStep === null || (data.currentStep === 1 && data.currentSubstep === 1)) 
                                && !this.hasStartedBefore();
            
            if (isFirstTime) {
                // First time - show welcome
                this.showWelcome();
            } else {
                // Has some progress or started before - show resume modal
                this.showResume();
            }
        } else if (this.status === 'paused') {
            // Paused - show minimized widget (user can click to open resume modal)
            this.showMinimized();
        } else if (this.status === 'skipped') {
            // Show minimized widget for restart option
            this.showMinimized();
        } else if (this.status === 'completed') {
            // Tutorial completed - don't show anything, user is done!
            // They can restart from Settings page if needed
        }
    }
    
    /**
     * Get progress stored in localStorage
     */
    getLocalProgress() {
        const stored = localStorage.getItem('quicksite_tutorial_progress');
        if (stored) {
            try {
                return JSON.parse(stored);
            } catch (e) {
                return null;
            }
        }
        return null;
    }
    
    /**
     * Save progress to localStorage (backup for beacon)
     */
    saveLocalProgress() {
        localStorage.setItem('quicksite_tutorial_progress', JSON.stringify({
            step: this.currentStep,
            substep: this.currentSubstep,
            status: this.status,
            timestamp: Date.now()
        }));
    }
    
    /**
     * Sync pending localStorage progress to server
     */
    async syncFromLocalStorage() {
        const localProgress = this.getLocalProgress();
        if (localProgress && localProgress.timestamp) {
            // If recent (within last minute), try to sync
            if (Date.now() - localProgress.timestamp < 60000) {
                console.log('Tutorial: Syncing localStorage progress to server');
                // This will be handled after we get server data
            }
        }
    }
    
    /**
     * Check if user has started tutorial before (using localStorage as backup)
     */
    hasStartedBefore() {
        return localStorage.getItem('quicksite_tutorial_started') === 'true';
    }
    
    /**
     * Mark tutorial as started
     */
    markAsStarted() {
        localStorage.setItem('quicksite_tutorial_started', 'true');
    }
    
    /**
     * Hide all tutorial UI elements
     */
    hideAll() {
        if (this.overlayElement) {
            this.overlayElement.classList.remove('active');
            this.overlayElement.style.display = 'none';
        }
        if (this.focusRingElement) {
            this.focusRingElement.style.display = 'none';
        }
        if (this.bubbleElement) {
            this.bubbleElement.style.display = 'none';
        }
        if (this.minimizedElement) {
            this.minimizedElement.style.display = 'none';
        }
    }
    
    /**
     * Create the dark overlay with cutout
     */
    createOverlay() {
        if (this.overlayElement) return;
        
        this.overlayElement = document.createElement('div');
        this.overlayElement.className = 'tutorial-overlay';
        this.overlayElement.innerHTML = `
            <div class="tutorial-overlay-bg"></div>
        `;
        
        this.focusRingElement = document.createElement('div');
        this.focusRingElement.className = 'tutorial-focus-ring';
        this.overlayElement.appendChild(this.focusRingElement);
        
        // Click outside to see instruction
        this.overlayElement.querySelector('.tutorial-overlay-bg').addEventListener('click', (e) => {
            // Shake the bubble to draw attention
            this.shakeBubble();
        });
        
        // Global listener for command card clicks during search steps
        // If user clicks a command card during a search step, save progress for next step
        // and let the navigation happen naturally
        document.addEventListener('click', (e) => {
            if (!this.isActive) return;
            
            const step = this.steps[this.currentStep];
            const substep = step?.substeps?.[this.currentSubstep];
            
            // Only for search steps
            if (!this.isSearchStep(substep)) return;
            
            // Check if clicked on a command card link
            const commandCard = e.target.closest('[data-command]');
            if (commandCard) {
                console.log('Tutorial: Command card clicked during search step, saving progress for next substep');
                
                // Calculate next substep
                let nextStep = this.currentStep;
                let nextSubstep = this.currentSubstep + 1;
                const totalSubsteps = Object.keys(step.substeps).length;
                
                if (nextSubstep > totalSubsteps) {
                    nextStep++;
                    nextSubstep = 1;
                }
                
                // Update internal state
                this.currentStep = nextStep;
                this.currentSubstep = nextSubstep;
                
                // Save using beacon (reliable during page navigation)
                this.saveProgressBeacon();
                
                // Let the command card link navigate naturally - don't prevent default
            }
        }, true); // Capture phase to save before navigation
        
        document.body.appendChild(this.overlayElement);
    }
    
    /**
     * Create the guide bubble
     */
    createBubble() {
        if (this.bubbleElement) return;
        
        const totalSteps = Object.keys(this.steps).length;
        
        this.bubbleElement = document.createElement('div');
        this.bubbleElement.className = 'tutorial-bubble arrow-top';
        this.bubbleElement.innerHTML = `
            <div class="tutorial-bubble-header" style="cursor: grab;">
                <h4>
                    <span class="step-badge">Step <span class="step-num">1</span>/${totalSteps}</span>
                    <span class="step-title">Create Your Website</span>
                </h4>
                <button class="tutorial-close-btn" onclick="tutorial.minimize()" title="Minimize">
                    <i class="bi bi-dash-lg"></i>
                </button>
            </div>
            <div class="tutorial-bubble-content">
                <div class="substep-title">
                    <span class="icon">👆</span>
                    <span class="title-text">Click "Create Website"</span>
                </div>
                <p class="substep-description">Start by clicking the Create Website button to begin your journey.</p>
            </div>
            <div class="tutorial-progress"></div>
            <div class="tutorial-bubble-actions">
                <button class="tutorial-btn tutorial-btn-skip" onclick="tutorial.skip()">Skip tutorial</button>
                <button class="tutorial-btn tutorial-btn-secondary" onclick="tutorial.previousSubstep()">
                    <i class="bi bi-arrow-left"></i>
                </button>
                <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.nextSubstep()">
                    Next <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(this.bubbleElement);
        
        // Add drag functionality
        this.initDraggable();
    }
    
    /**
     * Initialize drag functionality for the bubble
     */
    initDraggable() {
        const header = this.bubbleElement.querySelector('.tutorial-bubble-header');
        if (!header) return;
        
        // Double-click to reset position
        header.addEventListener('dblclick', (e) => {
            if (e.target.closest('button')) return;
            this.userDragPosition = null;
            this.positionBubble();
        });
        
        header.addEventListener('mousedown', (e) => {
            // Don't drag if clicking on buttons
            if (e.target.closest('button')) return;
            
            this.isDragging = true;
            header.style.cursor = 'grabbing';
            
            const rect = this.bubbleElement.getBoundingClientRect();
            this.dragOffset = {
                x: e.clientX - rect.left,
                y: e.clientY - rect.top
            };
            
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!this.isDragging) return;
            
            const x = e.clientX - this.dragOffset.x;
            const y = e.clientY - this.dragOffset.y;
            
            // Keep within viewport
            const maxX = window.innerWidth - this.bubbleElement.offsetWidth - 10;
            const maxY = window.innerHeight - this.bubbleElement.offsetHeight - 10;
            
            const finalX = Math.max(10, Math.min(x, maxX));
            const finalY = Math.max(10, Math.min(y, maxY));
            
            // Apply position
            this.bubbleElement.style.left = finalX + 'px';
            this.bubbleElement.style.top = finalY + 'px';
            this.bubbleElement.style.right = 'auto';
            this.bubbleElement.style.bottom = 'auto';
            this.bubbleElement.style.transform = 'none';
            
            // Remove arrow classes when dragging
            this.bubbleElement.className = this.bubbleElement.className.replace(/arrow-\w+/g, '').trim();
            
            // Store user position
            this.userDragPosition = { x: finalX, y: finalY };
        });
        
        document.addEventListener('mouseup', () => {
            if (this.isDragging) {
                this.isDragging = false;
                header.style.cursor = 'grab';
            }
        });
    }
    
    /**
     * Create minimized widget
     */
    createMinimizedWidget() {
        if (this.minimizedElement) return;
        
        this.minimizedElement = document.createElement('div');
        this.minimizedElement.className = 'tutorial-mini-guide';
        this.minimizedElement.style.display = 'none';
        this.minimizedElement.innerHTML = `
            <button class="tutorial-mini-btn" onclick="tutorial.restore()" title="Continue tutorial">
                <svg class="progress-ring" viewBox="0 0 62 62">
                    <circle class="bg" cx="31" cy="31" r="28"></circle>
                    <circle class="progress" cx="31" cy="31" r="28" 
                        stroke-dasharray="176" stroke-dashoffset="176"></circle>
                </svg>
                <i class="bi bi-lightbulb"></i>
            </button>
        `;
        
        document.body.appendChild(this.minimizedElement);
    }
    
    /**
     * Show welcome modal for first time users
     */
    showWelcome() {
        const t = this.translations;
        
        const modal = document.createElement('div');
        modal.className = 'tutorial-welcome-modal';
        modal.id = 'tutorial-welcome';
        modal.innerHTML = `
            <div class="tutorial-welcome-card">
                <div class="tutorial-welcome-header">
                    <div class="icon">🚀</div>
                    <h2>${t.welcomeTitle || 'Welcome to QuickSite!'}</h2>
                    <p>${t.welcomeSubtitle || 'Let\'s create your first website together'}</p>
                </div>
                <div class="tutorial-welcome-body">
                    <h3>${t.whatYouLearn || 'In this tutorial, you\'ll learn:'}</h3>
                    <ul class="tutorial-steps-preview">
                        ${this.renderStepsList(0)}
                    </ul>
                    <div class="tutorial-warning">
                        <span class="icon">⚠️</span>
                        ${t.freshStartWarning || 'The tutorial will start with a fresh example. Any existing website structure will be reset.'}
                    </div>
                    <div class="tutorial-welcome-actions">
                        <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.startTutorial()">
                            ${t.startTutorial || 'Start Tutorial'} <i class="bi bi-arrow-right"></i>
                        </button>
                        <button class="tutorial-btn tutorial-btn-secondary" onclick="tutorial.skipFromWelcome()">
                            ${t.skipForNow || 'Skip for now'}
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }
    
    /**
     * Show resume prompt
     */
    showResume() {
        const t = this.translations;
        const step = this.steps[this.currentStep];
        const stepTitle = step ? step.title : '';
        const completedSteps = this.currentStep - 1;
        
        const modal = document.createElement('div');
        modal.className = 'tutorial-welcome-modal';
        modal.id = 'tutorial-resume';
        modal.innerHTML = `
            <div class="tutorial-welcome-card">
                <div class="tutorial-welcome-header">
                    <div class="icon">📚</div>
                    <h2>${t.welcomeBack || 'Welcome Back!'}</h2>
                    <p>${t.resumeSubtitle || 'Continue where you left off'}</p>
                </div>
                <div class="tutorial-welcome-body">
                    <h3>${t.yourProgress || 'Your Progress'}</h3>
                    <ul class="tutorial-steps-preview">
                        ${this.renderStepsList(completedSteps)}
                    </ul>
                    <div class="tutorial-welcome-actions">
                        <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.resumeTutorial()">
                            ${t.continueTutorial || 'Continue Tutorial'} <i class="bi bi-arrow-right"></i>
                        </button>
                        <button class="tutorial-btn tutorial-btn-secondary" onclick="tutorial.pauseTutorial()">
                            ${t.continueLater || 'Continue Later'}
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }
    
    /**
     * Render progress dots
     */
    renderProgressDots() {
        let html = '';
        for (let i = 1; i <= 6; i++) {
            let cls = 'tutorial-progress-dot';
            if (i < this.currentStep) cls += ' completed';
            if (i === this.currentStep) cls += ' current';
            html += `<span class="${cls}"></span>`;
        }
        return html;
    }
    
    /**
     * Render step list with completion status
     */
    renderStepsList(completedUpTo = 0) {
        const t = this.translations;
        // Step titles with substep counts for user expectation
        const stepInfo = [
            { title: t.step1Preview || 'Create a website with AI', substeps: 9 },
            { title: t.step2Preview || 'Batch Basics', substeps: 9 },
            { title: t.step3Preview || 'Understanding Commands', substeps: 7 },
            { title: t.step4Preview || 'Understanding Structure', substeps: 11 },
            { title: t.step5Preview || 'Assets Management', substeps: 19 },
            { title: t.step6Preview || 'Change Favicon', substeps: 5 },
            { title: t.step7Preview || 'Edit Structure', substeps: 19 },
            { title: t.step8Preview || 'CSS Styling', substeps: 9 },
            { title: t.step9Preview || 'Theme Customization', substeps: 11 },
            { title: t.step10Preview || 'Add Languages', substeps: 10 },
            { title: t.step11Preview || 'Manage Translations', substeps: 11 }
        ];
        
        const totalSteps = stepInfo.length;
        let html = '';
        for (let i = 1; i <= totalSteps; i++) {
            const info = stepInfo[i-1];
            const isCompleted = i <= completedUpTo;
            const isCurrent = i === completedUpTo + 1;
            let cls = isCompleted ? 'completed' : '';
            if (isCurrent) cls += ' current';
            
            // Show substep count for non-completed steps (if > 0)
            const substepHint = (!isCompleted && info.substeps > 0) ? ` <small style="opacity:0.6">(${info.substeps})</small>` : '';
            
            html += `<li class="${cls}">`;
            if (isCompleted) {
                html += `<span class="step-num completed"><i class="bi bi-check"></i></span>`;
            } else {
                html += `<span class="step-num">${i}</span>`;
            }
            html += `<span class="step-text">${info.title}${substepHint}</span></li>`;
        }
        return html;
    }
    
    /**
     * Show step complete modal
     */
    showStepComplete(completedStepNum) {
        const t = this.translations;
        const completedStep = this.steps[completedStepNum];
        const nextStep = this.steps[completedStepNum + 1];
        
        const modal = document.createElement('div');
        modal.className = 'tutorial-welcome-modal';
        modal.id = 'tutorial-step-complete';
        modal.innerHTML = `
            <div class="tutorial-welcome-card">
                <div class="tutorial-welcome-header">
                    <div class="icon">🎉</div>
                    <h2>${t.stepComplete || 'Step Complete!'}</h2>
                    <p>${completedStep?.title || 'Step ' + completedStepNum} ${t.completed || 'completed successfully'}</p>
                </div>
                <div class="tutorial-welcome-body">
                    <h3>${t.yourProgress || 'Your Progress'}</h3>
                    <ul class="tutorial-steps-preview">
                        ${this.renderStepsList(completedStepNum)}
                    </ul>
                    ${nextStep ? `
                    <div class="tutorial-next-step-preview">
                        <strong>${t.nextUp || 'Next up'}:</strong> ${nextStep.title}
                    </div>
                    ` : ''}
                    <div class="tutorial-welcome-actions">
                        <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.continueAfterStepComplete()">
                            ${t.continueTutorial || 'Continue Tutorial'} <i class="bi bi-arrow-right"></i>
                        </button>
                        <button class="tutorial-btn tutorial-btn-secondary" onclick="tutorial.pauseTutorial()">
                            ${t.continueLater || 'Continue Later'}
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }
    
    /**
     * Continue after step complete modal
     */
    async continueAfterStepComplete() {
        const modal = document.getElementById('tutorial-step-complete');
        if (modal) modal.remove();
        
        this.isActive = true;
        this.status = 'active';
        await this.saveProgress();
        this.showCurrentStep();
    }
    
    /**
     * Pause tutorial (Continue Later)
     */
    async pauseTutorial() {
        const modal = document.getElementById('tutorial-step-complete');
        const welcome = document.getElementById('tutorial-welcome');
        const resume = document.getElementById('tutorial-resume');
        if (modal) modal.remove();
        if (welcome) welcome.remove();
        if (resume) resume.remove();
        
        this.status = 'paused';
        this.isActive = false;
        await this.saveProgress();
        
        // Show minimized widget
        this.showMinimized();
    }

    /**
     * Start the tutorial
     */
    async startTutorial() {
        // Close welcome modal
        const welcome = document.getElementById('tutorial-welcome');
        if (welcome) welcome.remove();
        
        // Clear any stale localStorage progress to ensure fresh start
        localStorage.removeItem('quicksite_tutorial_progress');
        
        // Mark as started in localStorage FIRST
        this.markAsStarted();
        
        // Initialize first step
        this.currentStep = 1;
        this.currentSubstep = 1;
        this.status = 'active';
        this.isActive = true;
        
        // Save progress FIRST (so any page refresh won't show welcome again)
        await this.saveProgress();
        
        // Save to localStorage too as backup
        this.saveLocalProgress();
        
        // Apply fresh start (reset structure) - this makes API calls but doesn't refresh
        await this.applyFreshStart();
        
        // Now show the tutorial UI (only if we haven't been navigated away)
        if (this.isActive) {
            this.showCurrentStep();
        }
    }
    
    /**
     * Resume tutorial
     */
    async resumeTutorial() {
        const modal = document.getElementById('tutorial-resume');
        if (modal) modal.remove();
        
        this.isActive = true;
        this.status = 'active';
        await this.saveProgress();
        this.showCurrentStep();
    }
    
    /**
     * Restart tutorial from beginning
     */
    async restartTutorial() {
        const modal = document.getElementById('tutorial-resume');
        if (modal) modal.remove();
        
        // Clear all tutorial localStorage data
        localStorage.removeItem('quicksite_tutorial_started');
        localStorage.removeItem('quicksite_tutorial_progress');
        
        await this.applyFreshStart();
        
        this.currentStep = 1;
        this.currentSubstep = 1;
        this.status = 'pending';
        this.isActive = true;
        
        await this.saveProgress();
        this.showCurrentStep();
    }
    
    /**
     * Skip from welcome/resume modal
     */
    async skipFromWelcome() {
        const welcome = document.getElementById('tutorial-welcome');
        const resume = document.getElementById('tutorial-resume');
        if (welcome) welcome.remove();
        if (resume) resume.remove();
        
        await this.skip();
    }
    
    /**
     * Execute API call helper (same pattern as batch page)
     */
    async executeApiCall(command, params = {}) {
        try {
            const response = await fetch(`${window.QUICKSITE_CONFIG.apiUrl}/${command}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${window.QUICKSITE_CONFIG.token}`
                },
                body: JSON.stringify(params)
            });
            const data = await response.json();
            return { ok: response.ok && data.status >= 200 && data.status < 300, data: data.data || data };
        } catch (error) {
            console.error(`Tutorial API call ${command} error:`, error);
            return { ok: false, error: error.message };
        }
    }
    
    /**
     * Apply fresh start (same as Batch > Fresh Start template)
     * Dynamically generates and executes commands to reset the project
     */
    async applyFreshStart() {
        console.log('Tutorial: Applying Fresh Start...');
        
        try {
            const commands = [];
            
            // 1. Fetch and delete all routes except 404 and home
            const routesResponse = await this.executeApiCall('getRoutes', {});
            if (routesResponse.ok && routesResponse.data?.routes) {
                const protectedRoutes = ['404', 'home'];
                for (const routeName of routesResponse.data.routes) {
                    if (!protectedRoutes.includes(routeName)) {
                        commands.push({ command: 'deleteRoute', params: { route: routeName } });
                    }
                }
            }
            
            // 2. Fetch and delete all assets
            const assetsResponse = await this.executeApiCall('listAssets', {});
            if (assetsResponse.ok && assetsResponse.data?.assets) {
                for (const [category, files] of Object.entries(assetsResponse.data.assets)) {
                    for (const file of files) {
                        commands.push({ 
                            command: 'deleteAsset', 
                            params: { category: category, filename: file.filename } 
                        });
                    }
                }
            }
            
            // 3. Fetch and delete all components
            const componentsResponse = await this.executeApiCall('listComponents', {});
            if (componentsResponse.ok && componentsResponse.data?.components) {
                for (const component of componentsResponse.data.components) {
                    commands.push({ 
                        command: 'editStructure', 
                        params: { type: 'component', name: component.name, structure: [] }
                    });
                }
            }
            
            // 4. Clear translation keys (except 404)
            const translationsResponse = await this.executeApiCall('getTranslations', {});
            if (translationsResponse.ok && translationsResponse.data?.translations) {
                for (const [lang, keys] of Object.entries(translationsResponse.data.translations)) {
                    const topLevelKeys = Object.keys(keys).filter(key => key !== '404');
                    if (topLevelKeys.length > 0) {
                        commands.push({ 
                            command: 'deleteTranslationKeys', 
                            params: { language: lang, keys: topLevelKeys } 
                        });
                    }
                }
            }
            
            // 5. Clear menu and footer structures
            commands.push({ command: 'editStructure', params: { type: 'menu', structure: [] } });
            commands.push({ command: 'editStructure', params: { type: 'footer', structure: [] } });
            
            // 6. Empty home page structure
            commands.push({ command: 'editStructure', params: { type: 'page', name: 'home', structure: [] } });
            
            // 7. Minimize 404 page structure
            commands.push({ 
                command: 'editStructure', 
                params: { 
                    type: 'page',
                    name: '404', 
                    structure: [
                        { tag: 'section', params: { class: 'error-page' }, children: [
                            { tag: 'h1', children: [{ textKey: '404.pageNotFound' }] },
                            { tag: 'p', children: [{ textKey: '404.message' }] }
                        ]}
                    ]
                } 
            });
            
            // 8. Clear CSS
            commands.push({ command: 'editStyles', params: { content: '/* Fresh Start - CSS cleared */\n' } });
            
            console.log(`Tutorial: Executing ${commands.length} Fresh Start commands...`);
            
            // Execute all commands
            let successCount = 0;
            let failCount = 0;
            
            for (const cmd of commands) {
                const result = await this.executeApiCall(cmd.command, cmd.params);
                if (result.ok) {
                    successCount++;
                } else {
                    failCount++;
                    console.warn(`Tutorial: Fresh Start command failed: ${cmd.command}`, result);
                }
            }
            
            console.log(`Tutorial: Fresh Start complete. ${successCount} succeeded, ${failCount} failed.`);
            
        } catch (error) {
            console.error('Tutorial: Fresh Start error:', error);
        }
    }
    
    /**
     * Get example structure for tutorial (kept for reference)
     */
    getExampleStructure() {
        return {
            "home": {
                "route": "/",
                "title": "Welcome to My Site",
                "meta": {
                    "description": "A beautiful website created with QuickSite"
                },
                "sections": [
                    {
                        "type": "hero",
                        "title": "Welcome",
                        "subtitle": "This is my awesome website",
                        "cta": {"text": "Learn More", "link": "#about"}
                    },
                    {
                        "type": "features",
                        "title": "What We Offer",
                        "items": [
                            {"icon": "star", "title": "Quality", "description": "Top notch quality"},
                            {"icon": "heart", "title": "Passion", "description": "Made with love"},
                            {"icon": "zap", "title": "Speed", "description": "Lightning fast"}
                        ]
                    }
                ]
            },
            "contact": {
                "route": "/contact",
                "title": "Contact Us",
                "sections": [
                    {
                        "type": "contact-form",
                        "title": "Get in Touch",
                        "fields": ["name", "email", "message"]
                    }
                ]
            }
        };
    }
    
    /**
     * Show current step
     */
    showCurrentStep() {
        const step = this.steps[this.currentStep];
        if (!step) return;
        
        const substep = step.substeps[this.currentSubstep];
        if (!substep) return;
        
        // Check if we need to navigate to a different page
        this.ensureCorrectPage();
        
        // Prepare page for current step (expand collapsed sections, etc.)
        this.preparePageForStep(substep);
        
        // Update bubble content
        this.updateBubble(step, substep);
        
        // Show overlay and highlight target
        if (substep.focus) {
            this.highlightElement(substep.focus);
            this.showOverlay();
        } else {
            // No focus - reset target element so bubble positions correctly
            this.targetElement = null;
            this.hideOverlay();
        }
        
        // Position bubble near target
        this.positionBubble();
        
        // Show bubble
        this.bubbleElement.style.display = 'block';
        this.minimizedElement.style.display = 'none';
        this.isMinimized = false;
    }
    
    /**
     * Prepare page for current step - expand collapsed sections, etc.
     */
    preparePageForStep(substep) {
        // For batch page template substeps, ensure templates section is expanded
        const batchTemplateSubsteps = [
            'select_fresh_start', 'generate_fresh_start',
            'select_starter_multilingual', 'load_starter',
            'select_starter_again', 'preview_template', 'load_to_queue'
        ];
        
        if (batchTemplateSubsteps.includes(substep.id)) {
            const templatesBody = document.getElementById('templates-body');
            const templatesToggle = document.getElementById('templates-toggle');
            
            if (templatesBody && templatesBody.style.display === 'none') {
                templatesBody.style.display = 'block';
                // Rotate the toggle arrow
                if (templatesToggle) {
                    templatesToggle.style.transform = 'rotate(180deg)';
                }
            }
        }
    }
    
    /**
     * Update bubble content
     */
    updateBubble(step, substep) {
        const t = this.translations;
        
        // Update header
        this.bubbleElement.querySelector('.step-num').textContent = this.currentStep;
        this.bubbleElement.querySelector('.step-title').textContent = step.title;
        
        // Update content
        const content = this.bubbleElement.querySelector('.tutorial-bubble-content');
        content.innerHTML = `
            <div class="substep-title">
                <span class="icon">${this.getSubstepIcon()}</span>
                <span class="title-text">${substep.title}</span>
            </div>
            <p class="substep-description">${this.getSubstepDescription(substep)}</p>
        `;
        
        // Update progress dots - make them clickable to jump to substep
        const totalSubsteps = Object.keys(step.substeps).length;
        const progressHtml = this.renderSubstepProgress(totalSubsteps);
        this.bubbleElement.querySelector('.tutorial-progress').innerHTML = progressHtml;
        
        // Update Previous button visibility
        const prevBtn = this.bubbleElement.querySelector('.tutorial-btn-secondary');
        prevBtn.style.display = (this.currentStep === 1 && this.currentSubstep === 1) ? 'none' : 'inline-flex';
        
        // Check if this is the last substep of the current step
        const isLastSubstep = this.currentSubstep === totalSubsteps;
        
        // Update Next/Finish button
        const nextBtn = this.bubbleElement.querySelector('.tutorial-btn-primary');
        const requiresClick = this.substepRequiresClick(substep);
        const isManual = this.isManualStep(substep);
        const isSearch = this.isSearchStep(substep);
        
        // Check if this is an external link substep (opens in new tab)
        const isExternalLinkSubstep = ['view_site', 'view_blank_site', 'view_starter_site'].includes(substep.id);
        
        if (isLastSubstep && isExternalLinkSubstep) {
            // Last substep with external link - hide button until they click the link
            const finishText = t.finishStep || 'Finish Step';
            nextBtn.innerHTML = `${finishText} ${this.currentStep} <i class="bi bi-check-lg"></i>`;
            nextBtn.style.display = 'none'; // Hidden until they click Back to Site
        } else if (isLastSubstep) {
            // Last substep (not external link) - show "Finish Step X" button
            const finishText = t.finishStep || 'Finish Step';
            nextBtn.innerHTML = `${finishText} ${this.currentStep} <i class="bi bi-check-lg"></i>`;
            nextBtn.style.display = 'inline-flex';
        } else if (isManual || isSearch) {
            // Manual steps and search steps - show Next button, user clicks when ready
            nextBtn.innerHTML = `${t.next || 'Next'} <i class="bi bi-arrow-right"></i>`;
            nextBtn.style.display = 'inline-flex';
        } else if (requiresClick) {
            // Mid-step that requires click - hide Next button
            nextBtn.innerHTML = `${t.next || 'Next'} <i class="bi bi-arrow-right"></i>`;
            nextBtn.style.display = 'none';
        } else {
            // Regular substep - show Next button
            nextBtn.innerHTML = `${t.next || 'Next'} <i class="bi bi-arrow-right"></i>`;
            nextBtn.style.display = 'inline-flex';
        }
    }
    
    /**
     * Check if substep requires user to click on target (vs just filling input)
     */
    substepRequiresClick(substep) {
        // These substep IDs require user to click on something specific
        // Note: "select_*" steps are observation only (show Next button)
        const clickRequired = [
            // Step 1: AI Integration
            'click_create',    // Click AI Integration link
            'select_spec',     // Click Create Website card
            'preview_prompt',  // Click Preview Full button
            'copy_prompt',     // Click Copy Full Prompt button
            'apply_structure', // Click Apply Structure button
            'view_site',       // Click Back to Site button
            // Step 2: Batch Basics
            'go_batch',              // Click Batch link
            'generate_fresh_start',  // Click Generate & Load
            'execute_fresh_start',   // Click Execute & Clear
            'view_blank_site',       // Click Back to Site
            'load_starter',          // Click Load button
            'execute_starter',       // Click Execute & Clear
            'view_starter_site',     // Click Back to Site
            // Step 3: Understanding Commands
            'preview_template',      // Click Preview button
            'load_to_queue',         // Click Load button
            'clear_queue',           // Click Clear All button
            // Step 4: Understanding Structure
            'go_structure',          // Click Structure link
            'select_menu_type',      // Select menu in dropdown
            'load_menu_structure',   // Click Load Structure
            'expand_menu',           // Click Expand All
            'select_page_type',      // Select page in dropdown
            'select_home_page',      // Select home page
            'load_page_structure',   // Click Load Structure
            'expand_page',           // Click Expand All
            // Step 5: Assets Management
            'go_commands',           // Click Commands link
            'click_upload_asset',    // Click uploadAsset command
            'execute_upload',        // Click Execute Command
            'go_commands_meta',      // Click Commands link
            'click_update_meta',     // Click updateAssetMeta command
            'go_commands_list',      // Click Commands link
            'click_list_assets',     // Click listAssets command
            // Step 6: Change Favicon
            'go_commands_favicon',   // Click Commands link
            'click_edit_favicon',    // Click editFavicon command
            'execute_favicon',       // Click Execute Command
            // Step 7: Edit Structure
            'go_commands_logo',      // Click Commands link
            'click_edit_structure',  // Click editStructure command
            'execute_logo',          // Click Execute Command
            'go_commands_home',      // Click Commands link
            'click_edit_structure_home', // Click editStructure command
            'execute_home',          // Click Execute Command
            'go_commands_services',  // Click Commands link
            'click_edit_structure_services', // Click editStructure command
            'execute_services',      // Click Execute Command
            // Step 8: CSS Styling
            'go_commands_css',       // Click Commands link
            // Step 9: Theme Customization
            'go_ai_integration',     // Click AI Integration link
            // Step 10: Add Languages
            'go_commands_lang'       // Click Commands link
        ];
        return clickRequired.includes(substep.id);
    }
    
    /**
     * Check if substep is a search step that should wait for command to appear
     * When user clicks a command during search step, we auto-advance
     */
    isSearchStep(substep) {
        return [
            // Step 5
            'find_upload_asset', 'find_update_meta', 'find_list_assets',
            // Step 6
            'find_edit_favicon',
            // Step 7
            'find_edit_structure', 'find_edit_structure_home',
            // Step 8
            'find_edit_styles',
            // Step 9 - only getRootVariables auto-advances
            'find_get_root_variables'
        ].includes(substep?.id);
    }
    
    /**
     * Check if substep should show Next button and let user work at their own pace
     */
    isManualStep(substep) {
        // These steps show Next button - user clicks Next when ready
        return [
            // Step 5: Assets Management
            'download_samples',       // Download images at own pace
            'select_images_category', // Select category at own pace
            'select_files',           // Select files at own pace  
            'view_upload_result',     // View upload result
            'select_meta_category',   // Select category at own pace
            'select_favicon_meta',    // Select file in dropdown
            'enter_favicon_description', // Enter description
            'enter_favicon_alt',      // Enter alt text
            'complete_other_meta',    // User does remaining 3 images
            'verify_assets',          // View results at own pace
            'assets_done',            // Final message
            // Step 6: Change Favicon - user does it themselves
            'configure_favicon',      // User selects file + executes independently
            'view_favicon',           // View website
            'favicon_complete',       // Final message
            // Step 7: Edit Structure - form fields
            'select_type_menu',       // Select type
            'select_element_logo',    // Select element
            'select_insert_before',   // Select action
            'enter_logo_json',        // Enter JSON content
            'view_logo',              // View website
            'select_type_page',       // Select type
            'select_page_home',       // Select page
            'select_element_home',    // Select element
            'select_action_home',     // Select action
            'enter_home_json',        // Enter JSON content
            'view_home',              // View website
            'services_challenge',     // DIY - user does services page independently
            'structure_complete',     // Final message
            // Step 8: CSS Styling
            'view_css_textarea',      // View CSS, click Next when ready
            'understand_css_categories', // Learn CSS structure
            'add_logo_css',           // Add logo CSS code
            'add_hero_css',           // Add hero CSS code
            'add_services_css',       // Add services CSS code
            'css_experiment',         // Free experimentation
            'css_complete',           // Final message
            // Step 9: Theme Customization
            'find_edit_styles_root',  // View editStyles command (manual)
            'execute_get_root_variables', // Execute and observe
            'find_set_root_variables', // View setRootVariables (manual)
            'indicate_global_design', // Highlight the spec card
            'select_global_design',   // Select spec card
            'prepare_prompt',         // Write prompt
            'copy_prompt',            // Copy to clipboard
            'execute_ai_result',      // Paste and execute AI response
            'theme_experiment',       // Optional: try another style
            // Step 10: Add Languages (merged steps)
            'find_language_section',  // Expand language category
            'understand_lang_commands', // Read about commands
            'find_add_lang',          // Navigate to addLang
            'add_spanish_lang',       // Configure + execute addLang
            'verify_lang_list',       // Check getLangList
            'check_footer_structure', // Check getStructure footer
            'add_footer_lang_link',   // Configure + execute editStructure
            'view_website_lang',      // View website
            'lang_complete',          // Final message
            // Step 11: Manage Translations
            'find_translation_section', // Expand translation category
            'understand_trans_commands', // Read about commands
            'indicate_translate_spec',  // Highlight the spec card
            'select_translate_spec',    // Select spec card
            'prepare_translation',      // Write prompt
            'copy_translate_prompt',    // Copy to clipboard
            'execute_translation',      // Paste and execute AI response
            'view_translated_site',     // View translated site
            'translation_complete'      // Final message
        ].includes(substep?.id);
    }
    
    /**
     * Render substep progress dots (clickable to jump to substep)
     */
    renderSubstepProgress(total) {
        let html = '';
        for (let i = 1; i <= total; i++) {
            let cls = 'tutorial-progress-dot';
            if (i < this.currentSubstep) cls += ' completed';
            if (i === this.currentSubstep) cls += ' current';
            html += `<span class="${cls}" onclick="tutorial.jumpToSubstep(${i})" title="Go to substep ${i}"></span>`;
        }
        return html;
    }
    
    /**
     * Jump to a specific substep within current step
     */
    async jumpToSubstep(substepNum) {
        const step = this.steps[this.currentStep];
        if (!step) return;
        
        const totalSubsteps = Object.keys(step.substeps).length;
        if (substepNum < 1 || substepNum > totalSubsteps) return;
        
        this.currentSubstep = substepNum;
        await this.saveProgress();
        this.showCurrentStep();
    }
    
    /**
     * Get icon for current substep
     */
    getSubstepIcon() {
        const icons = ['👆', '👀', '✏️', '🔍', '📋', '✨', '✅'];
        return icons[(this.currentSubstep - 1) % icons.length];
    }
    
    /**
     * Get description for substep
     */
    getSubstepDescription(substep) {
        const descriptions = {
            // Step 1: AI Integration
            'click_create': 'Click on "AI Integration" in the sidebar to get started.',
            'select_spec': 'Choose "Create Website" to generate a complete multi-page website.',
            'enter_goal': 'Describe what kind of website you want to create. Be specific about your needs.',
            'view_examples': 'Browse the example websites to get inspired. You can use any of them as a starting point!',
            'preview_prompt': 'Click "Preview Full" to see the complete prompt that will be sent to your AI.',
            'copy_prompt': 'Click to copy the full prompt to your clipboard.',
            'use_ai_chatbot': 'Now paste this prompt into your favorite AI chatbot (ChatGPT, Claude, Gemini, etc.) and wait for the JSON response. Click Next when ready.',
            'paste_response': 'Paste the JSON response from the AI into this field.',
            'apply_structure': 'Click Execute Now to build your new website!',
            'view_site': 'Congratulations! Your website structure is ready. Click "Back to Site" to preview it in a new tab, then click "Finish Step 1" to complete this step.',
            
            // Step 2: Batch Basics
            'go_batch': 'Click on "Batch" in the sidebar. This powerful page lets you run multiple commands at once using templates.',
            'select_fresh_start': 'This is the "Fresh Start" template. It will reset your website to a completely blank state. Click Next to continue.',
            'generate_fresh_start': 'Click "Generate & Load". ⚠️ A confirmation dialog will appear - click "OK" to proceed (this clears your site data).',
            'execute_fresh_start': 'Click "Execute & Clear Queue" to run all commands and clear your website.',
            'view_blank_site': 'Check your website - it should now be blank! Click "Back to Site" to verify, then click Next.',
            'select_starter_multilingual': 'This is the "Starter Business (Multilingual)" template. It creates a professional multi-language business website. Click Next.',
            'load_starter': 'Click "Load" to add this template\'s commands to the queue.',
            'execute_starter': 'Click "Execute & Clear Queue" to build your new business website!',
            'view_starter_site': 'Amazing! Your professional website is ready. Click "Back to Site" to see your new business site, then Finish Step 2.',
            
            // Step 3: Understanding Commands
            'select_starter_again': 'Let\'s explore how templates work. This is the same "Starter Business (Multilingual)" template. Click Next.',
            'preview_template': 'Click "Preview" to see what commands this template contains.',
            'understand_structure': 'Look at the JSON structure. Each command has a "command" name and "params". This is how QuickSite builds websites! Commands like "addRoute", "editContent", and "addLang" work together. Click Next when ready.',
            'load_to_queue': 'Click "Load" to add these commands to the queue (we won\'t execute them).',
            'view_queue': 'See how the queue fills up? Each item is a command that will be executed in order. You can manually add, remove, or reorder commands here. Click Next.',
            'clear_queue': 'Click "Clear All". A confirmation will ask if you\'re sure - click "OK" to empty the queue (your site won\'t be affected).',
            'commands_done': '✅ Great! You now understand how commands work. Click "Finish Step 3" to learn about page structures next.',
            
            // Step 4: Understanding Structure
            'go_structure': 'Click on "Structure" in the sidebar. This page lets you visualize how your pages are built.',
            'select_menu_type': 'Select "Menu" from the type dropdown to view your navigation structure.',
            'load_menu_structure': 'Click "Load Structure" to display the menu\'s HTML tree.',
            'expand_menu': 'Click "Expand All" to see all nested elements.',
            'observe_menu': 'This is your menu structure! Notice how HTML tags are nested. Click Next when ready.',
            'select_page_type': 'Now select "Page" to view a page structure.',
            'select_home_page': 'Select "home" (or any page) from the dropdown.',
            'load_page_structure': 'Click "Load Structure" to display the page tree.',
            'expand_page': 'Click "Expand All" to see the full page structure.',
            'understand_colors': '🎨 <strong>Color guide:</strong> <span style="color:#7a9eb8">Blue = HTML tags</span>, <span style="color:#6b8e5c">Green = CSS classes</span>, <span style="color:#c2703e">Orange = translation keys</span>. Tags can contain other tags, text, or images. Classes let you style elements with CSS or target them with JavaScript. Click Next.',
            'structure_done': '✅ Great! You now understand page structures. Click "Finish Step 4" to learn about assets management.',
            
            // Step 5: Assets Management
            'download_samples': '📥 <strong>Download Tutorial Images</strong><br><br>Click each link below to download (they\'ll be used throughout this step):<br><ul style="margin:10px 0;padding-left:20px;list-style:disc"><li><a href="' + (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/assets/tutorial/favicon-business.png" download style="color:var(--admin-accent)">favicon-business.png</a> - Website icon</li><li><a href="' + (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/assets/tutorial/logo.png" download style="color:var(--admin-accent)">logo.png</a> - Company logo</li><li><a href="' + (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/assets/tutorial/home-main.png" download style="color:var(--admin-accent)">home-main.png</a> - Home page image</li><li><a href="' + (window.QUICKSITE_CONFIG?.adminBase || '/admin') + '/assets/tutorial/services-main.jpg" download style="color:var(--admin-accent)">services-main.jpg</a> - Services image</li></ul>Click <strong>Next</strong> when all 4 files are downloaded.',
            'go_commands': 'Click on "Commands" in the sidebar to access all available commands.',
            'find_upload_asset': 'Type "upload" in the search bar to find uploadAsset. Click on the command when you see it.',
            'select_images_category': 'Select "images" as the category for your files.',
            'select_files': 'Click to select files or drag & drop your 4 images. You can select multiple files at once!',
            'execute_upload': 'Click "Execute Command" to upload all your images. Watch the progress!',
            'view_upload_result': '✅ Great! Your images are uploaded. Look at the response showing your files. Click Next.',
            'go_commands_meta': 'Now let\'s add descriptions to your images. Click "Commands" in the sidebar.',
            'find_update_meta': 'Type "meta" in the search bar to find updateAssetMeta. Click on the command.',
            'select_meta_category': 'Select "images" as the category.',
            'select_favicon_meta': '📝 Let\'s add meta info to your favicon. Select <strong>favicon-business.png</strong> from the filename dropdown.',
            'enter_favicon_description': 'Enter a description for the favicon: <code style="font-size:11px">Website favicon for browser tab</code>',
            'enter_favicon_alt': 'Enter alt text (for accessibility): <code style="font-size:11px">Company favicon</code>',
            'execute_favicon_meta': 'Click "Execute Command" to save the metadata.',
            'complete_other_meta': '📝 Now repeat for the other 3 images! Select each file, add a description and alt text, then execute. Click Next when all 4 images have metadata.',
            'go_commands_list': 'Let\'s verify everything. Click "Commands" again.',
            'find_list_assets': 'Type "list" in the search bar to find listAssets. Click on the command.',
            'verify_assets': '✅ Select "images" category and execute the command. You\'ll see all your uploaded images with their descriptions!',
            'assets_done': '🎉 <strong>Assets uploaded!</strong> Your images are ready to use. Click "Finish Step 5" to continue.',
            
            // Step 6: Change Favicon (streamlined - user applies knowledge independently)
            'go_commands_favicon': 'Now let\'s use your uploaded images! Click "Commands" in the sidebar.',
            'find_edit_favicon': 'Type "favicon" in the search bar. Click on editFavicon when you see it.',
            'configure_favicon': '🎯 <strong>Your turn!</strong> You know how forms work now. Select <strong>favicon-business.png</strong> from the dropdown, then click Execute. Click Next when done.',
            'view_favicon': '👀 Check your browser tab - you should see the new favicon! <em>(You may need to refresh)</em> Click Next when ready.',
            'favicon_complete': '🎉 <strong>Favicon changed!</strong> Your website now has a custom icon. Click "Finish Step 6" to continue.',
            
            // Step 7: Edit Structure (add logo + images)
            // Part 1: Add logo to menu (guided)
            'go_commands_logo': 'Now let\'s add images to your website! Click "Commands".',
            'find_edit_structure': 'Type "editStructure" in the search bar. Click on the command when you see it.',
            'select_type_menu': 'Select "menu" as the type. <em>Note: The "name" field is disabled because menu and footer don\'t use routes.</em>',
            'select_element_logo': 'Select element <strong>0.0.0</strong> - this is the "Our Business" title link. Each number represents a level in the structure tree.',
            'select_insert_before': 'Select <strong>"Add sibling before"</strong> to insert our logo before the existing element.',
            'enter_logo_json': '📝 Enter this JSON to add your logo:<br><code style="font-size:11px">{"tag":"img","params":{"src":"/assets/images/logo.png","alt":"Logo","class":"logo-img"}}</code>',
            'execute_logo': 'Click "Execute Command" to add the logo.',
            'view_logo': '👀 Check your website - the logo appears! <em>(It may look oversized - we\'ll fix this with CSS in Step 8)</em> Click Next.',
            // Part 2: Add image to home page (guided)
            'go_commands_home': 'Now let\'s add an image to the home page. Click "Commands".',
            'find_edit_structure_home': 'Type "editStructure". Click on the command.',
            'select_type_page': 'Select "page" as the type.',
            'select_page_home': 'Select "home" as the page name.',
            'select_element_home': 'Choose an element where you want to add the image (e.g., a section container).',
            'select_action_home': 'Select an action (e.g., "Add child at end").',
            'enter_home_json': '📝 Enter JSON to add the home image:<br><code style="font-size:11px">{"tag":"img","params":{"src":"/assets/images/home-main.png","alt":"Welcome","class":"hero-image"}}</code>',
            'execute_home': 'Click "Execute Command".',
            'view_home': '👀 Check the home page - your image is there! <em>(It may not be positioned or sized correctly yet - we\'ll fix this with CSS in Step 8)</em> Click Next.',
            // Part 3: Services page challenge (DIY)
            'services_challenge': '🎯 <strong>Challenge Time!</strong> You\'ve learned how editStructure works. Now add <strong>services-main.jpg</strong> to the <strong>services</strong> page on your own!<br><br>Hints:<br>• Find editStructure, select page type, choose "services"<br>• Pick an element ID, choose an action<br>• Use: <code style="font-size:11px">{"tag":"img","params":{"src":"/assets/images/services-main.jpg","alt":"Services","class":"services-image"}}</code><br><br>Click Next when done!',
            'structure_complete': '🎉 <strong>Structure edited!</strong> You\'ve mastered adding images with editStructure. The images may look unstyled - we\'ll fix that with CSS next! Click "Finish Step 7".',
            
            // Step 8: CSS Styling
            'go_commands_css': 'Now let\'s make your images look great! Click "Commands" in the sidebar.',
            'find_edit_styles': 'Type "editStyles" in the search bar. Click on the command when you see it.',
            'view_css_textarea': '📝 <strong>Welcome to CSS!</strong><br><br>CSS (Cascading Style Sheets) is how we make websites beautiful. It controls:<br>• <strong>Colors</strong> - backgrounds, text, borders<br>• <strong>Sizes</strong> - widths, heights, spacing<br>• <strong>Shapes</strong> - borders, rounded corners<br>• <strong>Positioning</strong> - layout, alignment<br>• <strong>Animations</strong> - transitions, effects<br><br>What you see in this textarea is the current CSS preset. Read through it if you\'d like, then click <strong>Next</strong>.',
            'understand_css_categories': '📚 <strong>CSS is organized in sections:</strong><br><br>1. <code>:root</code> - Global color variables (we\'ll use this in Step 9)<br>2. <strong>GLOBAL RESET</strong> - Base styles for all elements<br>3. <strong>NAVIGATION</strong> - Menu styling<br>4. <strong>HERO SECTION</strong> - Main banner area (the "hero" grabs attention)<br>5. <strong>PAGE HEADERS</strong> - Section titles<br>6. <strong>FEATURES</strong> - Feature cards<br>7. <strong>SERVICES</strong> - Services page<br>8. <strong>CONTACT</strong> - Contact page<br>9. <strong>ABOUT</strong> - About page<br>10. <strong>FOOTER</strong> - Footer styling<br><br>Click <strong>Next</strong> to style the logo!',
            'add_logo_css': '✏️ <strong>Style the logo!</strong><br><br>Find the <code>/* NAVIGATION */</code> section and add this at the end:<br><pre style="background:#1e1e1e;padding:8px;border-radius:4px;margin:8px 0;font-size:11px;color:#e0e0e0">.logo-img {\n  height: 50px;\n  vertical-align: bottom;\n}</pre><span class="substep-description">The <code>.</code> targets elements with that class name. Everything inside <code>{ }</code> are the styles to apply.<br><br><strong>Tip:</strong> Use Ctrl+F to search for "NAVIGATION".<br><br>When ready, click <strong>Execute Command</strong>, then check your website - the logo should be properly sized! Click <strong>Next</strong> when done.</span>',
            'add_hero_css': '✏️ <strong>Style the hero image!</strong><br><br>Find the <code>/* HERO SECTION */</code> and add this at the end:<br><pre style="background:#1e1e1e;padding:8px;border-radius:4px;margin:8px 0;font-size:11px;color:#e0e0e0">.hero-image {\n  display: block;\n  max-width: 100%;\n  margin: 20px auto;\n  border-radius: 12px;\n  box-shadow: 0 4px 15px rgba(0,0,0,0.2);\n}</pre><span class="substep-description">• <code>display: block</code> + <code>margin: auto</code> = centered<br>• <code>border-radius</code> = rounded corners<br>• <code>box-shadow</code> = subtle shadow<br><br>Click <strong>Execute Command</strong>, then check the home page - your image should be centered with nice rounded corners! Click <strong>Next</strong> when done.</span>',
            'add_services_css': '✏️ <strong>Style the services image!</strong><br><br>Find <code>/* SERVICES SECTION */</code> and add:<br><pre style="background:#1e1e1e;padding:8px;border-radius:4px;margin:8px 0;font-size:11px;color:#e0e0e0">.services-image {\n  display: block;\n  max-width: 80%;\n  margin: 30px auto;\n  border: 3px solid var(--primary);\n  border-radius: 8px;\n  padding: 5px;\n}</pre><span class="substep-description">• <code>border</code> = colored border using CSS variable<br>• <code>padding</code> = space between image and border<br>• <code>max-width: 80%</code> = doesn\'t fill entire width<br><br>Click <strong>Execute Command</strong>, then check the services page - your image should have a nice bordered frame! Click <strong>Next</strong> when done.</span>',
            'css_experiment': '🧪 <strong>Experiment Time!</strong><br><br>Try changing values and see what happens! Ideas:<br>• Change <code>border-radius</code> values (px or %)<br>• Adjust <code>margin</code> and <code>padding</code><br>• Try different <code>box-shadow</code> effects<br>• Change colors using <code>var(--primary)</code>, <code>var(--secondary)</code><br><br>⚠️ <strong>Note:</strong> Don\'t modify the <code>:root</code> section yet - we\'ll use AI to redesign the entire theme in Step 9!<br><br>Click <strong>Next</strong> when you\'re done experimenting.',
            'css_complete': '🎉 <strong>CSS Styling Complete!</strong><br><br>You\'ve learned:<br>• How CSS sections are organized<br>• How to target elements with class selectors<br>• How to apply sizes, borders, shadows, and spacing<br><br>Your images now look great! Click "Finish Step 8" to learn about theme customization.',
            
            // Step 9: Theme Customization
            'find_edit_styles_root': '🎨 <strong>Discover CSS Variables!</strong><br><br>Go to the <strong>editStyles</strong> command. Look at the command description - it mentions the <code>:root</code> section containing CSS variables.<br><br>The <code>:root</code> block holds <strong>global design tokens</strong> - colors, spacing, and more that are reused throughout the CSS. Changing these transforms the entire site!<br><br>Click <strong>Next</strong> when you\'ve read about it.',
            'find_get_root_variables': '🔍 Now let\'s see a focused view! Type "getRootVariables" in the search. This command extracts <em>just</em> the <code>:root</code> variables from the full CSS. Click on it when you see it.',
            'execute_get_root_variables': '▶️ <strong>Execute the command!</strong><br><br>Click <strong>Execute Command</strong> to see your current CSS variables. Notice how the response shows the same variables from the <code>:root</code> section, but in a clean JSON format.<br><br>Click <strong>Next</strong> after viewing the result.',
            'find_set_root_variables': '✏️ Go to the <strong>setRootVariables</strong> command. This command lets you <strong>modify</strong> one or more variables without touching the rest of your CSS.<br><br>Look at the form - you can change individual variables safely. But don\'t execute anything yet! We have a smarter way...<br><br>Click <strong>Next</strong> when ready.',
            'go_ai_integration': '🤖 <strong>Let AI help!</strong><br><br>Instead of manually picking colors, let\'s use AI to generate a cohesive theme. Click <strong>"AI Integration"</strong> in the sidebar.',
            'indicate_global_design': '👀 Look for the <strong>"Global Design Rework"</strong> spec card. This spec is designed specifically for theme customization - it will show the AI your current variables and ask for harmonious new values.<br><br>Click <strong>Next</strong> when you\'ve found it.',
            'select_global_design': '🎯 Now click on the <strong>"Global Design Rework"</strong> card to select it.',
            'prepare_prompt': '✍️ <strong>Craft your design request!</strong><br><br>You can either:<br>• Click one of the example buttons (Modern Minimalist, Dark Mode, etc.)<br>• Or write your own vision in the text area<br><br>Be creative! Try things like:<br>• "Ocean vibes with teal and sandy colors"<br>• "Professional law firm look"<br>• "Playful and colorful for kids"<br><br>Click <strong>Next</strong> when you\'ve entered your idea.',
            'copy_prompt': '📋 Click <strong>"Preview Full Prompt"</strong> to see the complete prompt (with your current variables included), then click <strong>"Copy Full Prompt"</strong>.<br><br>Paste this into your favorite AI chatbot (ChatGPT, Claude, etc.). The AI will return a JSON command to transform your theme!<br><br>Click <strong>Next</strong> after copying.',
            'execute_ai_result': '🚀 <strong>Apply the transformation!</strong><br><br>1. Paste your AI\'s response in the <strong>"Import & Execute JSON"</strong> textarea below<br>2. Click <strong>"Execute"</strong><br>3. Visit your website to see the new theme!<br><br>⚠️ Note: AI results vary - if you don\'t like it, try a different prompt or run it again.<br><br>Click <strong>Next</strong> when done.',
            'theme_experiment': '🎨 <strong>Want to try another style?</strong><br><br>You can repeat this process anytime:<br>• Go to AI Integration → Global Design Rework<br>• Try different prompts for different moods<br>• Each execution replaces the previous theme<br><br>Some ideas to try:<br>• Seasonal themes ("Christmas red and green")<br>• Industry-specific ("Tech startup", "Restaurant warm tones")<br>• Mood-based ("Calm and zen", "Bold and energetic")<br><br>When you\'re happy with your theme, click <strong>"Finish Step 9"</strong>!',
            
            // Step 10: Add Languages
            'go_commands_lang': '🌍 <strong>Time to go multilingual!</strong><br><br>Let\'s add a new language to your website. Click <strong>"Commands"</strong> in the sidebar.',
            'find_language_section': '📂 Find and expand the <strong>"Language"</strong> category in the command list.<br><br>This section contains all commands for managing languages on your website.<br><br>Click <strong>Next</strong> when you\'ve expanded it.',
            'understand_lang_commands': '📚 <strong>Language Commands Overview:</strong><br><br>• <code>getLangList</code> - Returns available languages (you should have English and French)<br>• <code>setMultilingual</code> - Toggle between monolingual/multilingual mode<br>• <code>checkStructureMulti</code> - Check language switch links in your structure<br>• <code>addLang</code> - Add a new language<br>• <code>deleteLang</code> - Remove a language<br>• <code>setDefaultLang</code> - Set which language is default (useful if going back to monolingual)<br><br>Click <strong>Next</strong> to add Spanish!',
            'find_add_lang': '➕ Go to the <strong>addLang</strong> command.<br><br>Click <strong>Next</strong> when you\'re on the addLang form.',
            'add_spanish_lang': '✏️ <strong>Add Spanish to your website!</strong><br><br>Fill in the form:<br>• <strong>code</strong>: <code>es</code><br>• <strong>name</strong>: <code>Español</code><br><br>Then click <strong>Execute Command</strong>.<br><br>Click <strong>Next</strong> after execution succeeds.',
            'verify_lang_list': '✅ <strong>Verify the language was added!</strong><br><br>Go to <strong>getLangList</strong> and execute it. You should now see <strong>es (Español)</strong> in the list alongside English and French.<br><br>Click <strong>Next</strong> when you\'ve confirmed.',
            'check_footer_structure': '🔍 <strong>Check the footer structure!</strong><br><br>Go to <strong>getStructure</strong>, select type <strong>footer</strong>, and load it.<br><br>Find the nodes containing <code>__RAW__English</code> and <code>__RAW__Français</code> - these are the language switch links. Note the node ID of <code>__RAW__Français</code> (should be around <strong>0.0.2.1.1</strong>).<br><br>We\'ll add Spanish after it!<br><br>Click <strong>Next</strong> when ready.',
            'add_footer_lang_link': '✏️ <strong>Add the Spanish link to footer!</strong><br><br>Go to <strong>editStructure</strong> and configure:<br>• Type: <code>footer</code><br>• Name: <em>(leave empty)</em><br>• Node ID: <code>0.0.2.1.1</code><br>• Action: <code>Insert After (Add sibling after)</code><br><br>In the structure field, paste:<br><pre style="background:#1e1e1e;padding:8px;border-radius:4px;margin:8px 0;font-size:10px;color:#e0e0e0;white-space:pre-wrap">{\n  "tag": "a",\n  "params": {\n    "href": "{{__current_page;lang=es}}",\n    "class": "lang-link"\n  },\n  "children": [\n    { "textKey": "__RAW__Español" }\n  ]\n}</pre><span class="substep-description">💡 <strong>Note:</strong> If your footer structure is different, adjust the node ID accordingly.<br><br>Click <strong>Execute Command</strong>, then <strong>Next</strong>.</span>',
            'view_website_lang': '👀 <strong>Check your website!</strong><br><br>Visit your site and scroll to the footer. You should now see <strong>Español</strong> as a language option!<br><br>⚠️ Note: Clicking it will switch to Spanish, but translations don\'t exist yet - you\'ll see the translation keys. We\'ll cover translations in the next step!<br><br>Click <strong>Next</strong> when done.',
            'lang_complete': '🎉 <strong>Languages Step Complete!</strong><br><br>You\'ve learned:<br>• How language commands work<br>• How to add a new language with <code>addLang</code><br>• How to add language switch links to structure<br><br>Your website now supports English, French, and Spanish! Click <strong>"Finish Step 10"</strong>!',
            
            // Step 11: Manage Translations
            'go_commands_trans': '🌐 <strong>Time to add translations!</strong><br><br>Now that Spanish is available, let\'s add the actual translations so your content displays in Spanish. Click <strong>"Commands"</strong> in the sidebar.',
            'find_translation_section': '📂 Find and expand the <strong>"Translation"</strong> category in the command list.<br><br>This section contains all commands for managing translations on your website.<br><br>Click <strong>Next</strong> when you\'ve expanded it.',
            'understand_trans_commands': '📚 <strong>Translation Commands Overview:</strong><br><br>• <code>getTranslations</code> - Get all translations for all languages<br>• <code>getTranslation</code> - Get translations for a specific language<br>• <code>setTranslationKeys</code> - Add or update translation keys (merges safely)<br>• <code>deleteTranslationKeys</code> - Remove translation keys<br>• <code>validateTranslations</code> - Check for missing translations<br>• <code>analyzeTranslations</code> - Full health check of translations<br><br>Click <strong>Next</strong> to use AI for translations!',
            'go_ai_translate': '🤖 <strong>Let AI do the heavy lifting!</strong><br><br>Instead of manually translating every key, we\'ll use AI to translate the entire site at once. Click <strong>"AI Integration"</strong> in the sidebar.',
            'indicate_translate_spec': '👀 Look for the <strong>"Translate Language"</strong> spec card. This spec will load your default language translations and ask the AI to provide a complete translation for the target language.<br><br>Click <strong>Next</strong> when you\'ve found it.',
            'select_translate_spec': '🎯 Now click on the <strong>"Translate Language"</strong> card to select it.',
            'prepare_translation': '✍️ <strong>Request the Spanish translation!</strong><br><br>In the text area, describe what you want:<br><br>Example: <em>"Translate all content to Spanish (es). Keep a professional but friendly tone."</em><br><br>You can also click one of the example buttons like "🇪🇸 Translate to Spanish".<br><br>Click <strong>Next</strong> when you\'ve entered your request.',
            'copy_translate_prompt': '📋 Click <strong>"Preview Full Prompt"</strong> to see the complete prompt (with all your current translations included), then click <strong>"Copy Full Prompt"</strong>.<br><br>Paste this into your favorite AI chatbot (ChatGPT, Claude, etc.). The AI will return a JSON command with the complete Spanish translations!<br><br>Click <strong>Next</strong> after copying.',
            'execute_translation': '🚀 <strong>Apply the translations!</strong><br><br>1. Paste your AI\'s response in the <strong>"Import & Execute JSON"</strong> textarea below<br>2. Click <strong>"Execute"</strong><br>3. The translations will be merged into your Spanish language file!<br><br>⚠️ Note: AI translations may need minor adjustments for perfect accuracy.<br><br>Click <strong>Next</strong> when done.',
            'view_translated_site': '🌍 <strong>Check your translated site!</strong><br><br>Visit your website and click the <strong>Español</strong> link in the footer.<br><br>Your site should now display in Spanish! All the menu items, page content, and footer text should be translated.<br><br>Click <strong>Next</strong> when you\'ve verified.',
            'translation_complete': '🎉 <strong>Tutorial Complete!</strong><br><br>Congratulations! You\'ve learned:<br>• How to create websites with AI<br>• Batch operations and templates<br>• Structure editing and assets<br>• CSS styling and themes<br>• Adding languages and translations<br><br>You now have a fully multilingual website! Click <strong>"Finish Step 11"</strong> to complete the tutorial!'
        };
        
        return descriptions[substep.id] || substep.title;
    }
    
    /**
     * Ensure we're on the correct page for current step
     * Only redirects if tutorial is actively running
     * Does NOT redirect for "go_*" navigation steps - user must click those themselves
     */
    ensureCorrectPage() {
        // Don't redirect if tutorial is not active
        if (!this.isActive || this.status !== 'active') {
            return;
        }
        
        // Get current substep to determine exact page needed
        const step = this.steps[this.currentStep];
        const substep = step?.substeps?.[this.currentSubstep];
        
        // NEVER auto-redirect for "go_*" navigation steps - user must click themselves
        if (substep?.id?.startsWith('go_')) {
            return;
        }
        
        // Steps 5, 6, 7, 8, 9, 10, and 11 have different page requirements per substep
        if (this.currentStep >= 5 && this.currentStep <= 11) {
            const currentPage = this.getCurrentPage();
            
            // Substeps that need the command LIST page (not form page)
            // Excludes "go_*" steps since those are handled above
            // With auto-advance, search steps ("find_*") will auto-click the command
            const needsCommandList = [
                // Step 5
                'find_upload_asset',
                'find_update_meta',
                'find_list_assets',
                // Step 6
                'find_edit_favicon',
                // Step 7
                'find_edit_structure',
                'find_edit_structure_home',
                // Step 8
                'find_edit_styles',
                // Step 9 - only getRootVariables needs command page redirect (it's a search step)
                'find_get_root_variables',
                // Step 10 - find language section needs command list
                'find_language_section',
                // Step 11 - find translation section needs command list
                'find_translation_section'
                // Note: other step 10/11 substeps are manual - user navigates themselves
            ];
            
            if (needsCommandList.includes(substep?.id)) {
                // Need command list page - only redirect if on wrong page (e.g., after refresh)
                if (currentPage !== 'command') {
                    // Use the sidebar nav link which goes to command list (not a specific command)
                    const link = document.querySelector('.admin-nav__link[href*="command"]');
                    if (link) {
                        const url = new URL(link.href);
                        url.searchParams.set('tutorial', 'continue');
                        window.location.href = url.toString();
                    }
                }
                return;
            }
            
            // Step 9: After command exploration, needs AI Integration page
            if (this.currentStep === 9) {
                // Steps 9.6+ need AI Integration page (after go_ai_integration)
                const needsAiPage = [
                    'indicate_global_design',
                    'select_global_design',
                    'prepare_prompt',
                    'copy_prompt',
                    'execute_ai_result',
                    'theme_experiment'
                ];
                
                if (needsAiPage.includes(substep?.id)) {
                    if (currentPage !== 'ai') {
                        const link = document.querySelector('.admin-nav__link[href*="ai"]');
                        if (link) {
                            const url = new URL(link.href);
                            url.searchParams.set('tutorial', 'continue');
                            window.location.href = url.toString();
                        }
                    }
                }
                // Steps 9.1-9.4 stay on whatever page user is on (no redirect)
            }
            
            // Step 11: After command exploration, needs AI Integration page
            if (this.currentStep === 11) {
                // Steps 11.5+ need AI Integration page (after go_ai_translate)
                const needsAiPage = [
                    'indicate_translate_spec',
                    'select_translate_spec',
                    'prepare_translation',
                    'copy_translate_prompt',
                    'execute_translation',
                    'view_translated_site',
                    'translation_complete'
                ];
                
                if (needsAiPage.includes(substep?.id)) {
                    if (currentPage !== 'ai') {
                        const link = document.querySelector('.admin-nav__link[href*="ai"]');
                        if (link) {
                            const url = new URL(link.href);
                            url.searchParams.set('tutorial', 'continue');
                            window.location.href = url.toString();
                        }
                    }
                }
                // Steps 11.1-11.3 stay on command page (no redirect)
            }
            // For other substeps (select_*, execute_*, view_*, services_challenge, etc.), stay on current page
            return;
        }
        
        const pageMap = {
            1: 'ai',        // Step 1: AI Integration page
            2: 'batch',     // Step 2: Batch Basics
            3: 'batch',     // Step 3: Understanding Commands
            4: 'structure'  // Step 4: Understanding Structure
        };
        
        const targetPage = pageMap[this.currentStep];
        const currentPage = this.getCurrentPage();
        
        if (targetPage && currentPage !== targetPage) {
            // Navigate to correct page
            const link = document.querySelector(`a[href*="${targetPage}"]`);
            if (link) {
                // Add flag to URL so we continue tutorial after navigation
                const url = new URL(link.href);
                url.searchParams.set('tutorial', 'continue');
                window.location.href = url.toString();
            }
        }
    }
    
    /**
     * Get current page from URL
     */
    getCurrentPage() {
        const path = window.location.pathname;
        if (path.includes('/ai')) return 'ai';
        if (path.includes('/batch')) return 'batch';
        if (path.includes('/build')) return 'build';
        if (path.includes('/structure')) return 'structure';
        if (path.includes('/settings')) return 'settings';
        if (path.includes('/favorites')) return 'favorites';
        // Check for specific command form (e.g., /command/uploadAsset) vs command list (/command)
        if (path.match(/\/command\/[^\/]+/)) return 'command-form';
        if (path.includes('/command')) return 'command';
        return 'dashboard';
    }
    
    /**
     * Highlight an element
     */
    highlightElement(selector, retryCount = 0) {
        // Remove previous highlight
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
        });
        
        // Find target element
        const selectors = selector.split(',').map(s => s.trim());
        let target = null;
        
        for (const sel of selectors) {
            target = document.querySelector(sel);
            if (target) break;
        }
        
        if (!target) {
            // Retry a few times for dynamically rendered elements
            if (retryCount < 10) {
                console.log('Tutorial: Target not found yet, retrying...', selector);
                setTimeout(() => this.highlightElement(selector, retryCount + 1), 200);
                return;
            }
            console.warn('Tutorial: Target not found after retries:', selector);
            this.focusRingElement.style.display = 'none';
            return;
        }
        
        this.targetElement = target;
        
        // Add highlight class
        target.classList.add('tutorial-highlight');
        
        // Position focus ring
        const rect = target.getBoundingClientRect();
        const padding = 10;
        
        this.focusRingElement.style.display = 'block';
        this.focusRingElement.style.top = (rect.top + window.scrollY - padding) + 'px';
        this.focusRingElement.style.left = (rect.left + window.scrollX - padding) + 'px';
        this.focusRingElement.style.width = (rect.width + padding * 2) + 'px';
        this.focusRingElement.style.height = (rect.height + padding * 2) + 'px';
        
        // Allow clicking the target
        this.focusRingElement.style.pointerEvents = 'none';
        
        // Get current substep info for click handling
        const currentStep = this.steps[this.currentStep];
        const currentSubstep = currentStep?.substeps?.[this.currentSubstep];
        
        // Only add click listener if this is NOT a manual step
        // Manual steps show Next button and user clicks Next when ready
        if (!this.isManualStep(currentSubstep) && !this.isSearchStep(currentSubstep)) {
            // Add click listener to target for auto-advance
            // Use capture phase to catch the click before navigation
            target.addEventListener('click', (e) => this.onTargetClick(e, target), { once: true, capture: true });
        }
        
        // For specific dropdown steps that should auto-advance on change,
        // set up the change listener directly (don't wait for click)
        const autoAdvanceDropdowns = [
            // Step 5: Assets Management
            'select_images_category',  // category dropdown
            'select_meta_category',    // meta category dropdown
            'select_favicon_meta',     // filename dropdown for favicon
            // Step 7: Edit Structure - Part 1 (logo)
            'select_type_menu',        // type dropdown
            'select_element_logo',     // elementId dropdown
            'select_insert_before',    // action dropdown
            // Step 7: Edit Structure - Part 2 (home page)
            'select_type_page',        // type dropdown
            'select_page_home',        // page name dropdown
            'select_element_home',     // elementId dropdown
            'select_action_home'       // action dropdown
        ];
        
        if (autoAdvanceDropdowns.includes(currentSubstep?.id) && target.tagName === 'SELECT') {
            const handleSelectChange = () => {
                target.removeEventListener('change', handleSelectChange);
                console.log('Tutorial: Dropdown changed, auto-advancing from', currentSubstep?.id);
                setTimeout(() => {
                    this.nextSubstep();
                }, 300);
            };
            target.addEventListener('change', handleSelectChange);
        }
        
        // For file input steps, set up the change listener directly
        // Handle both direct file inputs and file inputs inside wrapper elements
        if (currentSubstep?.id === 'select_files') {
            // Find the actual file input - might be the target or inside the target
            let fileInput = target;
            if (target.tagName !== 'INPUT' || target.type !== 'file') {
                fileInput = target.querySelector('input[type="file"]');
            }
            
            if (fileInput) {
                const handleFileChange = () => {
                    if (fileInput.files && fileInput.files.length > 0) {
                        fileInput.removeEventListener('change', handleFileChange);
                        console.log('Tutorial: Files selected, auto-advancing');
                        setTimeout(() => {
                            this.nextSubstep();
                        }, 300);
                    }
                };
                fileInput.addEventListener('change', handleFileChange);
            }
        }
        
        // Special listener for import JSON textarea - auto-advance when valid JSON detected
        if (selector.includes('import-json')) {
            const handleValidJson = () => {
                // Only advance if we're still on the paste_response step
                const step = this.steps[this.currentStep];
                const substep = step?.substeps?.[this.currentSubstep];
                if (substep?.id === 'paste_response') {
                    document.removeEventListener('quicksite:import-json-valid', handleValidJson);
                    setTimeout(() => this.nextSubstep(), 500);
                }
            };
            document.addEventListener('quicksite:import-json-valid', handleValidJson);
        }
        
        // Scroll target into view if needed, then reposition bubble after scroll completes
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        // Reposition bubble after scroll animation (smooth scroll takes ~300-500ms)
        // Skip for certain substeps where initial positioning is already correct
        const step = this.steps[this.currentStep];
        const substepData = step?.substeps?.[this.currentSubstep];
        const skipRepositionSubsteps = ['select_spec'];
        
        if (!skipRepositionSubsteps.includes(substepData?.id)) {
            setTimeout(() => {
                this.positionBubble();
            }, 400);
        }
    }
    
    /**
     * Handle click on target element
     */
    async onTargetClick(event, target) {
        // Check if target is a link that will navigate
        const isLink = target.tagName === 'A' && target.href;
        const isExternalLink = isLink && target.target === '_blank';
        const isInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA';
        const isSelect = target.tagName === 'SELECT';
        
        if (isExternalLink) {
            // Link opens in new tab - don't advance automatically
            // Set flag to allow nextSubstep() to work when user clicks Finish button
            this.externalLinkClicked = true;
            
            // Show the Next/Finish button so user can click it when ready
            const nextBtn = this.bubbleElement?.querySelector('.tutorial-btn-primary');
            if (nextBtn) {
                const t = this.translations;
                const step = this.steps[this.currentStep];
                const totalSubsteps = Object.keys(step.substeps).length;
                const isLastSubstep = this.currentSubstep === totalSubsteps;
                
                if (isLastSubstep) {
                    const finishText = t.finishStep || 'Finish Step';
                    nextBtn.innerHTML = `${finishText} ${this.currentStep} <i class="bi bi-check-lg"></i>`;
                } else {
                    nextBtn.innerHTML = `${t.next || 'Next'} <i class="bi bi-arrow-right"></i>`;
                }
                nextBtn.style.display = 'inline-flex';
            }
            // Let the link open in new tab naturally
            return;
        } else if (isLink) {
            // Update progress for next substep
            console.log(`Tutorial: Link clicked, advancing from ${this.currentStep}.${this.currentSubstep}`);
            this.currentSubstep++;
            const step = this.steps[this.currentStep];
            if (this.currentSubstep > Object.keys(step.substeps).length) {
                this.currentStep++;
                this.currentSubstep = 1;
            }
            console.log(`Tutorial: New progress: ${this.currentStep}.${this.currentSubstep}`);
            
            // Use sendBeacon for reliable saving during navigation
            // sendBeacon is designed to send data even when page is unloading
            this.saveProgressBeacon();
            
            // Let the link navigate naturally
        } else if (isSelect) {
            // Get current substep info
            const step = this.steps[this.currentStep];
            const substep = step?.substeps[this.currentSubstep];
            
            // Specific dropdown steps that SHOULD auto-advance when selection changes
            const autoAdvanceDropdowns = [
                'select_images_category',  // Step 5: category dropdown
                'select_meta_category',    // Step 5: meta category dropdown
                'select_favicon_meta'      // Step 5: filename dropdown for favicon
            ];
            
            // For manual steps that are NOT in autoAdvanceDropdowns, don't auto-advance
            if (this.isManualStep(substep) && !autoAdvanceDropdowns.includes(substep?.id)) {
                // Just let user interact - they'll click Next when ready
                return;
            }
            
            // For regular select elements and autoAdvanceDropdowns, wait for change event
            const handleSelectChange = () => {
                target.removeEventListener('change', handleSelectChange);
                setTimeout(() => {
                    this.nextSubstep();
                }, 300);
            };
            target.addEventListener('change', handleSelectChange);
        } else if (isInput) {
            // Get current substep info
            const step = this.steps[this.currentStep];
            const substep = step?.substeps[this.currentSubstep];
            
            // For file inputs, ALWAYS set up auto-advance (even for manual steps like select_files)
            if (target.type === 'file') {
                const handleFileChange = () => {
                    if (target.files && target.files.length > 0) {
                        target.removeEventListener('change', handleFileChange);
                        console.log('Tutorial: Files selected, auto-advancing');
                        setTimeout(() => {
                            this.nextSubstep();
                        }, 300);
                    }
                };
                target.addEventListener('change', handleFileChange);
                return;
            }
            
            // For search steps and other manual steps, don't auto-advance on text input
            if (this.isSearchStep(substep) || this.isManualStep(substep)) {
                // Just let user interact - they'll click Next when ready
                return;
            }
            
            // For regular input/textarea, wait for actual input and then blur
            // Don't advance on click - wait for them to type something
            const handleInputComplete = () => {
                if (target.value && target.value.trim().length > 0) {
                    target.removeEventListener('blur', handleInputComplete);
                    setTimeout(() => {
                        this.nextSubstep();
                    }, 300);
                }
            };
            target.addEventListener('blur', handleInputComplete);
        } else {
            // Check if this is a "confirm dialog" substep (like clear_queue)
            const step = this.steps[this.currentStep];
            const substep = step?.substeps[this.currentSubstep];
            const isConfirmSubstep = substep && ['clear_queue'].includes(substep.id);
            
            if (isConfirmSubstep) {
                // Hide overlay so confirm dialog is visible
                this.hideOverlay();
                
                // Watch for queue to be emptied (user clicked OK on confirm)
                const queueContainer = document.querySelector('#batch-queue');
                if (queueContainer) {
                    const checkQueueCleared = () => {
                        const queueItems = queueContainer.querySelectorAll('.batch-queue-item');
                        if (queueItems.length === 0) {
                            // Queue is empty - user confirmed!
                            this.showOverlay();
                            this.nextSubstep();
                        } else {
                            // Check again in 200ms (user might have cancelled)
                            setTimeout(checkQueueCleared, 200);
                        }
                    };
                    // Start checking after a brief delay (let confirm dialog appear)
                    setTimeout(checkQueueCleared, 300);
                } else {
                    // Fallback: just advance
                    setTimeout(() => {
                        this.nextSubstep();
                    }, 300);
                }
            } else {
                // Not a link or input, advance after a short delay
                setTimeout(() => {
                    this.nextSubstep();
                }, 300);
            }
        }
    }
    
    /**
     * Save progress using sendBeacon (for navigation scenarios)
     * sendBeacon is guaranteed to complete even if page unloads
     */
    saveProgressBeacon() {
        // Also save to localStorage as backup
        this.saveLocalProgress();
        
        const url = `${window.QUICKSITE_CONFIG.adminBase}/tutorial-api.php?action=update`;
        const data = JSON.stringify({
            step: this.currentStep,
            substep: this.currentSubstep,
            status: this.status
        });
        
        // sendBeacon sends as POST with Content-Type: text/plain by default
        // We use a Blob to send as application/json
        const blob = new Blob([data], { type: 'application/json' });
        const success = navigator.sendBeacon(url, blob);
        
        console.log('Tutorial: Beacon save', { step: this.currentStep, substep: this.currentSubstep, sent: success });
    }
    
    /**
     * Show overlay
     */
    showOverlay() {
        if (this.overlayElement) {
            this.overlayElement.style.display = 'block';
            this.overlayElement.classList.add('active');
        }
    }
    
    /**
     * Hide overlay
     */
    hideOverlay() {
        if (this.overlayElement) {
            this.overlayElement.classList.remove('active');
            this.overlayElement.style.display = 'none';
        }
        if (this.focusRingElement) {
            this.focusRingElement.style.display = 'none';
        }
        // Remove highlight class from any elements
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
        });
    }
    
    /**
     * Position bubble near target
     */
    positionBubble() {
        // If user has dragged the bubble, use their position
        if (this.userDragPosition) {
            this.bubbleElement.style.left = this.userDragPosition.x + 'px';
            this.bubbleElement.style.top = this.userDragPosition.y + 'px';
            this.bubbleElement.style.right = 'auto';
            this.bubbleElement.style.bottom = 'auto';
            this.bubbleElement.style.transform = 'none';
            // Keep current class without arrows
            this.bubbleElement.className = this.bubbleElement.className.replace(/arrow-\w+/g, '').trim();
            if (!this.bubbleElement.classList.contains('tutorial-bubble')) {
                this.bubbleElement.classList.add('tutorial-bubble');
            }
            return;
        }
        
        if (!this.targetElement) {
            // No target - position bubble in a central, visible location
            // Try to find a relevant anchor element based on current step
            const step = this.steps[this.currentStep];
            const substep = step?.substeps?.[this.currentSubstep];
            
            // For queue-related steps on batch page, position near the queue
            const queueRelatedSteps = ['view_queue', 'commands_done'];
            const queueElement = document.querySelector('#batch-queue');
            
            // DIY steps where user works independently - position bottom-right to stay out of the way
            const diySteps = ['configure_favicon', 'services_challenge', 'complete_other_meta'];
            
            if (diySteps.includes(substep?.id)) {
                // Bottom-right corner, out of the way
                this.bubbleElement.style.bottom = '100px';
                this.bubbleElement.style.right = '30px';
                this.bubbleElement.style.top = 'auto';
                this.bubbleElement.style.left = 'auto';
                this.bubbleElement.style.transform = 'none';
                this.bubbleElement.className = 'tutorial-bubble';
            } else if (queueRelatedSteps.includes(substep?.id) && queueElement) {
                const queueRect = queueElement.getBoundingClientRect();
                // Position above the queue, centered
                this.bubbleElement.style.top = Math.max(100, queueRect.top - 220) + 'px';
                this.bubbleElement.style.left = (queueRect.left + queueRect.width / 2 - 160) + 'px';
                this.bubbleElement.style.right = 'auto';
                this.bubbleElement.style.bottom = 'auto';
                this.bubbleElement.className = 'tutorial-bubble arrow-bottom';
            } else {
                // Default: center horizontally, upper portion of screen
                this.bubbleElement.style.top = '150px';
                this.bubbleElement.style.left = '50%';
                this.bubbleElement.style.transform = 'translateX(-50%)';
                this.bubbleElement.style.right = 'auto';
                this.bubbleElement.style.bottom = 'auto';
                this.bubbleElement.className = 'tutorial-bubble';
            }
            return;
        }
        
        const targetRect = this.targetElement.getBoundingClientRect();
        const bubbleWidth = 320; // Approximate bubble width
        const bubbleHeight = 200; // Approximate bubble height
        const padding = 25; // Increased padding to not overlap
        
        // Determine available space
        const spaceBelow = window.innerHeight - targetRect.bottom;
        const spaceAbove = targetRect.top;
        const spaceRight = window.innerWidth - targetRect.right;
        const spaceLeft = targetRect.left;
        
        // Reset positioning
        this.bubbleElement.style.top = 'auto';
        this.bubbleElement.style.bottom = 'auto';
        this.bubbleElement.style.left = 'auto';
        this.bubbleElement.style.right = 'auto';
        this.bubbleElement.style.transform = 'none';
        
        // Get current substep for special positioning
        const step = this.steps[this.currentStep];
        const substep = step?.substeps?.[this.currentSubstep];
        
        // Determine element type for special positioning
        const isButton = this.targetElement.tagName === 'BUTTON' || 
                        this.targetElement.classList.contains('admin-btn') ||
                        this.targetElement.getAttribute('onclick');
        const isInput = this.targetElement.tagName === 'INPUT' || 
                       this.targetElement.tagName === 'TEXTAREA';
        const isSelect = this.targetElement.tagName === 'SELECT';
        const isLargeElement = targetRect.height > 100 || targetRect.width > 400;
        const isSpecCard = this.targetElement.classList.contains('admin-ai-spec-card');
        
        // For spec cards (like indicate_global_design, indicate_translate_spec): position to the side to not block the card
        if (isSpecCard || substep?.id === 'indicate_global_design' || substep?.id === 'indicate_translate_spec') {
            if (spaceRight > bubbleWidth + padding) {
                // Position right of card
                this.bubbleElement.style.top = Math.max(10, targetRect.top) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else if (spaceLeft > bubbleWidth + padding) {
                // Position left of card
                this.bubbleElement.style.top = Math.max(10, targetRect.top) + 'px';
                this.bubbleElement.style.right = (window.innerWidth - targetRect.left + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            } else {
                // Fallback: position below the card
                this.bubbleElement.style.top = (targetRect.bottom + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, targetRect.left) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-top';
            }
            return;
        }
        
        // For SELECT elements: always position to the side so dropdown is accessible
        if (isSelect) {
            if (spaceRight > bubbleWidth + padding) {
                // Position right of select
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 20) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else if (spaceLeft > bubbleWidth + padding) {
                // Position left of select
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 20) + 'px';
                this.bubbleElement.style.right = (window.innerWidth - targetRect.left + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            } else {
                // Fallback: position below but offset to the side
                this.bubbleElement.style.top = (targetRect.bottom + padding) + 'px';
                this.bubbleElement.style.right = '20px';
                this.bubbleElement.className = 'tutorial-bubble arrow-top';
            }
        }
        // For inputs/textareas and large elements: prefer above with compact mode, then sides
        else if (isInput || isLargeElement) {
            const compactHeight = 120; // Compact bubble is shorter
            const compactWidth = 500; // Compact bubble is wider
            
            // Prefer above with compact (wide/short) bubble for inputs
            if (spaceAbove > compactHeight + padding) {
                // Position above with compact mode - center on the target
                const leftPos = Math.max(10, targetRect.left + (targetRect.width / 2) - (compactWidth / 2));
                const adjustedLeft = Math.min(leftPos, window.innerWidth - compactWidth - 10);
                
                // Use top positioning instead of bottom for more predictable placement
                const topPos = targetRect.top - compactHeight - padding;
                this.bubbleElement.style.top = Math.max(10, topPos) + 'px';
                this.bubbleElement.style.left = Math.max(10, adjustedLeft) + 'px';
                this.bubbleElement.className = 'tutorial-bubble compact arrow-bottom';
            } else if (spaceRight > bubbleWidth + padding) {
                // Position right (standard mode) - align to top of target but stay in viewport
                const topPos = Math.min(targetRect.top, window.innerHeight - bubbleHeight - 20);
                this.bubbleElement.style.top = Math.max(10, topPos) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else if (spaceLeft > bubbleWidth + padding) {
                // Position left (standard mode) - align to top of target but stay in viewport
                const topPos = Math.min(targetRect.top, window.innerHeight - bubbleHeight - 20);
                this.bubbleElement.style.top = Math.max(10, topPos) + 'px';
                this.bubbleElement.style.right = (window.innerWidth - targetRect.left + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            } else {
                // Fallback: position fixed at top center with compact
                this.bubbleElement.style.top = '10px';
                this.bubbleElement.style.left = '50%';
                this.bubbleElement.style.transform = 'translateX(-50%)';
                this.bubbleElement.className = 'tutorial-bubble compact';
            }
        }
        // For buttons: prefer side positioning
        else if (isButton) {
            if (spaceRight > bubbleWidth + padding) {
                // Position right of buttons
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 20) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else if (spaceLeft > bubbleWidth + padding) {
                // Position left of buttons
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 20) + 'px';
                this.bubbleElement.style.right = (window.innerWidth - targetRect.left + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            } else if (spaceAbove > bubbleHeight + padding) {
                // Position above
                this.bubbleElement.style.bottom = (window.innerHeight - targetRect.top + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, Math.min(targetRect.left, window.innerWidth - bubbleWidth - 20)) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-bottom';
            } else if (spaceBelow > bubbleHeight + padding) {
                // Position below
                this.bubbleElement.style.top = (targetRect.bottom + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, Math.min(targetRect.left, window.innerWidth - bubbleWidth - 20)) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-top';
            } else {
                // Fallback
                this.bubbleElement.style.bottom = '20px';
                this.bubbleElement.style.right = '20px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            }
        }
        // Default positioning for other elements
        else {
            if (spaceBelow > bubbleHeight + padding) {
                // Position below
                this.bubbleElement.style.top = (targetRect.bottom + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, Math.min(targetRect.left, window.innerWidth - bubbleWidth - 20)) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-top';
            } else if (spaceAbove > bubbleHeight + padding) {
                // Position above
                this.bubbleElement.style.bottom = (window.innerHeight - targetRect.top + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, Math.min(targetRect.left, window.innerWidth - bubbleWidth - 20)) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-bottom';
            } else if (spaceRight > bubbleWidth + padding) {
                // Position right
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 50) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else {
                // Position left (fallback)
                this.bubbleElement.style.top = Math.max(10, targetRect.top - 50) + 'px';
                this.bubbleElement.style.right = (window.innerWidth - targetRect.left + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-right';
            }
        }
    }
    
    /**
     * Move to next substep
     */
    async nextSubstep() {
        const step = this.steps[this.currentStep];
        if (!step) return;
        
        const substep = step.substeps[this.currentSubstep];
        const totalSubsteps = Object.keys(step.substeps).length;
        const isLastSubstep = this.currentSubstep === totalSubsteps;
        
        // External link substeps require user to click link first, then click Finish button
        const externalLinkSubsteps = ['view_site', 'view_blank_site', 'view_starter_site'];
        if (externalLinkSubsteps.includes(substep?.id) && !this.externalLinkClicked) {
            // User hasn't clicked the external link yet - don't advance
            console.log('Tutorial: Waiting for external link click before advancing');
            return;
        }
        
        // Reset the flag
        this.externalLinkClicked = false;
        
        if (this.currentSubstep < totalSubsteps) {
            this.currentSubstep++;
            // Reset user drag position for fresh positioning on each substep
            this.userDragPosition = null;
            await this.saveProgress();
            this.showCurrentStep();
        } else {
            // End of current step - show step complete modal
            const completedStepNum = this.currentStep;
            const totalSteps = Object.keys(this.steps).length;
            
            if (this.currentStep < totalSteps) {
                // Move to next step
                this.currentStep++;
                this.currentSubstep = 1;
                // Reset user drag position for new step (fresh positioning)
                this.userDragPosition = null;
                await this.saveProgress();
                // Show step complete modal instead of immediately continuing
                this.hideAll();
                this.showStepComplete(completedStepNum);
            } else {
                // Tutorial complete!
                await this.complete();
                return;
            }
        }
    }
    
    /**
     * Move to previous substep
     */
    async previousSubstep() {
        if (this.currentSubstep > 1) {
            this.currentSubstep--;
        } else if (this.currentStep > 1) {
            this.currentStep--;
            const step = this.steps[this.currentStep];
            this.currentSubstep = Object.keys(step.substeps).length;
        }
        
        await this.saveProgress();
        this.showCurrentStep();
    }
    
    /**
     * Save progress to server
     */
    async saveProgress() {
        try {
            console.log('Tutorial: Saving progress...', {
                step: this.currentStep,
                substep: this.currentSubstep,
                status: this.status
            });
            
            // Use admin-specific tutorial API (not management API)
            const response = await fetch(`${window.QUICKSITE_CONFIG.adminBase}/tutorial-api.php?action=update`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin', // Include session cookie
                body: JSON.stringify({
                    step: this.currentStep,
                    substep: this.currentSubstep,
                    status: this.status
                })
            });
            
            const result = await response.json();
            console.log('Tutorial: Save response:', result);
            
            // Admin API returns success: true/false
            if (!result.success) {
                console.warn('Tutorial: Failed to save progress:', result.error);
            }
        } catch (error) {
            console.error('Tutorial: Save error:', error);
        }
    }
    
    /**
     * Skip tutorial
     */
    async skip() {
        this.status = 'skipped';
        this.isActive = false;
        
        await this.saveProgress();
        
        this.hideOverlay();
        if (this.overlayElement) {
            this.overlayElement.style.display = 'none';
        }
        if (this.bubbleElement) {
            this.bubbleElement.style.display = 'none';
        }
        this.showMinimized();
    }
    
    /**
     * Complete tutorial
     */
    async complete() {
        this.status = 'completed';
        this.isActive = false;
        
        // Save to both server and localStorage
        await this.saveProgress();
        this.saveLocalProgress();
        
        // Fully hide all tutorial UI
        this.hideAll();
        
        // Show completion message
        this.showCompletion();
    }
    
    /**
     * Show completion celebration
     */
    showCompletion() {
        const t = this.translations;
        
        const modal = document.createElement('div');
        modal.className = 'tutorial-welcome-modal';
        modal.id = 'tutorial-complete';
        modal.innerHTML = `
            <div class="tutorial-welcome-card">
                <div class="tutorial-welcome-header" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <div class="icon">🎉</div>
                    <h2>${t.congratulations || 'Congratulations!'}</h2>
                    <p>${t.tutorialComplete || 'You\'ve completed the QuickSite tutorial!'}</p>
                </div>
                <div class="tutorial-welcome-body">
                    <h3>${t.whatsNext || 'What\'s Next?'}</h3>
                    <ul class="tutorial-steps-preview">
                        <li><span class="step-num">💡</span><span class="step-text">${t.nextExplore || 'Explore more advanced features'}</span></li>
                        <li><span class="step-num">📖</span><span class="step-text">${t.nextDocs || 'Read the full documentation'}</span></li>
                        <li><span class="step-num">🚀</span><span class="step-text">${t.nextPublish || 'Publish your website to the world'}</span></li>
                    </ul>
                    <div class="tutorial-welcome-actions">
                        <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.dismissCompletion()">
                            ${t.startCreating || 'Start Creating!'} <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }
    
    /**
     * Dismiss completion modal and ensure all UI is hidden
     */
    dismissCompletion() {
        const modal = document.getElementById('tutorial-complete');
        if (modal) modal.remove();
        
        // Ensure status stays completed
        this.status = 'completed';
        this.isActive = false;
        this.saveLocalProgress();
        
        // Ensure everything is hidden
        this.hideAll();
    }
    
    /**
     * Minimize tutorial
     */
    minimize() {
        this.isMinimized = true;
        this.hideOverlay();
        // Also hide the overlay element completely
        if (this.overlayElement) {
            this.overlayElement.style.display = 'none';
        }
        if (this.bubbleElement) {
            this.bubbleElement.style.display = 'none';
        }
        this.showMinimized();
    }
    
    /**
     * Show minimized widget
     */
    showMinimized() {
        this.minimizedElement.style.display = 'block';
        this.updateMiniProgress();
    }
    
    /**
     * Update mini widget progress ring
     */
    updateMiniProgress() {
        const circumference = 176; // 2 * PI * 28
        const totalSteps = Object.keys(this.steps).length;
        const progress = (this.currentStep - 1) / totalSteps;
        const offset = circumference - (progress * circumference);
        
        const progressCircle = this.minimizedElement.querySelector('.progress');
        if (progressCircle) {
            progressCircle.style.strokeDashoffset = offset;
        }
    }
    
    /**
     * Restore from minimized
     */
    restore() {
        this.minimizedElement.style.display = 'none';
        this.isMinimized = false;
        
        if (this.status === 'skipped' || this.status === 'completed') {
            // Ask if they want to restart
            this.showResume();
        } else if (this.status === 'paused') {
            // Show resume modal for paused state
            this.showResume();
        } else {
            // Active state - show current step
            this.isActive = true;
            this.showCurrentStep();
        }
    }
    
    /**
     * Shake bubble to draw attention
     */
    shakeBubble() {
        this.bubbleElement.style.animation = 'none';
        setTimeout(() => {
            this.bubbleElement.style.animation = 'shake 0.5s ease';
        }, 10);
    }
}

// Create global instance
window.tutorial = new QuickSiteTutorial();

// Add shake animation
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
`;
document.head.appendChild(style);

// Auto-check for continue flag after page load
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tutorial') === 'continue') {
        // Remove the parameter from URL
        const url = new URL(window.location.href);
        url.searchParams.delete('tutorial');
        window.history.replaceState({}, '', url);
    }
});
