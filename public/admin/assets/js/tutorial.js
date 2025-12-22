/**
 * QuickSite Tutorial System
 * Floating guide with focus block overlay
 */

class QuickSiteTutorial {
    constructor() {
        this.isActive = false;
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
        
        // Translations (will be set from PHP)
        this.translations = window.TUTORIAL_TRANSLATIONS || {};
    }
    
    /**
     * Initialize from server data
     */
    init(data) {
        if (!data) return;
        
        // Check for pending localStorage sync (from beacon saves during navigation)
        this.syncFromLocalStorage();
        
        this.status = data.status || 'pending';
        this.currentStep = data.currentStep || 1;
        this.currentSubstep = data.currentSubstep || 1;
        this.steps = data.steps || {};
        this.stepInfo = data.stepInfo;
        this.suggestedRoute = data.suggestedRoute || 'test-quicksite';
        
        // Check if localStorage has more recent progress
        const localProgress = this.getLocalProgress();
        if (localProgress && localProgress.step && localProgress.substep) {
            // Compare: localStorage might be ahead if beacon save was pending
            const localTotal = (localProgress.step - 1) * 10 + localProgress.substep;
            const serverTotal = (this.currentStep - 1) * 10 + this.currentSubstep;
            
            if (localTotal > serverTotal) {
                console.log('Tutorial: Using localStorage progress (ahead of server)', localProgress);
                this.currentStep = localProgress.step;
                this.currentSubstep = localProgress.substep;
                this.status = localProgress.status || this.status;
                // Sync to server
                this.saveProgress();
            }
        }
        
        // Create base elements (but keep them hidden)
        this.createOverlay();
        this.createBubble();
        this.createMinimizedWidget();
        
        // IMPORTANT: Hide everything initially
        this.hideAll();
        
        // Check if we should start
        if (this.status === 'pending') {
            // Check if step/substep is null (never started) vs just step 1 substep 1
            const neverStarted = (data.currentStep === null || data.currentStep === 1) && 
                                 (data.currentSubstep === null || data.currentSubstep === 1);
            
            if (neverStarted && !this.hasStartedBefore()) {
                // First time - show welcome only (no bubble/overlay yet)
                this.showWelcome();
            } else if (this.hasStartedBefore()) {
                // Already started - auto-resume without modal (they're in the middle of the tutorial)
                this.isActive = true;
                this.showCurrentStep();
            } else {
                // Not started but has progress somehow - show resume
                this.showResume();
            }
        } else if (this.status === 'skipped' || this.status === 'completed') {
            // Show minimized widget for restart option
            this.showMinimized();
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
        
        this.bubbleElement = document.createElement('div');
        this.bubbleElement.className = 'tutorial-bubble arrow-top';
        this.bubbleElement.innerHTML = `
            <div class="tutorial-bubble-header">
                <h4>
                    <span class="step-badge">Step <span class="step-num">1</span>/6</span>
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
                        <li><span class="step-num">2</span><span class="step-text">${t.step2Preview || 'Build and preview your site'}</span></li>
                        <li><span class="step-num">3</span><span class="step-text">${t.step3Preview || 'Explore the structure'}</span></li>
                        <li><span class="step-num">4</span><span class="step-text">${t.step4Preview || 'Make quick edits'}</span></li>
                        <li><span class="step-num">5</span><span class="step-text">${t.step5Preview || 'Add a new page'}</span></li>
                        <li><span class="step-num">6</span><span class="step-text">${t.step6Preview || 'Learn about publishing'}</span></li>
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
                    <div class="tutorial-progress-summary" style="margin-bottom: 20px;">
                        <p style="font-size: 1.1rem;">
                            <strong>Step ${this.currentStep}</strong>: ${stepTitle}
                        </p>
                        <div class="tutorial-progress" style="justify-content: flex-start; gap: 8px; padding: 12px 0;">
                            ${this.renderProgressDots()}
                        </div>
                    </div>
                    <div class="tutorial-welcome-actions">
                        <button class="tutorial-btn tutorial-btn-primary" onclick="tutorial.resumeTutorial()">
                            ${t.continueTutorial || 'Continue Tutorial'} <i class="bi bi-arrow-right"></i>
                        </button>
                        <button class="tutorial-btn tutorial-btn-secondary" onclick="tutorial.restartTutorial()">
                            ${t.startOver || 'Start Over'}
                        </button>
                        <button class="tutorial-btn tutorial-btn-skip" onclick="tutorial.skipFromWelcome()">
                            ${t.skipForNow || 'Skip for now'}
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
     * Start the tutorial
     */
    async startTutorial() {
        // Close welcome modal
        const welcome = document.getElementById('tutorial-welcome');
        if (welcome) welcome.remove();
        
        // Mark as started in localStorage
        this.markAsStarted();
        
        // Initialize first step
        this.currentStep = 1;
        this.currentSubstep = 1;
        this.status = 'pending';
        this.isActive = true;
        
        // Save progress FIRST (so refresh won't show welcome again)
        await this.saveProgress();
        
        // Apply fresh start (reset structure)
        await this.applyFreshStart();
        
        // Now show the tutorial UI
        this.showCurrentStep();
    }
    
    /**
     * Resume tutorial
     */
    resumeTutorial() {
        const modal = document.getElementById('tutorial-resume');
        if (modal) modal.remove();
        
        this.isActive = true;
        this.showCurrentStep();
    }
    
    /**
     * Restart tutorial from beginning
     */
    async restartTutorial() {
        const modal = document.getElementById('tutorial-resume');
        if (modal) modal.remove();
        
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
        
        // Update progress dots
        const totalSubsteps = Object.keys(step.substeps).length;
        const progressHtml = this.renderSubstepProgress(totalSubsteps);
        this.bubbleElement.querySelector('.tutorial-progress').innerHTML = progressHtml;
        
        // Update buttons
        const prevBtn = this.bubbleElement.querySelector('.tutorial-btn-secondary');
        prevBtn.style.display = (this.currentStep === 1 && this.currentSubstep === 1) ? 'none' : 'inline-flex';
    }
    
    /**
     * Render substep progress dots
     */
    renderSubstepProgress(total) {
        let html = '';
        for (let i = 1; i <= total; i++) {
            let cls = 'tutorial-progress-dot';
            if (i < this.currentSubstep) cls += ' completed';
            if (i === this.currentSubstep) cls += ' current';
            html += `<span class="${cls}"></span>`;
        }
        return html;
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
            'click_create': 'Click on "AI Integration" in the sidebar to get started.',
            'select_spec': 'Choose "Create Website" to generate a complete multi-page website.',
            'enter_goal': 'Describe what kind of website you want to create. Be specific about your needs.',
            'view_examples': 'Browse the example websites to get inspired. You can use any of them as a starting point!',
            'preview_prompt': 'Click "Preview Full" to see the complete prompt that will be sent to your AI.',
            'copy_prompt': 'Copy the full prompt and paste it into your favorite AI chatbot (ChatGPT, Claude, etc.)',
            'paste_response': 'Paste the JSON response from the AI into the response field.',
            'apply_structure': 'Click to save your new website configuration.',
            'go_build': 'Navigate to the Build command to generate your website files.',
            'click_build': 'Click Execute to compile your website.',
            'preview_site': 'Open your website in a new tab to see the result!',
            'go_structure': 'Go to the Structure page to see your website organization.',
            'expand_route': 'Click on a route to expand and see its content sections.',
            'view_content': 'Explore the different content blocks that make up your page.',
            'find_title': 'Find the Edit Title command to modify page titles.',
            'edit_title': 'Enter a new title for your page.',
            'rebuild': 'Go back to Build to regenerate your site with changes.',
            'go_routes': 'Find the Add Route command to create a new page.',
            'enter_name': `Enter a route name like "${this.suggestedRoute || 'test-quicksite'}".`,
            'enter_title': 'Give your new page a title.',
            'confirm_add': 'Execute the command to create your route.',
            'preview_page': 'Check the Structure page to see your new route!',
            'learn_hosting': 'Visit the Documentation page to learn more.',
            'explore_settings': 'Explore the Settings page for configuration options.',
            'complete': 'Congratulations! You\'ve completed the tutorial! 🎉'
        };
        
        return descriptions[substep.id] || substep.title;
    }
    
    /**
     * Ensure we're on the correct page for current step
     */
    ensureCorrectPage() {
        const pageMap = {
            1: 'ai',
            2: 'build',
            3: 'structure',
            4: 'structure',
            5: 'structure',
            6: 'settings'
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
        
        // Scroll target into view if needed
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    /**
     * Handle click on target element
     */
    async onTargetClick(event, target) {
        // Check if target is a link that will navigate
        const isLink = target.tagName === 'A' && target.href;
        const isInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA';
        
        if (isLink) {
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
            // Not a link or input, advance after a short delay
            setTimeout(() => {
                this.nextSubstep();
            }, 300);
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
        const isLargeElement = targetRect.height > 100 || targetRect.width > 400;
        
        // For inputs/textareas and large elements: prefer above with compact mode, then sides
        if (isInput || isLargeElement) {
            const compactHeight = 120; // Compact bubble is shorter
            const compactWidth = 500; // Compact bubble is wider
            
            // Prefer above with compact (wide/short) bubble for inputs
            if (spaceAbove > compactHeight + padding) {
                // Position above with compact mode - center on the target
                const leftPos = Math.max(10, targetRect.left + (targetRect.width / 2) - (compactWidth / 2));
                const adjustedLeft = Math.min(leftPos, window.innerWidth - compactWidth - 10);
                
                this.bubbleElement.style.bottom = (window.innerHeight - targetRect.top + padding) + 'px';
                this.bubbleElement.style.left = Math.max(10, adjustedLeft) + 'px';
                this.bubbleElement.className = 'tutorial-bubble compact arrow-bottom';
            } else if (spaceRight > bubbleWidth + padding) {
                // Position right (standard mode)
                this.bubbleElement.style.top = Math.max(10, targetRect.top) + 'px';
                this.bubbleElement.style.left = (targetRect.right + padding) + 'px';
                this.bubbleElement.className = 'tutorial-bubble arrow-left';
            } else if (spaceLeft > bubbleWidth + padding) {
                // Position left (standard mode)
                this.bubbleElement.style.top = Math.max(10, targetRect.top) + 'px';
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
        
        const totalSubsteps = Object.keys(step.substeps).length;
        
        if (this.currentSubstep < totalSubsteps) {
            this.currentSubstep++;
        } else {
            // Move to next step
            if (this.currentStep < 6) {
                this.currentStep++;
                this.currentSubstep = 1;
            } else {
                // Tutorial complete!
                await this.complete();
                return;
            }
        }
        
        await this.saveProgress();
        this.showCurrentStep();
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
        
        await this.saveProgress();
        
        this.hideOverlay();
        this.bubbleElement.style.display = 'none';
        
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
                        <button class="tutorial-btn tutorial-btn-primary" onclick="document.getElementById('tutorial-complete').remove()">
                            ${t.startCreating || 'Start Creating!'} <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
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
        const progress = (this.currentStep - 1) / 6;
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
        this.isActive = true;
        
        if (this.status === 'skipped' || this.status === 'completed') {
            // Ask if they want to restart
            this.showResume();
        } else {
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
