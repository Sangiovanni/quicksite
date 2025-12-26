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
                5 => ['id' => 'view_queue', 'title' => 'View the queue', 'focus' => null],
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
                10 => ['id' => 'understand_colors', 'title' => 'Understand the colors', 'focus' => null],
                11 => ['id' => 'structure_done', 'title' => 'Step complete!', 'focus' => null]
            ]
        ],
        5 => [
            'id' => 'assets_management',
            'title' => 'Assets Management',
            'description' => 'Upload and manage images',
            'substeps' => [
                1 => ['id' => 'download_samples', 'title' => 'Download sample images', 'focus' => null],
                2 => ['id' => 'go_commands', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                3 => ['id' => 'find_upload_asset', 'title' => 'Find uploadAsset', 'focus' => '#command-search'],
                // Note: click_upload_asset removed - auto-advance from search if user clicks command directly
                4 => ['id' => 'select_images_category', 'title' => 'Select "images" category', 'focus' => '[name="category"]'],
                5 => ['id' => 'select_files', 'title' => 'Select your 4 images', 'focus' => '.admin-file-input, [name="files[]"]'],
                6 => ['id' => 'execute_upload', 'title' => 'Execute upload', 'focus' => '#submit-btn'],
                7 => ['id' => 'view_upload_result', 'title' => 'View uploaded images', 'focus' => null],
                8 => ['id' => 'go_commands_meta', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                9 => ['id' => 'find_update_meta', 'title' => 'Find updateAssetMeta', 'focus' => '#command-search'],
                // Note: click_update_meta removed - auto-advance from search if user clicks command directly
                10 => ['id' => 'select_meta_category', 'title' => 'Select "images" category', 'focus' => '[name="category"]'],
                // Guided favicon meta substeps
                11 => ['id' => 'select_favicon_meta', 'title' => 'Select favicon-business.png', 'focus' => '[name="filename"]'],
                12 => ['id' => 'enter_favicon_description', 'title' => 'Enter description', 'focus' => '[name="description"]'],
                13 => ['id' => 'enter_favicon_alt', 'title' => 'Enter alt text', 'focus' => '[name="alt"]'],
                14 => ['id' => 'execute_favicon_meta', 'title' => 'Execute command', 'focus' => '#submit-btn'],
                15 => ['id' => 'complete_other_meta', 'title' => 'Complete other images', 'focus' => null],
                16 => ['id' => 'go_commands_list', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                17 => ['id' => 'find_list_assets', 'title' => 'Find listAssets', 'focus' => '#command-search'],
                // Note: click_list_assets removed - auto-advance from search if user clicks command directly
                18 => ['id' => 'verify_assets', 'title' => 'Verify your assets', 'focus' => null],
                19 => ['id' => 'assets_done', 'title' => 'Assets uploaded!', 'focus' => null]
            ]
        ],
        6 => [
            'id' => 'edit_favicon',
            'title' => 'Change Favicon',
            'description' => 'Set your website favicon',
            'substeps' => [
                1 => ['id' => 'go_commands_favicon', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                2 => ['id' => 'find_edit_favicon', 'title' => 'Find editFavicon', 'focus' => '#command-search'],
                3 => ['id' => 'configure_favicon', 'title' => 'Configure favicon', 'focus' => null],
                4 => ['id' => 'view_favicon', 'title' => 'Check your favicon', 'focus' => null],
                5 => ['id' => 'favicon_complete', 'title' => 'Favicon changed!', 'focus' => null]
            ]
        ],
        7 => [
            'id' => 'edit_structure',
            'title' => 'Edit Structure',
            'description' => 'Add images to your website',
            'substeps' => [
                // Part 1: Add logo to menu (guided)
                1 => ['id' => 'go_commands_logo', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                2 => ['id' => 'find_edit_structure', 'title' => 'Find editStructure', 'focus' => '#command-search'],
                3 => ['id' => 'select_type_menu', 'title' => 'Select type: menu', 'focus' => '[name="type"]'],
                4 => ['id' => 'select_element_logo', 'title' => 'Select element 0.0.0', 'focus' => '[name="nodeId"]'],
                5 => ['id' => 'select_insert_before', 'title' => 'Select "Add sibling before"', 'focus' => '[name="action"]'],
                6 => ['id' => 'enter_logo_json', 'title' => 'Enter logo JSON', 'focus' => '[name="structure"]'],
                7 => ['id' => 'execute_logo', 'title' => 'Execute command', 'focus' => '#submit-btn'],
                8 => ['id' => 'view_logo', 'title' => 'Check logo', 'focus' => null],
                // Part 2: Add image to home page (guided)
                9 => ['id' => 'go_commands_home', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                10 => ['id' => 'find_edit_structure_home', 'title' => 'Find editStructure', 'focus' => '#command-search'],
                11 => ['id' => 'select_type_page', 'title' => 'Select type: page', 'focus' => '[name="type"]'],
                12 => ['id' => 'select_page_home', 'title' => 'Select page: home', 'focus' => '[name="name"]'],
                13 => ['id' => 'select_element_home', 'title' => 'Select an element', 'focus' => '[name="nodeId"]'],
                14 => ['id' => 'select_action_home', 'title' => 'Select action', 'focus' => '[name="action"]'],
                15 => ['id' => 'enter_home_json', 'title' => 'Enter image JSON', 'focus' => '[name="structure"]'],
                16 => ['id' => 'execute_home', 'title' => 'Execute command', 'focus' => '#submit-btn'],
                17 => ['id' => 'view_home', 'title' => 'Check home page', 'focus' => null],
                // Part 3: Add image to services page (DIY - user does it independently)
                18 => ['id' => 'services_challenge', 'title' => 'Services page challenge', 'focus' => null],
                19 => ['id' => 'structure_complete', 'title' => 'Structure edited!', 'focus' => null]
            ]
        ],
        8 => [
            'id' => 'css_styling',
            'title' => 'CSS Styling',
            'description' => 'Style your website with CSS',
            'substeps' => [
                // Navigate to editStyles
                1 => ['id' => 'go_commands_css', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                2 => ['id' => 'find_edit_styles', 'title' => 'Find editStyles', 'focus' => '#command-search'],
                // Discover CSS
                3 => ['id' => 'view_css_textarea', 'title' => 'View CSS editor', 'focus' => '[name="content"]'],
                4 => ['id' => 'understand_css_categories', 'title' => 'Understand CSS structure', 'focus' => null],
                // Style the logo (includes execute)
                5 => ['id' => 'add_logo_css', 'title' => 'Style the logo', 'focus' => '[name="content"]'],
                // Style hero image (includes execute)
                6 => ['id' => 'add_hero_css', 'title' => 'Style hero image', 'focus' => '[name="content"]'],
                // Style services image (includes execute)
                7 => ['id' => 'add_services_css', 'title' => 'Style services image', 'focus' => '[name="content"]'],
                // Experiment freely
                8 => ['id' => 'css_experiment', 'title' => 'Experiment with CSS', 'focus' => null],
                9 => ['id' => 'css_complete', 'title' => 'CSS styling done!', 'focus' => null]
            ]
        ],
        9 => [
            'id' => 'theme_customization',
            'title' => 'Theme Customization',
            'description' => 'Use AI to customize your theme',
            'substeps' => [
                // Discover root variables vs full CSS
                1 => ['id' => 'find_edit_styles_root', 'title' => 'Discover :root in editStyles', 'focus' => '.admin-command-item[data-command="editStyles"]'],
                2 => ['id' => 'find_get_root_variables', 'title' => 'Find getRootVariables', 'focus' => '#command-search'],
                3 => ['id' => 'execute_get_root_variables', 'title' => 'Execute getRootVariables', 'focus' => '#submit-btn'],
                4 => ['id' => 'find_set_root_variables', 'title' => 'Find setRootVariables', 'focus' => '.admin-command-item[data-command="setRootVariables"]'],
                // AI Integration
                5 => ['id' => 'go_ai_integration', 'title' => 'Go to AI Integration', 'focus' => '.admin-nav__link[href*="ai"]'],
                6 => ['id' => 'indicate_global_design', 'title' => 'Find Global Design Rework', 'focus' => '.admin-ai-spec-card[data-spec-id="global-design"]'],
                7 => ['id' => 'select_global_design', 'title' => 'Select Global Design Rework', 'focus' => '.admin-ai-spec-card[data-spec-id="global-design"]'],
                8 => ['id' => 'prepare_prompt', 'title' => 'Craft your design request', 'focus' => '#user-goal'],
                9 => ['id' => 'copy_prompt', 'title' => 'Copy for your AI', 'focus' => '[onclick*="copyFullPrompt"]'],
                // Execute and experiment
                10 => ['id' => 'execute_ai_result', 'title' => 'Apply the transformation', 'focus' => '#import-json'],
                11 => ['id' => 'theme_experiment', 'title' => 'Try another style?', 'focus' => null]
            ]
        ],
        10 => [
            'id' => 'add_languages',
            'title' => 'Add Languages',
            'description' => 'Add a new language to your website',
            'substeps' => [
                // Discover language commands
                1 => ['id' => 'go_commands_lang', 'title' => 'Go to Commands', 'focus' => '.admin-nav__link[href*="command"]'],
                2 => ['id' => 'find_language_section', 'title' => 'Find Language Section', 'focus' => '.admin-command-category[data-category="language"]'],
                3 => ['id' => 'understand_lang_commands', 'title' => 'Understand Language Commands', 'focus' => null],
                // Add a new language
                4 => ['id' => 'find_add_lang', 'title' => 'Find addLang', 'focus' => '.admin-command-item[data-command="addLang"]'],
                5 => ['id' => 'add_spanish_lang', 'title' => 'Add Spanish language', 'focus' => '[name="code"]'],
                6 => ['id' => 'verify_lang_list', 'title' => 'Verify with getLangList', 'focus' => null],
                // Add language link to footer
                7 => ['id' => 'check_footer_structure', 'title' => 'Check footer structure', 'focus' => null],
                8 => ['id' => 'add_footer_lang_link', 'title' => 'Add Spanish to footer', 'focus' => '[name="structure"]'],
                9 => ['id' => 'view_website_lang', 'title' => 'Check your website', 'focus' => null],
                10 => ['id' => 'lang_complete', 'title' => 'Languages done!', 'focus' => null]
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
        // Update to last step's final substep when adding new steps
        return $this->updateProgress(8, 9, 'completed');
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
