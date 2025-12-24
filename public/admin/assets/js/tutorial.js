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
        
        // Check if localStorage has more recent progress (for navigation scenarios)
        const localProgress = this.getLocalProgress();
        if (localProgress && localProgress.step && localProgress.substep) {
            const localTotal = (localProgress.step - 1) * 10 + localProgress.substep;
            const serverTotal = (this.currentStep - 1) * 10 + this.currentSubstep;
            
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
                        <li><span class="step-num">1</span><span class="step-text">${t.step1Preview || 'Create a website with AI'}</span></li>
                        <li><span class="step-num">2</span><span class="step-text">${t.step2Preview || 'Batch Basics'}</span></li>
                        <li><span class="step-num">3</span><span class="step-text">${t.step3Preview || 'Understanding Commands'}</span></li>
                        <li><span class="step-num">4</span><span class="step-text">${t.step4Preview || 'Understanding Structure'}</span></li>
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
        const stepTitles = [
            t.step1Preview || 'Create a website with AI',
            t.step2Preview || 'Batch Basics',
            t.step3Preview || 'Understanding Commands',
            t.step4Preview || 'Understanding Structure'
        ];
        
        let html = '';
        for (let i = 1; i <= 4; i++) {
            const isCompleted = i <= completedUpTo;
            const isCurrent = i === completedUpTo + 1;
            let cls = isCompleted ? 'completed' : '';
            if (isCurrent) cls += ' current';
            
            html += `<li class="${cls}">`;
            if (isCompleted) {
                html += `<span class="step-num completed"><i class="bi bi-check"></i></span>`;
            } else {
                html += `<span class="step-num">${i}</span>`;
            }
            html += `<span class="step-text">${stepTitles[i-1]}</span></li>`;
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
    continueAfterStepComplete() {
        const modal = document.getElementById('tutorial-step-complete');
        if (modal) modal.remove();
        
        this.isActive = true;
        this.status = 'active';
        this.saveProgress();
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
        
        // Make overlay element visible (but not active yet)
        if (this.overlayElement) {
            this.overlayElement.style.display = 'block';
        }
        
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
            'expand_page'            // Click Expand All
        ];
        return clickRequired.includes(substep.id);
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
            'structure_done': '🎉 <strong>Tutorial Complete!</strong> You now understand QuickSite! Use <strong>AI Integration</strong> for custom sites, <strong>Batch</strong> for templates, and <strong>Structure</strong> to visualize your pages. Happy building!'
        };
        
        return descriptions[substep.id] || substep.title;
    }
    
    /**
     * Ensure we're on the correct page for current step
     * Only redirects if tutorial is actively running
     */
    ensureCorrectPage() {
        // Don't redirect if tutorial is not active
        if (!this.isActive || this.status !== 'active') {
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
        
        // Add click listener to target for auto-advance
        // Use capture phase to catch the click before navigation
        target.addEventListener('click', (e) => this.onTargetClick(e, target), { once: true, capture: true });
        
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
            this.currentSubstep++;
            const step = this.steps[this.currentStep];
            if (this.currentSubstep > Object.keys(step.substeps).length) {
                this.currentStep++;
                this.currentSubstep = 1;
            }
            
            // Use sendBeacon for reliable saving during navigation
            // sendBeacon is designed to send data even when page is unloading
            this.saveProgressBeacon();
            
            // Let the link navigate naturally
        } else if (isSelect) {
            // For select elements, wait for change event
            const handleSelectChange = () => {
                target.removeEventListener('change', handleSelectChange);
                setTimeout(() => {
                    this.nextSubstep();
                }, 300);
            };
            target.addEventListener('change', handleSelectChange);
        } else if (isInput) {
            // For input/textarea, wait for actual input and then blur
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
            // Center on screen if no target
            this.bubbleElement.style.bottom = '100px';
            this.bubbleElement.style.right = '30px';
            this.bubbleElement.style.top = 'auto';
            this.bubbleElement.style.left = 'auto';
            this.bubbleElement.className = 'tutorial-bubble arrow-right';
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
        
        // Determine element type for special positioning
        const isButton = this.targetElement.tagName === 'BUTTON' || 
                        this.targetElement.classList.contains('admin-btn') ||
                        this.targetElement.getAttribute('onclick');
        const isInput = this.targetElement.tagName === 'INPUT' || 
                       this.targetElement.tagName === 'TEXTAREA';
        const isSelect = this.targetElement.tagName === 'SELECT';
        const isLargeElement = targetRect.height > 100 || targetRect.width > 400;
        
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
