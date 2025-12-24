<?php
/**
 * Admin Tutorial System
 * 
 * Manages the 3-step onboarding tutorial for new QuickSite users.
 * Progress is stored server-side in auth.php token data.
 * 
 * Tutorial Steps:
 * 1. AI Integration - Create website with AI
 * 2. Batch Basics - Learn batch operations with templates
 * 3. Understanding Commands - Learn how commands are structured
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
                3 => ['id' => 'enter_goal', 'title' => 'Describe your website goal', 'focus' => '#user-goal'],
                4 => ['id' => 'preview_prompt', 'title' => 'Preview the full prompt', 'focus' => '[onclick*="previewFullPrompt"]'],
                5 => ['id' => 'copy_prompt', 'title' => 'Copy the prompt', 'focus' => '[onclick*="copyFullPrompt"]'],
                6 => ['id' => 'use_ai_chatbot', 'title' => 'Use your AI chatbot', 'focus' => '.admin-ai-import-hint'],
                7 => ['id' => 'paste_response', 'title' => 'Paste AI response', 'focus' => '#import-json'],
                8 => ['id' => 'apply_structure', 'title' => 'Apply the structure', 'focus' => '#execute-btn'],
                9 => ['id' => 'view_site', 'title' => 'View your site', 'focus' => '#back-to-site-btn']
            ]
        ],
        2 => [
            'id' => 'batch_basics',
            'title' => 'Batch Basics',
            'description' => 'Learn batch operations with templates',
            'substeps' => [
                1 => ['id' => 'go_batch', 'title' => 'Go to Batch page', 'focus' => '.admin-nav__link[href*="batch"]'],
                2 => ['id' => 'select_fresh_start', 'title' => 'Find "Fresh Start" template', 'focus' => '.admin-template[data-template="fresh-start"] .admin-template__title'],
                3 => ['id' => 'generate_fresh_start', 'title' => 'Generate & Load', 'focus' => '.admin-template[data-template="fresh-start"] .admin-btn--primary'],
                4 => ['id' => 'execute_fresh_start', 'title' => 'Execute & Clear Queue', 'focus' => '#batch-controls .admin-btn--primary'],
                5 => ['id' => 'view_blank_site', 'title' => 'View blank site', 'focus' => '#back-to-site-btn'],
                6 => ['id' => 'select_starter_multilingual', 'title' => 'Select "Starter Business (Multilingual)"', 'focus' => '.admin-template[data-template="starter-business-multilingual"] .admin-template__title'],
                7 => ['id' => 'load_starter', 'title' => 'Load template', 'focus' => '.admin-template[data-template="starter-business-multilingual"] .admin-btn--primary'],
                8 => ['id' => 'execute_starter', 'title' => 'Execute & Clear Queue', 'focus' => '#batch-controls .admin-btn--primary'],
                9 => ['id' => 'view_starter_site', 'title' => 'View your new site', 'focus' => '#back-to-site-btn']
            ]
        ],
        3 => [
            'id' => 'understanding_commands',
            'title' => 'Understanding Commands',
            'description' => 'Learn how commands are structured',
            'substeps' => [
                1 => ['id' => 'select_starter_again', 'title' => 'Select "Starter Business (Multilingual)"', 'focus' => '.admin-template[data-template="starter-business-multilingual"] .admin-template__title'],
                2 => ['id' => 'preview_template', 'title' => 'Click Preview', 'focus' => '.admin-template[data-template="starter-business-multilingual"] .admin-btn--secondary'],
                3 => ['id' => 'understand_structure', 'title' => 'Understand command structure', 'focus' => '#template-preview-content'],
                4 => ['id' => 'load_to_queue', 'title' => 'Load to queue', 'focus' => '.admin-template[data-template="starter-business-multilingual"] .admin-btn--primary'],
                5 => ['id' => 'view_queue', 'title' => 'View the queue', 'focus' => '#batch-queue'],
                6 => ['id' => 'clear_queue', 'title' => 'Clear All (don\'t execute)', 'focus' => '.admin-card__actions [onclick*="clearQueue"]'],
                7 => ['id' => 'commands_done', 'title' => 'Commands understood!', 'focus' => null]
            ]
        ],
        4 => [
            'id' => 'understanding_structure',
            'title' => 'Understanding Structure',
            'description' => 'Learn how page structures work',
            'substeps' => [
                1 => ['id' => 'go_structure', 'title' => 'Go to Structure page', 'focus' => '.admin-nav__link[href*="structure"]'],
                2 => ['id' => 'select_menu_type', 'title' => 'Select "Menu" type', 'focus' => '#structure-type'],
                3 => ['id' => 'load_menu_structure', 'title' => 'Load Structure', 'focus' => '#load-structure'],
                4 => ['id' => 'expand_menu', 'title' => 'Expand All', 'focus' => '[onclick="expandAll()"]'],
                5 => ['id' => 'observe_menu', 'title' => 'Observe the menu structure', 'focus' => '#structure-tree'],
                6 => ['id' => 'select_page_type', 'title' => 'Select "Page" type', 'focus' => '#structure-type'],
                7 => ['id' => 'select_home_page', 'title' => 'Select "home" page', 'focus' => '#structure-name'],
                8 => ['id' => 'load_page_structure', 'title' => 'Load Structure', 'focus' => '#load-structure'],
                9 => ['id' => 'expand_page', 'title' => 'Expand All', 'focus' => '[onclick="expandAll()"]'],
                10 => ['id' => 'understand_colors', 'title' => 'Understand the colors', 'focus' => '#structure-tree'],
                11 => ['id' => 'structure_done', 'title' => 'Tutorial complete!', 'focus' => null]
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
        
        // Allow both 'pending' (legacy) and 'active' statuses
        if (!$progress || !in_array($progress['status'], ['pending', 'active', 'paused'])) {
            return null;
        }
        
        $step = $progress['step'] ?? 1;
        $substep = $progress['substep'] ?? 1;
        
        if (!isset($this->tutorialSteps[$step])) {
            return null;
        }
        
        $stepData = $this->tutorialSteps[$step];
        $substepData = $stepData['substeps'][$substep] ?? null;
        $totalSteps = count($this->tutorialSteps);
        $lastStepSubsteps = count($this->tutorialSteps[$totalSteps]['substeps']);
        
        return [
            'step' => $step,
            'substep' => $substep,
            'totalSteps' => $totalSteps,
            'totalSubsteps' => count($stepData['substeps']),
            'stepInfo' => $stepData,
            'substepInfo' => $substepData,
            'isFirstStep' => ($step === 1 && $substep === 1),
            'isLastStep' => ($step === $totalSteps && $substep === $lastStepSubsteps)
        ];
    }
    
    /**
     * Advance to next substep/step
     */
    public function advanceProgress() {
        $progress = $this->getOnboardingStatus();
        
        // Allow both 'pending' (legacy) and 'active' statuses
        if (!$progress || !in_array($progress['status'], ['pending', 'active'])) {
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
