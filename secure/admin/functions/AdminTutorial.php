<?php
/**
 * Admin Tutorial System
 * 
 * Manages the 6-step onboarding tutorial for new QuickSite users.
 * Progress is stored server-side in auth.php token data.
 * 
 * Tutorial Steps:
 * 1. AI Integration - Create website with AI
 * 2. Build & Preview - Generate the site
 * 3. Exploring Your Site - Navigate the structure
 * 4. Quick Edits - Make simple modifications
 * 5. Add a Page - Create a new route
 * 6. Publish & Beyond - Final steps
 */

class AdminTutorial {
    private static $instance = null;
    private $onboardingConfigPath;
    private $currentToken = null;
    
    /**
     * Tutorial step definitions with sub-steps
     */
    private $tutorialSteps = [
        1 => [
            'id' => 'ai_integration',
            'title' => 'Create Your Website',
            'description' => 'Use AI to generate your website structure',
            'substeps' => [
                1 => ['id' => 'click_create', 'title' => 'Click "AI Integration"', 'focus' => '.admin-nav__link[href*="ai"]'],
                2 => ['id' => 'select_spec', 'title' => 'Select "Create Website"', 'focus' => '.admin-ai-spec-card[data-spec-id="create-website"]'],
                3 => ['id' => 'enter_goal', 'title' => 'Describe your website goal', 'focus' => '#user-goal, .user-goal-input'],
                4 => ['id' => 'preview_prompt', 'title' => 'Preview the full prompt', 'focus' => '[onclick*="previewFullPrompt"], .preview-full-btn'],
                5 => ['id' => 'copy_prompt', 'title' => 'Copy and use in your AI', 'focus' => '[onclick*="copyFullPrompt"], .copy-full-btn'],
                6 => ['id' => 'paste_response', 'title' => 'Paste AI response', 'focus' => '#ai-response, .ai-response-textarea'],
                7 => ['id' => 'apply_structure', 'title' => 'Apply the structure', 'focus' => '[onclick*="applyStructure"], .apply-structure-btn']
            ]
        ],
        2 => [
            'id' => 'build_preview',
            'title' => 'Build & Preview',
            'description' => 'Generate your website files',
            'substeps' => [
                1 => ['id' => 'go_build', 'title' => 'Go to Build page', 'focus' => '.admin-nav__link[href*="command/build"]'],
                2 => ['id' => 'click_build', 'title' => 'Click Execute', 'focus' => '.admin-btn--primary[type="submit"], .execute-btn'],
                3 => ['id' => 'preview_site', 'title' => 'Preview your site', 'focus' => '.admin-btn--ghost[target="_blank"], .preview-link']
            ]
        ],
        3 => [
            'id' => 'explore_site',
            'title' => 'Explore Your Site',
            'description' => 'Understand the structure',
            'substeps' => [
                1 => ['id' => 'go_structure', 'title' => 'Go to Structure page', 'focus' => '.admin-nav__link[href*="structure"]'],
                2 => ['id' => 'expand_route', 'title' => 'Expand a route', 'focus' => '.structure-node, .tree-item'],
                3 => ['id' => 'view_content', 'title' => 'View content block', 'focus' => '.structure-content, .content-block']
            ]
        ],
        4 => [
            'id' => 'quick_edits',
            'title' => 'Quick Edits',
            'description' => 'Make simple modifications',
            'substeps' => [
                1 => ['id' => 'find_title', 'title' => 'Find Edit Title command', 'focus' => '.admin-command-link[href*="editTitle"]'],
                2 => ['id' => 'edit_title', 'title' => 'Edit a title', 'focus' => 'input[name="title"], .title-input'],
                3 => ['id' => 'rebuild', 'title' => 'Rebuild your site', 'focus' => '.admin-nav__link[href*="command/build"]']
            ]
        ],
        5 => [
            'id' => 'add_page',
            'title' => 'Add a Page',
            'description' => 'Create a new route',
            'substeps' => [
                1 => ['id' => 'go_routes', 'title' => 'Go to Add Route', 'focus' => '.admin-command-link[href*="addRoute"]'],
                2 => ['id' => 'enter_name', 'title' => 'Enter route name', 'focus' => 'input[name="route"], .route-input'],
                3 => ['id' => 'enter_title', 'title' => 'Enter page title', 'focus' => 'input[name="title"], .page-title-input'],
                4 => ['id' => 'confirm_add', 'title' => 'Execute command', 'focus' => '.admin-btn--primary[type="submit"]'],
                5 => ['id' => 'preview_page', 'title' => 'Check structure', 'focus' => '.admin-nav__link[href*="structure"]']
            ],
            'suggestedRoute' => 'test-quicksite'
        ],
        6 => [
            'id' => 'publish_beyond',
            'title' => 'Publish & Beyond',
            'description' => 'Final steps and resources',
            'substeps' => [
                1 => ['id' => 'learn_hosting', 'title' => 'Visit Documentation', 'focus' => '.admin-nav__link[href*="docs"]'],
                2 => ['id' => 'explore_settings', 'title' => 'Explore Settings', 'focus' => '.admin-nav__link[href*="settings"]'],
                3 => ['id' => 'complete', 'title' => 'Complete tutorial!', 'focus' => null]
            ]
        ]
    ];
    
    private function __construct() {
        $this->onboardingConfigPath = dirname(__DIR__) . '/config/onboarding.php';
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Set the current token from session/auth
     */
    public function setCurrentToken($token) {
        $this->currentToken = $token;
    }
    
    /**
     * Get all tutorial steps
     */
    public function getTutorialSteps() {
        return $this->tutorialSteps;
    }
    
    /**
     * Get onboarding status for current token
     */
    public function getOnboardingStatus() {
        if (!$this->currentToken) {
            return null;
        }
        
        // Invalidate OPcache to ensure we read fresh data
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->onboardingConfigPath, true);
        }
        
        // Load onboarding config (separate from auth.php)
        $onboardingConfig = [];
        if (file_exists($this->onboardingConfigPath)) {
            $onboardingConfig = include $this->onboardingConfigPath;
        }
        
        // Get token-specific data or defaults
        $tokenData = $onboardingConfig[$this->currentToken] ?? [];
        
        return [
            'status' => $tokenData['status'] ?? 'pending',
            'step' => $tokenData['step'] ?? null,
            'substep' => $tokenData['substep'] ?? null
        ];
    }
    
    /**
     * Update onboarding progress
     */
    public function updateProgress($step, $substep = null, $status = null) {
        if (!$this->currentToken) {
            return ['success' => false, 'error' => 'No token set'];
        }
        
        // Invalidate OPcache to ensure we read fresh data
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->onboardingConfigPath, true);
        }
        
        // Load current onboarding config
        $onboardingConfig = [];
        if (file_exists($this->onboardingConfigPath)) {
            $onboardingConfig = include $this->onboardingConfigPath;
        }
        
        // Initialize token entry if not exists
        if (!isset($onboardingConfig[$this->currentToken])) {
            $onboardingConfig[$this->currentToken] = [
                'status' => 'pending',
                'step' => null,
                'substep' => null
            ];
        }
        
        // Update the values
        if ($step !== null) {
            $onboardingConfig[$this->currentToken]['step'] = $step;
        }
        if ($substep !== null) {
            $onboardingConfig[$this->currentToken]['substep'] = $substep;
        }
        if ($status !== null) {
            $onboardingConfig[$this->currentToken]['status'] = $status;
        }
        
        // Save back to file
        $content = "<?php\n/**\n * Admin Panel Onboarding/Tutorial State\n * \n * Auto-updated: " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($onboardingConfig, true) . ";\n";
        
        if (file_put_contents($this->onboardingConfigPath, $content) === false) {
            return ['success' => false, 'error' => 'Failed to save progress'];
        }
        
        // Invalidate OPcache after write
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->onboardingConfigPath, true);
        }
        
        return ['success' => true];
    }
    
    /**
     * Mark tutorial as completed
     */
    public function completeTutorial() {
        return $this->updateProgress(6, 3, 'completed');
    }
    
    /**
     * Skip tutorial
     */
    public function skipTutorial() {
        return $this->updateProgress(null, null, 'skipped');
    }
    
    /**
     * Reset tutorial to beginning
     */
    public function resetTutorial() {
        return $this->updateProgress(1, 1, 'pending');
    }
    
    /**
     * Get step info with current progress
     */
    public function getCurrentStepInfo() {
        $progress = $this->getOnboardingStatus();
        
        if (!$progress || $progress['status'] !== 'pending') {
            return null;
        }
        
        $step = $progress['step'] ?? 1;
        $substep = $progress['substep'] ?? 1;
        
        if (!isset($this->tutorialSteps[$step])) {
            return null;
        }
        
        $stepData = $this->tutorialSteps[$step];
        $substepData = $stepData['substeps'][$substep] ?? null;
        
        return [
            'step' => $step,
            'substep' => $substep,
            'totalSteps' => count($this->tutorialSteps),
            'totalSubsteps' => count($stepData['substeps']),
            'stepInfo' => $stepData,
            'substepInfo' => $substepData,
            'isFirstStep' => ($step === 1 && $substep === 1),
            'isLastStep' => ($step === 6 && $substep === 3)
        ];
    }
    
    /**
     * Advance to next substep/step
     */
    public function advanceProgress() {
        $progress = $this->getOnboardingStatus();
        
        if (!$progress || $progress['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Tutorial not active'];
        }
        
        $step = $progress['step'] ?? 1;
        $substep = $progress['substep'] ?? 1;
        
        $stepData = $this->tutorialSteps[$step] ?? null;
        if (!$stepData) {
            return $this->completeTutorial();
        }
        
        $totalSubsteps = count($stepData['substeps']);
        
        if ($substep < $totalSubsteps) {
            // Next substep
            return $this->updateProgress($step, $substep + 1);
        } else {
            // Next step
            if ($step < count($this->tutorialSteps)) {
                return $this->updateProgress($step + 1, 1);
            } else {
                // Tutorial complete
                return $this->completeTutorial();
            }
        }
    }
    
    /**
     * Check if a route exists (for step 5)
     */
    public function checkRouteExists($routeName) {
        $routesPath = dirname(__DIR__, 2) . '/routes.php';
        
        if (!file_exists($routesPath)) {
            return false;
        }
        
        $routes = include $routesPath;
        return isset($routes[$routeName]);
    }
    
    /**
     * Get suggested route name that doesn't exist
     */
    public function getSuggestedRouteName() {
        $suggestions = ['test-quicksite', 'my-new-page', 'demo-page', 'tutorial-page'];
        
        foreach ($suggestions as $suggestion) {
            if (!$this->checkRouteExists($suggestion)) {
                return $suggestion;
            }
        }
        
        // Generate unique name
        $i = 1;
        while ($this->checkRouteExists('new-page-' . $i)) {
            $i++;
        }
        
        return 'new-page-' . $i;
    }
    
    /**
     * Export tutorial data for JavaScript
     */
    public function exportForJs() {
        $progress = $this->getOnboardingStatus();
        $currentStep = $this->getCurrentStepInfo();
        
        return [
            'status' => $progress['status'] ?? 'pending',
            'currentStep' => $progress['step'] ?? 1,
            'currentSubstep' => $progress['substep'] ?? 1,
            'steps' => $this->tutorialSteps,
            'stepInfo' => $currentStep,
            'suggestedRoute' => $this->getSuggestedRouteName()
        ];
    }
}

/**
 * Helper function to get Tutorial instance
 */
function getTutorial() {
    return AdminTutorial::getInstance();
}
