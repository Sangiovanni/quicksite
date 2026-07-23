<?php
/**
 * WorkflowManager - Manages workflow specification loading, validation, and rendering
 * 
 * This class handles:
 * - Loading workflow JSON files from the core/ directory
 * - Validating workflows against the JSON schema
 * - Fetching data requirements via CommandRunner
 * - Rendering prompt templates with variable substitution
 * - Generating steps from forEach definitions
 * 
 * @package QuickSite\Admin
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CommandRunner.php';

class WorkflowManager {
    
    /** @var string Base path for workflows */
    private string $workflowsBasePath;
    
    /** @var array Cached workflows */
    private array $workflowsCache = [];
    
    /** @var string Project ID */
    private string $projectId;
    
    /** @var array|null Token info for role-based permission checks (optional) */
    private ?array $tokenInfo = null;
    
    /** @var array Per-render help cache: [commandName => helpData]. Lifetime = one renderPrompt() call. */
    private array $helpCache = [];
    
    /** Max recursion depth for {{> partial}} resolution (cycle/runaway guard). */
    private const PARTIALS_MAX_DEPTH = 5;
    
    /** @var array Language name mappings */
    private static array $languageNames = [
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'ru' => 'Русский',
        'zh' => '中文',
        'ja' => '日本語',
        'ko' => '한국어',
        'ar' => 'العربية',
        'pl' => 'Polski',
        'sv' => 'Svenska',
        'da' => 'Dansk',
        'fi' => 'Suomi',
        'no' => 'Norsk',
        'cs' => 'Čeština',
        'tr' => 'Türkçe',
        'el' => 'Ελληνικά',
        'he' => 'עברית',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
        'id' => 'Bahasa Indonesia',
        'ms' => 'Bahasa Melayu',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'uk' => 'Українська',
        'ro' => 'Română',
        'hu' => 'Magyar',
        'ca' => 'Català',
        'bg' => 'Български',
        'hr' => 'Hrvatski',
        'sk' => 'Slovenčina',
        'sl' => 'Slovenščina',
        'sr' => 'Српски',
        'et' => 'Eesti',
        'lv' => 'Latviešu',
        'lt' => 'Lietuvių'
    ];
    
    /**
     * Constructor
     * 
     * @param string $projectId Project identifier (optional)
     */
    public function __construct(string $projectId = '') {
        $this->projectId = $projectId;
        $this->workflowsBasePath = SECURE_FOLDER_PATH . '/admin/workflows';
    }
    
    /**
     * Set the token info for role-based permission checking.
     * When set, fetchDataRequirements will verify each command is allowed
     * for the user's role before executing via CommandRunner.
     * 
     * @param array $tokenInfo Resolved user from validateBearerToken() — must have 'id' (C5)
     */
    public function setTokenInfo(array $tokenInfo): void {
        $this->tokenInfo = $tokenInfo;
    }
    
    /**
     * Get all available workflows.
     *
     * Workflows are SHIPPED, not authored: only `core/` is read. The custom
     * workflow feature (author-written specs in `custom/`, saved + deleted
     * through the admin AJAX helper) was removed in beta.10 C8 — it was an
     * unused artifact that had become a flaw vector: an ungated save arm let
     * any authenticated caller author a spec whose `dataRequirements` named
     * arbitrary CommandRunner-allowlisted commands, then execute it.
     *
     * @return array List of workflow metadata
     */
    public function listWorkflows(): array {
        $workflows = [];

        $coreDir = $this->workflowsBasePath . '/core';
        if (is_dir($coreDir)) {
            foreach (glob($coreDir . '/*.json') as $file) {
                $workflow = $this->loadWorkflow(basename($file, '.json'));
                if ($workflow) {
                    $workflow['_source'] = 'core';
                    $workflows[] = $workflow;
                }
            }
        }

        return $workflows;
    }
    
    /**
     * Get workflows organized by category
     * 
     * @return array Workflows grouped by category
     */
    public function getWorkflowsByCategory(): array {
        $workflows = $this->listWorkflows();
        $byCategory = [];
        
        foreach ($workflows as $workflow) {
            $category = $workflow['meta']['category'] ?? 'other';
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }
            $byCategory[$category][] = $workflow;
        }
        
        return $byCategory;
    }
    
    /**
     * @deprecated Use getWorkflowsByCategory() instead
     */
    public function getSpecsByCategory(): array {
        return $this->getWorkflowsByCategory();
    }
    
    /**
     * Load a workflow by ID
     * 
     * @param string $workflowId Workflow identifier
     * @return array|null Workflow data or null if not found
     */
    public function loadWorkflow(string $workflowId): ?array {
        // Check cache
        if (isset($this->workflowsCache[$workflowId])) {
            return $this->workflowsCache[$workflowId];
        }
        
        // Shipped workflows only — `core/`. See listWorkflows() for why there is
        // no longer a `custom/` lookup.
        $paths = [
            $this->workflowsBasePath . '/core/' . $workflowId . '.json',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $workflow = json_decode($content, true);

                if ($workflow && isset($workflow['id'])) {
                    $workflow['_filePath'] = $path;
                    $workflow['_folder'] = dirname($path);
                    $workflow['_source'] = 'core';

                    $this->workflowsCache[$workflowId] = $workflow;
                    return $workflow;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Resolve `default` template references in workflow parameters.
     *
     * Parameter authors can write `default: "{{data.X}}"` to seed an
     * initial value from a fetched dataRequirement. This walks the
     * parameter list and substitutes any such templates against the
     * provided `data` (and optional `userParams`) context.
     *
     * Templates inside literal-string defaults that are NOT a full
     * `{{path}}` placeholder fall through `resolveStepParamValue`'s
     * inline-substitution path — useful for `default: "Hello {{param.name}}"`.
     *
     * Mutates the workflow in place. Idempotent (safe to call twice).
     * Schema addition for beta.9 Phase C+.
     *
     * @param array $workflow The workflow (modified in place)
     * @param array $data     Fetched dataRequirements keyed by id
     * @param array $userParams Optional — preserved across `param.X` refs
     */
    public function resolveParameterDefaults(array &$workflow, array $data, array $userParams = []): void {
        if (empty($workflow['parameters']) || !is_array($workflow['parameters'])) return;
        $context = [
            'param'  => $userParams,
            'data'   => $data,
            'config' => defined('CONFIG') ? CONFIG : []
        ];
        foreach ($workflow['parameters'] as &$param) {
            if (!isset($param['default'])) continue;
            $default = $param['default'];
            if (!is_string($default)) continue;
            if (strpos($default, '{{') === false) continue;
            $resolved = $this->resolveStepParamValue($default, $context);
            $param['default'] = $resolved;
        }
        unset($param);
    }

    /**
     * Resolve all *Key fields in a loaded workflow into their non-Key
     * siblings using __workflow(). Walks meta + parameters + nested
     * option lists. Idempotent — existing non-Key fields stay untouched.
     *
     * Output additions:
     *   meta.title, meta.description
     *   parameters[*].label, parameters[*].help, parameters[*].placeholder
     *   parameters[*].options[*].label
     *
     * @param array $workflow The workflow definition (modified in place)
     * @return void
     */
    public static function resolveLabelsInPlace(array &$workflow): void {
        if (!function_exists('__workflow')) {
            require_once SECURE_FOLDER_PATH . '/admin/functions/AdminTranslation.php';
        }
        if (isset($workflow['meta'])) {
            $meta = &$workflow['meta'];
            if (isset($meta['titleKey']) && !isset($meta['title'])) {
                $meta['title'] = __workflow($workflow, $meta['titleKey'], $workflow['id'] ?? '');
            }
            if (isset($meta['descriptionKey']) && !isset($meta['description'])) {
                $meta['description'] = __workflow($workflow, $meta['descriptionKey'], '');
            }
            unset($meta);
        }
        if (!empty($workflow['parameters']) && is_array($workflow['parameters'])) {
            foreach ($workflow['parameters'] as &$param) {
                if (isset($param['labelKey']) && !isset($param['label'])) {
                    $param['label'] = __workflow($workflow, $param['labelKey'], $param['id'] ?? '');
                }
                if (isset($param['helpKey']) && !isset($param['help'])) {
                    $param['help'] = __workflow($workflow, $param['helpKey'], '');
                }
                if (isset($param['placeholderKey']) && !isset($param['placeholder'])) {
                    $param['placeholder'] = __workflow($workflow, $param['placeholderKey'], '');
                }
                if (!empty($param['options']) && is_array($param['options'])) {
                    foreach ($param['options'] as &$opt) {
                        if (isset($opt['labelKey']) && !isset($opt['label'])) {
                            $opt['label'] = __workflow($workflow, $opt['labelKey'], $opt['value'] ?? '');
                        }
                    }
                    unset($opt);
                }
            }
            unset($param);
        }
    }

    /**
     * Fetch all data requirements for a workflow
     *
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters (for condition evaluation)
     * @return array Fetched data keyed by requirement ID
     */
    public function fetchDataRequirements(array $workflow, array $userParams = []): array {
        $data = [];
        
        if (!isset($workflow['dataRequirements'])) {
            return $data;
        }
        
        foreach ($workflow['dataRequirements'] as $req) {
            $id = $req['id'];
            
            // Check condition if present
            if (isset($req['condition'])) {
                $condition = $req['condition'];
                $conditionMet = $this->evaluateDataCondition($condition, $userParams);
                
                if (!$conditionMet) {
                    // Skip this data requirement
                    $data[$id] = null;
                    continue;
                }
            }
            
            $command = $req['command'];
            $params = $req['params'] ?? [];
            $urlParams = $req['urlParams'] ?? [];
            $extract = $req['extract'] ?? null;
            
            // Role-based permission check: if tokenInfo is set, verify the user can run this command.
            // C7 — hasPermission is project-scoped for project commands; authorize against the loaded
            // project context (PROJECT_NAME). WorkflowManager always runs inside a resolved project
            // (admin/api or the dispatcher), so a workflow's data commands are checked against it.
            if ($this->tokenInfo !== null && function_exists('hasPermission')) {
                if (!hasPermission($this->tokenInfo, $command, defined('PROJECT_NAME') ? PROJECT_NAME : null)) {
                    $data[$id] = [
                        '_error' => true,
                        '_message' => "Permission denied for command: {$command}",
                        '_command' => $command
                    ];
                    continue;
                }
            }
            
            // Replace placeholders in urlParams with actual user param values
            $urlParams = array_map(function($param) use ($userParams) {
                if (preg_match('/^\{\{(\w+)\}\}$/', $param, $matches)) {
                    return $userParams[$matches[1]] ?? $param;
                }
                return $param;
            }, $urlParams);
            
            try {
                if ($extract) {
                    $data[$id] = CommandRunner::extractData($command, $params, $urlParams, $extract);
                } else {
                    $response = CommandRunner::execute($command, $params, $urlParams);
                    $data[$id] = $response->getData();
                }
            } catch (Exception $e) {
                // Store error info for debugging
                $data[$id] = [
                    '_error' => true,
                    '_message' => $e->getMessage(),
                    '_command' => $command
                ];
            }
        }
        
        return $data;
    }
    
    /**
     * Generate expanded steps from workflow definition
     * Processes preWorkflows, forEach, conditions, postWorkflows and parameter resolution
     * 
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters
     * @param array $fetchedData Data from dataRequirements
     * @param array $configData Project configuration data
     * @return array Expanded list of commands ready for execution
     */
    public function generateSteps(array $workflow, array $userParams = [], array $fetchedData = [], array $configData = []): array {
        $expandedSteps = [];
        
        // Merge static data defined in the workflow itself
        if (!empty($workflow['staticData']) && is_array($workflow['staticData'])) {
            $fetchedData = array_merge($workflow['staticData'], $fetchedData);
        }
        
        $context = [
            'param' => $userParams,
            'data' => $fetchedData,
            'config' => $configData
        ];
        
        // Process preWorkflows first
        if (!empty($workflow['preWorkflows'])) {
            $preSteps = $this->expandSubWorkflows($workflow['preWorkflows'], $context);
            foreach ($preSteps as $step) {
                $expandedSteps[] = $step;
            }
        }
        
        // Process main workflow steps
        if (isset($workflow['steps']) && !empty($workflow['steps'])) {
            foreach ($workflow['steps'] as $step) {
                // Check step condition first
                if (isset($step['condition'])) {
                    if (!$this->evaluateStepCondition($step['condition'], $context)) {
                        continue; // Skip this step
                    }
                }
                
                // Handle forEach expansion
                if (isset($step['forEach'])) {
                    $expanded = $this->expandForEach($step, $context);
                    foreach ($expanded as $expandedStep) {
                        $expandedSteps[] = $expandedStep;
                    }
                } else {
                    // Single step - resolve params
                    $resolvedParams = $this->resolveStepParams($step['params'] ?? [], $context);
                    $resolvedParams = $this->resolveEachLoops($resolvedParams, $context);
                    $resolvedStep = [
                        'command' => $step['command'],
                        'method' => $step['method'] ?? 'POST',
                        'params' => $resolvedParams
                    ];
                    
                    // Carry over execution control properties
                    foreach (['abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs'] as $prop) {
                        if (isset($step[$prop])) {
                            $resolvedStep[$prop] = $step[$prop];
                        }
                    }
                    
                    $expandedSteps[] = $resolvedStep;
                }
            }
        }
        
        // Process postWorkflows last
        if (!empty($workflow['postWorkflows'])) {
            $postSteps = $this->expandSubWorkflows($workflow['postWorkflows'], $context);
            foreach ($postSteps as $step) {
                $expandedSteps[] = $step;
            }
        }
        
        return $expandedSteps;
    }
    
    /**
     * Get the execution phases for a workflow (metadata only, no resolution).
     * Used by the client to build a phase-by-phase execution plan.
     * 
     * @param array $workflow The workflow definition
     * @param array $userParams User params (for condition evaluation on sub-workflows)
     * @return array List of phases: [{type, workflowId?}, ...]
     */
    public function getWorkflowPhases(array $workflow, array $userParams = []): array {
        $phases = [];
        $context = ['param' => $userParams];
        
        // Pre-workflows
        if (!empty($workflow['preWorkflows'])) {
            foreach ($workflow['preWorkflows'] as $sub) {
                if (is_string($sub)) {
                    $phases[] = ['type' => 'preWorkflow', 'workflowId' => $sub];
                } else {
                    $id = $sub['id'] ?? null;
                    if (!$id) continue;
                    
                    $condition = $sub['condition'] ?? null;
                    if ($condition !== null && !$this->evaluateStepCondition($condition, $context)) {
                        continue;
                    }
                    
                    $phases[] = ['type' => 'preWorkflow', 'workflowId' => $id];
                }
            }
        }
        
        // Main workflow
        $phases[] = ['type' => 'main'];
        
        // Post-workflows
        if (!empty($workflow['postWorkflows'])) {
            foreach ($workflow['postWorkflows'] as $sub) {
                if (is_string($sub)) {
                    $phases[] = ['type' => 'postWorkflow', 'workflowId' => $sub];
                } else {
                    $id = $sub['id'] ?? null;
                    if (!$id) continue;
                    
                    $condition = $sub['condition'] ?? null;
                    if ($condition !== null && !$this->evaluateStepCondition($condition, $context)) {
                        continue;
                    }
                    
                    $phases[] = ['type' => 'postWorkflow', 'workflowId' => $id];
                }
            }
        }
        
        return $phases;
    }
    
    /**
     * Resolve a single sub-workflow with fresh data.
     * Loads the workflow, fetches its dataRequirements, generates expanded steps.
     * Used by the API for phase-by-phase execution.
     * 
     * @param string $workflowId The workflow ID to resolve
     * @param array $userParams User-provided parameters
     * @return array {workflowId, steps, count} or {error}
     */
    public function resolveSubWorkflow(string $workflowId, array $userParams = []): array {
        $workflow = $this->loadWorkflow($workflowId);
        if (!$workflow) {
            return ['error' => 'Workflow not found: ' . $workflowId];
        }
        
        // Merge with workflow parameter defaults
        if (!empty($workflow['parameters'])) {
            foreach ($workflow['parameters'] as $param) {
                $paramId = $param['id'];
                if (!isset($userParams[$paramId]) && isset($param['default'])) {
                    $userParams[$paramId] = $param['default'];
                }
            }
        }
        
        // Fetch fresh data requirements
        $data = $this->fetchDataRequirements($workflow, $userParams);
        
        // Generate steps (handles forEach, conditions, nested sub-workflows)
        $steps = $this->generateSteps($workflow, $userParams, $data, []);
        
        return [
            'workflowId' => $workflowId,
            'steps' => $steps,
            'count' => count($steps)
        ];
    }
    
    /**
     * Expand sub-workflows (preWorkflows or postWorkflows) into steps
     * 
     * @param array $subWorkflows Array of workflow IDs or {id, params} objects
     * @param array $parentContext Parent workflow's context
     * @return array Expanded steps from all sub-workflows
     */
    private function expandSubWorkflows(array $subWorkflows, array $parentContext): array {
        $allSteps = [];
        
        foreach ($subWorkflows as $subWorkflow) {
            // Parse sub-workflow definition
            $condition = null;
            if (is_string($subWorkflow)) {
                $workflowId = $subWorkflow;
                $subParams = [];
            } else {
                $workflowId = $subWorkflow['id'] ?? null;
                $subParams = $subWorkflow['params'] ?? [];
                $condition = $subWorkflow['condition'] ?? null;
            }
            
            if (!$workflowId) {
                continue;
            }
            
            // Evaluate condition if present (uses parent context)
            if ($condition !== null) {
                if (!$this->evaluateStepCondition($condition, $parentContext)) {
                    continue;
                }
            }
            
            // Load the sub-workflow
            $workflow = $this->loadWorkflow($workflowId);
            if (!$workflow) {
                continue;
            }
            
            // Resolve sub-workflow params (can use {{param.x}} from parent)
            $resolvedSubParams = $this->resolveStepParams($subParams, $parentContext);
            
            // Merge with workflow defaults
            $finalParams = $resolvedSubParams;
            if (!empty($workflow['parameters'])) {
                foreach ($workflow['parameters'] as $param) {
                    $paramId = $param['id'];
                    if (!isset($finalParams[$paramId]) && isset($param['default'])) {
                        $finalParams[$paramId] = $param['default'];
                    }
                }
            }
            
            // Fetch data requirements for sub-workflow
            $subData = [];
            try {
                $subData = $this->fetchDataRequirements($workflow, $finalParams);
            } catch (Exception $e) {
                // Continue with empty data
            }
            
            // Generate steps for sub-workflow (recursive - supports nested pre/post)
            $subSteps = $this->generateSteps($workflow, $finalParams, $subData, $parentContext['config'] ?? []);
            
            // Add marker comment for clarity in preview
            if (!empty($subSteps)) {
                $subSteps[0]['_subWorkflow'] = $workflowId;
            }
            
            foreach ($subSteps as $step) {
                $allSteps[] = $step;
            }
        }
        
        return $allSteps;
    }
    
    /**
     * Expand a forEach step into multiple commands
     * 
     * @param array $step Step definition with forEach
     * @param array $context Full context (param, data, config)
     * @return array Expanded steps
     */
    private function expandForEach(array $step, array $context): array {
        $expanded = [];
        $forEachPath = $step['forEach'];
        
        // Get the data to iterate over
        $items = $this->resolveDataPath($forEachPath, $context);
        
        // Support comma-separated strings (e.g., from user params like "en,fr,es")
        if (is_string($items) && !empty($items)) {
            $items = array_map('trim', explode(',', $items));
        }
        
        if (!is_array($items)) {
            return [];
        }
        
        // Apply filter if present
        if (isset($step['filter'])) {
            $items = $this->evaluateStepFilter($step['filter'], $items, $context);
        }
        
        // Expand each item
        foreach ($items as $key => $value) {
            // Create item context for parameter resolution
            $itemContext = array_merge($context, [
                '$key' => $key,
                '$value' => $value,
                '$item' => $value  // Alias
            ]);
            
            $resolvedParams = $this->resolveStepParams($step['params'] ?? [], $itemContext);
            $resolvedParams = $this->resolveEachLoops($resolvedParams, $itemContext);
            $resolvedStep = [
                'command' => $step['command'],
                'method' => $step['method'] ?? 'POST',
                'params' => $resolvedParams
            ];
            
            // Carry over execution control properties
            foreach (['abortOnFail', 'retryOn', 'maxRetries', 'retryDelayMs'] as $prop) {
                if (isset($step[$prop])) {
                    $resolvedStep[$prop] = $step[$prop];
                }
            }
            
            $expanded[] = $resolvedStep;
        }
        
        return $expanded;
    }
    
    /**
     * Resolve a data path like "routes", "langData.languages", "param.keepAssets"
     * 
     * Supports:
     * - Direct data requirement IDs: "routes" → $context['data']['routes']
     * - Nested paths: "langData.languages" → $context['data']['langData']['languages']
     * - Param paths: "param.keepAssets" → $context['param']['keepAssets']
     * - Config paths: "config.x" → $context['config']['x']
     * 
     * @param string $path Dot-notation path
     * @param array $context Full context
     * @return mixed Resolved value
     */
    private function resolveDataPath(string $path, array $context) {
        $parts = explode('.', $path);
        $firstPart = $parts[0];
        
        // Check if first part is a known context key
        if (in_array($firstPart, ['param', 'data', 'config', '$key', '$value', '$item'])) {
            // Standard path resolution
            $current = $context;
            foreach ($parts as $part) {
                if (is_array($current) && isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    return null;
                }
            }
            return $current;
        }
        
        // Otherwise, assume it's a dataRequirement ID - look in 'data' context
        $current = $context['data'] ?? [];
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Evaluate a step condition
     * 
     * Supports:
     * - Simple path checks: "param.keepAssets"
     * - Comparisons: "param.keepAssets !== true"
     * - AND conditions: "param.keepLanguages !== true && langData.multilingual === true"
     * 
     * @param string $condition Condition string
     * @param array $context Full context (param, data, config)
     * @return bool Whether condition is met
     */
    private function evaluateStepCondition(string $condition, array $context): bool {
        // Handle AND (&&) conditions
        if (strpos($condition, '&&') !== false) {
            $parts = array_map('trim', explode('&&', $condition));
            foreach ($parts as $part) {
                if (!$this->evaluateSingleCondition($part, $context)) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle OR (||) conditions
        if (strpos($condition, '||') !== false) {
            $parts = array_map('trim', explode('||', $condition));
            foreach ($parts as $part) {
                if ($this->evaluateSingleCondition($part, $context)) {
                    return true;
                }
            }
            return false;
        }
        
        return $this->evaluateSingleCondition($condition, $context);
    }
    
    /**
     * Evaluate a single condition (no && or ||)
     */
    private function evaluateSingleCondition(string $condition, array $context): bool {
        // Handle negation at start
        $negate = false;
        if (str_starts_with($condition, '!') && !str_starts_with($condition, '!=')) {
            $negate = true;
            $condition = ltrim(substr($condition, 1));
        }
        
        // Check for comparison operators
        if (preg_match('/^([\w.]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $leftPath = $matches[1];
            $operator = $matches[2];
            $rightValue = trim($matches[3]);
            
            // Resolve left side
            $leftValue = $this->resolveDataPath($leftPath, $context);
            
            // Normalize left value for boolean comparisons (form sends "true"/"false" as strings)
            if ($leftValue === 'true') $leftValue = true;
            if ($leftValue === 'false') $leftValue = false;
            if ($leftValue === '1') $leftValue = true;
            if ($leftValue === '0') $leftValue = false;
            
            // Parse right side
            $rightParsed = $this->parseFilterValue($rightValue);
            
            $result = match($operator) {
                '=', '==' => $leftValue == $rightParsed,
                '===' => $leftValue === $rightParsed,
                '!=', '!==' => $leftValue !== $rightParsed,
                '>' => $leftValue > $rightParsed,
                '>=' => $leftValue >= $rightParsed,
                '<' => $leftValue < $rightParsed,
                '<=' => $leftValue <= $rightParsed,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple truthy check on path
        $value = $this->resolveDataPath($condition, $context);
        
        // Normalize for truthy check
        if ($value === 'true' || $value === '1') $value = true;
        if ($value === 'false' || $value === '0') $value = false;
        
        $result = !empty($value);
        
        return $negate ? !$result : $result;
    }
    
    /**
     * Evaluate filter expression for forEach items
     * 
     * @param string $filter Filter expression
     * @param array $items Items to filter
     * @param array $context Base context
     * @return array Filtered items
     */
    private function evaluateStepFilter(string $filter, array $items, array $context): array {
        $filtered = [];
        
        // Normalize filter - remove {{ }} if present for simpler parsing
        $normalizedFilter = preg_replace('/\{\{(\$\w+(?:\.\w+)?)\}\}/', '$1', $filter);
        
        foreach ($items as $key => $value) {
            $itemContext = array_merge($context, [
                '$key' => $key,
                '$value' => $value,
                '$item' => $value
            ]);
            
            // Support && (AND) operator — split into sub-expressions, all must pass
            $subExpressions = array_map('trim', explode('&&', $normalizedFilter));
            $allPass = true;
            
            foreach ($subExpressions as $expr) {
                if (!$this->evaluateSingleFilterExpr($expr, $key, $value, $itemContext)) {
                    $allPass = false;
                    break;
                }
            }
            
            if ($allPass) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Evaluate a single filter comparison expression
     */
    private function evaluateSingleFilterExpr(string $expr, $key, $value, array $itemContext): bool {
        if (preg_match('/^(\$\w+(?:\.\w+)?)\s*(===?|!==?|!=)\s*(.+)$/', $expr, $matches)) {
            $leftPath = $matches[1];
            $operator = $matches[2];
            $rightValue = trim($matches[3]);

            // Resolve left side (handle $key, $value, $value.field)
            if ($leftPath === '$key') {
                $leftResolved = $key;
            } elseif ($leftPath === '$value' || $leftPath === '$item') {
                $leftResolved = $value;
            } elseif (str_starts_with($leftPath, '$value.') || str_starts_with($leftPath, '$item.')) {
                $fieldPath = substr($leftPath, strpos($leftPath, '.') + 1);
                $leftResolved = is_array($value) ? ($value[$fieldPath] ?? null) : null;
            } else {
                $leftResolved = $this->resolveDataPath(ltrim($leftPath, '$'), $itemContext);
            }

            // Parse right value - also handle {{dataPath}} references
            if (preg_match('/^\{\{(.+)\}\}$/', $rightValue, $dataMatch)) {
                $rightParsed = $this->resolveDataPath($dataMatch[1], $itemContext);
            } else {
                $rightParsed = $this->parseFilterValue($rightValue);
            }

            return match($operator) {
                '=', '==' => $leftResolved == $rightParsed,
                '===' => $leftResolved === $rightParsed,
                '!=', '!==' => $leftResolved !== $rightParsed,
                default => false
            };
        }

        // Membership operators: `in` and `not_in`. Useful for diff-style
        // forEach steps such as "delete every existing language that the
        // user no longer has selected":
        //
        //   "forEach": "data.langData.languages",
        //   "filter": "{{$value}} not_in {{param.languages}}",
        //   "command": "deleteLang", "params": { "code": "{{$value}}" }
        //
        // Added in beta.9 Phase C+ (workflow framework upgrades).
        if (preg_match('/^(.+?)\s+(in|not_in)\s+(.+)$/', $expr, $matches)) {
            $leftRaw = trim($matches[1]);
            $operator = $matches[2];
            $rightRaw = trim($matches[3]);

            // Resolve left value
            if ($leftRaw === '$key') {
                $leftValue = $key;
            } elseif ($leftRaw === '$value' || $leftRaw === '$item') {
                $leftValue = $value;
            } elseif (str_starts_with($leftRaw, '$value.') || str_starts_with($leftRaw, '$item.')) {
                $fieldPath = substr($leftRaw, strpos($leftRaw, '.') + 1);
                $leftValue = is_array($value) ? ($value[$fieldPath] ?? null) : null;
            } elseif (preg_match('/^\{\{(.+)\}\}$/', $leftRaw, $leftTpl)) {
                $leftValue = $this->resolveDataPath(trim($leftTpl[1]), $itemContext);
            } else {
                // Bare path or literal — try data path first, fall back to literal.
                $leftValue = $this->resolveDataPath($leftRaw, $itemContext);
                if ($leftValue === null) {
                    $leftValue = $this->parseFilterValue($leftRaw);
                }
            }

            // Resolve right side as an array
            if (preg_match('/^\{\{(.+)\}\}$/', $rightRaw, $rightTpl)) {
                $rightArr = $this->resolveDataPath(trim($rightTpl[1]), $itemContext);
            } else {
                $rightArr = $this->resolveDataPath($rightRaw, $itemContext);
            }

            // If the right side isn't an array (e.g., the data path didn't
            // resolve, or the workflow author pointed at a scalar), `in`
            // returns false and `not_in` returns true — matching the
            // intuitive read of "is X in this list?" / "is X NOT in this list?"
            // when the list is empty.
            if (!is_array($rightArr)) {
                return $operator === 'not_in';
            }

            $isMember = in_array($leftValue, $rightArr, true);
            return $operator === 'in' ? $isMember : !$isMember;
        }

        // No operator - truthy check
        return true;
    }
    
    /**
     * Parse a filter/condition value (handle quotes, numbers, booleans)
     * 
     * @param string $value Raw value string
     * @return mixed Parsed value
     */
    private function parseFilterValue(string $value) {
        // Quoted string
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
            return $matches[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        
        // Number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        return $value;
    }
    
    /**
     * Resolve step parameters with context values
     * 
     * @param array $params Parameter definitions
     * @param array $context Full context including $key, $value for forEach
     * @return array Resolved parameters
     */
    private function resolveStepParams(array $params, array $context): array {
        $resolved = [];
        
        foreach ($params as $key => $value) {
            $resolved[$key] = $this->resolveStepParamValue($value, $context);
        }
        
        return $resolved;
    }
    
    /**
     * Resolve a single parameter value
     * 
     * @param mixed $value Value to resolve
     * @param array $context Context for resolution
     * @return mixed Resolved value
     */
    private function resolveStepParamValue($value, array $context) {
        if (is_string($value)) {
            // First, resolve simple $value/$key placeholders that may be nested
            // This handles {{__current_page;lang={{$value}}}} -> {{__current_page;lang=en}}
            $value = preg_replace_callback('/\{\{\$(\w+)\}\}/', function($matches) use ($context) {
                $varName = '$' . $matches[1];
                if (isset($context[$varName])) {
                    $val = $context[$varName];
                    return is_scalar($val) ? $val : json_encode($val);
                }
                return $matches[0];
            }, $value);
            
            // Handle {{placeholder}} syntax (full value is a placeholder)
            if (preg_match('/^\{\{([^{}]+)\}\}$/', $value, $matches)) {
                $path = trim($matches[1]);
                // Preserve system placeholders (e.g. {{__current_page;lang=en}}) — they're resolved at render time
                if (str_starts_with($path, '__')) {
                    return $value;
                }
                // Use empty string as fallback for missing params (not the raw template)
                // This ensures unresolved {{param.X}} becomes '' rather than literal '{{param.X}}'
                $resolved = $this->resolvePathWithFilters($path, $context, '');
                return $resolved;
            }
            
            // Handle inline {{placeholders}} in strings (not containing nested braces)
            return preg_replace_callback('/\{\{([^{}]+)\}\}/', function($matches) use ($context) {
                $path = trim($matches[1]);
                // Preserve system placeholders — resolved at render time, not workflow time
                if (str_starts_with($path, '__')) {
                    return $matches[0];
                }
                $resolved = $this->resolvePathWithFilters($path, $context, '');
                return is_scalar($resolved) ? $resolved : json_encode($resolved);
            }, $value);
        }
        
        if (is_array($value)) {
            // Don't resolve $each templates prematurely — the $item template
            // contains {{$value}} references that need loop context from resolveEachLoops.
            // Only resolve the $each source here; leave $item intact.
            if (isset($value['$each'])) {
                $value['$each'] = $this->resolveStepParamValue($value['$each'], $context);
                return $value;
            }
            return $this->resolveStepParams($value, $context);
        }
        
        return $value;
    }
    
    /**
     * Resolve a path with optional filters (e.g., {{$value | uppercase}})
     * 
     * @param string $path The path possibly containing filters
     * @param array $context Context for resolution
     * @param mixed $fallback Fallback value if unresolved
     * @return mixed Resolved and filtered value
     */
    private function resolvePathWithFilters(string $path, array $context, $fallback = null) {
        // Check for filter syntax: "path | filter"
        $filter = null;
        if (str_contains($path, '|')) {
            $parts = explode('|', $path, 2);
            $path = trim($parts[0]);
            $filter = trim($parts[1]);
        }
        
        // Resolve the base value
        $resolved = null;
        
        // Handle special forEach variables
        if ($path === '$key') {
            $resolved = $context['$key'] ?? $fallback;
        } elseif ($path === '$value' || $path === '$item') {
            $resolved = $context['$value'] ?? $fallback;
        } elseif (str_starts_with($path, '$value.') || str_starts_with($path, '$item.')) {
            $fieldPath = substr($path, strpos($path, '.') + 1);
            $itemValue = $context['$value'] ?? [];
            $resolved = is_array($itemValue) ? ($itemValue[$fieldPath] ?? $fallback) : $fallback;
        } else {
            // Normal path resolution
            $resolved = $this->resolveDataPath($path, $context) ?? $fallback;
        }
        
        // Apply filter if present
        if ($filter !== null && is_string($resolved)) {
            $resolved = $this->applyFilter($resolved, $filter);
        }
        
        return $resolved;
    }
    
    /**
     * Apply a filter to a value
     * 
     * @param string $value The value to filter
     * @param string $filter The filter name
     * @return string Filtered value
     */
    private function applyFilter(string $value, string $filter): string {
        return match($filter) {
            'uppercase', 'upper' => strtoupper($value),
            'lowercase', 'lower' => strtolower($value),
            'ucfirst', 'capitalize' => ucfirst($value),
            'ucwords', 'title' => ucwords($value),
            'trim' => trim($value),
            'langname', 'language' => self::getLanguageName($value),
            default => $value
        };
    }
    
    /**
     * Evaluate a condition for data requirements
     * 
     * @param string $condition Condition string (param name or expression)
     * @param array $userParams User parameters
     * @return bool Whether condition is met
     */
    private function evaluateDataCondition(string $condition, array $userParams): bool {
        // Handle AND (&&) conditions
        if (strpos($condition, '&&') !== false) {
            $parts = array_map('trim', explode('&&', $condition));
            foreach ($parts as $part) {
                if (!$this->evaluateDataCondition($part, $userParams)) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle OR (||) conditions
        if (strpos($condition, '||') !== false) {
            $parts = array_map('trim', explode('||', $condition));
            foreach ($parts as $part) {
                if ($this->evaluateDataCondition($part, $userParams)) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle negation
        $negate = false;
        if (str_starts_with($condition, '!') && !str_starts_with($condition, '!=')) {
            $negate = true;
            $condition = ltrim(substr($condition, 1));
        }
        
        // Handle comparison operators (e.g., "multilingual === true", "pages > 1")
        if (preg_match('/^([\w.]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $paramName = trim($matches[1]);
            $operator = $matches[2];
            $rightRaw = trim($matches[3]);
            
            // Resolve left side
            $left = $userParams[$paramName] ?? null;
            // Normalize boolean-like strings
            if ($left === 'true' || $left === 'on' || $left === '1') $left = true;
            if ($left === 'false' || $left === 'off' || $left === '0') $left = false;
            
            // Parse right side
            $right = match($rightRaw) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => is_numeric($rightRaw) ? (int)$rightRaw : trim($rightRaw, "'\"")
            };
            
            $result = match($operator) {
                '=', '==' => $left == $right,
                '===' => $left === $right,
                '!=' => $left != $right,
                '!==' => $left !== $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                '<=' => $left <= $right,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple param check - just check if param is truthy
        if (isset($userParams[$condition])) {
            $value = $userParams[$condition];
            $result = !empty($value) && $value !== 'false' && $value !== '0';
            return $negate ? !$result : $result;
        }
        
        // Param doesn't exist - condition not met
        return $negate ? true : false;
    }
    
    /**
     * Render a prompt template with all variables substituted
     * 
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters
     * @param array $fetchedData Data fetched from dataRequirements
     * @return string Rendered prompt
     */
    public function renderPrompt(array $workflow, array $userParams, array $fetchedData): string {
        // Get the prompt template from workflow
        $template = $workflow['promptTemplate'] ?? '';
        
        // If template is a filename (ends with .md), load it directly
        if (preg_match('/\.md$/', $template)) {
            $folder = $workflow['_folder'] ?? ($this->workflowsBasePath . '/core');
            $mdPath = $folder . '/' . $template;
            
            if (file_exists($mdPath)) {
                $template = file_get_contents($mdPath);
            } else {
                return "Error: Template file not found: $template";
            }
        }
        // Legacy support: Check for @file: syntax within template content
        elseif (preg_match('/^@file:\s*(.+\.md)$/m', $template, $matches)) {
            $mdFile = trim($matches[1]);
            $mdPath = ($workflow['_folder'] ?? '') . '/' . $mdFile;
            
            if (file_exists($mdPath)) {
                $template = file_get_contents($mdPath);
            }
        }
        
        // Build commands context from relatedCommands + fetched help data
        $commandsContext = $this->buildCommandsContext($workflow, $fetchedData);
        
        // Reset per-render help cache and seed it with already-fetched help data
        $this->helpCache = $commandsContext;
        
        // Phase 3 — Auto-inject pins / warnings as partial references at the top of the template,
        // unless meta.suppressPinsHeader is true. The resolver below inlines them.
        $template = $this->prependPinsWarnings($template, $workflow);
        
        // Phase 1/2/4 — Resolve {{> name}}, {{> command.X}}, {{> command.$relatedCommands}},
        // {{> pin.X}}, {{> warning.X}}, {{> example.X}} BEFORE conditionals/loops, so the
        // inlined block content participates in the regular template passes.
        $template = $this->resolvePartials($template, $workflow);
        
        // First pass: Handle {{#if}} conditionals
        $template = $this->processConditionals($template, $userParams, $fetchedData);
        
        // Second pass: Replace {{#each}} loops (supports data.X and commands)
        $template = $this->processEachLoops($template, $fetchedData, $commandsContext);
        
        // Third pass: Replace {{json X}} helper — JSON-encode a data value
        $template = preg_replace_callback('/\{\{json\s+(\w+)\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            if (!isset($fetchedData[$key])) return '(no data)';
            $value = $fetchedData[$key];
            if (is_array($value) && isset($value['_error'])) return "(error loading {$key})";
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $template);
        
        // Fourth pass: Replace direct data references {{data.xxx}} and {{data.xxx.yyy}}
        $template = preg_replace_callback('/\{\{data\.(\w+)(?:\.(\w+))?\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            $subKey = $matches[2] ?? null;
            
            if (!isset($fetchedData[$key])) {
                return '(no data)';
            }
            
            $value = $fetchedData[$key];
            
            // Check if there was an error fetching
            if (is_array($value) && isset($value['_error'])) {
                return "(error loading {$key})";
            }
            
            if ($subKey && is_array($value)) {
                $value = $value[$subKey] ?? null;
            }
            
            return $this->formatValue($value);
        }, $template);
        
        // Fifth pass: Replace {{param.xxx}} user parameters
        $template = preg_replace_callback('/\{\{param\.(\w+)\}\}/', function($matches) use ($userParams) {
            $key = $matches[1];
            return $userParams[$key] ?? '';
        }, $template);
        
        // Sixth pass: Replace {{helpers.xxx}} - static helper values
        $template = preg_replace_callback('/\{\{helpers\.(\w+)(?:\s+([^}]+))?\}\}/', function($matches) {
            $helper = $matches[1];
            $args = isset($matches[2]) ? trim($matches[2]) : '';
            
            return match($helper) {
                'date' => date('Y-m-d'),
                'datetime' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
                default => ''
            };
        }, $template);
        
        // Seventh pass: Replace bare data references {{xxx}} and {{xxx.yyy}}
        // This catches any remaining {{word}} or {{word.word}} that wasn't handled above
        $template = preg_replace_callback('/\{\{(\w+)(?:\.(\w+))?\}\}/', function($matches) use ($fetchedData) {
            $key = $matches[1];
            $subKey = $matches[2] ?? null;
            
            if (!isset($fetchedData[$key])) return $matches[0]; // leave unknown placeholders
            $value = $fetchedData[$key];
            if (is_array($value) && isset($value['_error'])) return "(error loading {$key})";
            
            if ($subKey && is_array($value)) {
                $value = $value[$subKey] ?? null;
            }
            
            return $this->formatValue($value);
        }, $template);
        
        return trim($template);
    }
    
    /**
     * Process {{#if}} conditionals in template
     */
    private function processConditionals(string $template, array $userParams, array $fetchedData): string {
        // Pattern: {{#if condition}}...content...{{/if}}
        // Also supports: {{#if condition}}...{{else}}...{{/if}}
        $pattern = '/\{\{#if\s+(.+?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($userParams, $fetchedData) {
            $condition = trim($matches[1]);
            $ifContent = $matches[2];
            $elseContent = $matches[3] ?? '';
            
            $conditionMet = $this->evaluateTemplateCondition($condition, $userParams, $fetchedData);
            
            return $conditionMet ? $ifContent : $elseContent;
        }, $template);
    }
    
    /**
     * Evaluate a template condition
     */
    private function evaluateTemplateCondition(string $condition, array $userParams, array $fetchedData): bool {
        // Handle negation
        $negate = false;
        if (str_starts_with($condition, '!')) {
            $negate = true;
            $condition = substr($condition, 1);
        }
        
        // Check for comparison operators
        if (preg_match('/^([\w.]+)\s*(===?|!==?|>=?|<=?)\s*(.+)$/', $condition, $matches)) {
            $left = $this->resolveTemplateValue($matches[1], $userParams, $fetchedData);
            $operator = $matches[2];
            $right = $this->parseConditionValue($matches[3]);
            
            $result = match($operator) {
                '=', '==' => $left == $right,
                '===' => $left === $right,
                '!=' => $left != $right,
                '!==' => $left !== $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                '<=' => $left <= $right,
                default => false
            };
            
            return $negate ? !$result : $result;
        }
        
        // Simple existence/truthy check
        $value = $this->resolveTemplateValue($condition, $userParams, $fetchedData);
        $result = !empty($value) && $value !== 'false' && $value !== '0';
        
        return $negate ? !$result : $result;
    }
    
    /**
     * Resolve a value reference in template conditions
     */
    private function resolveTemplateValue(string $ref, array $userParams, array $fetchedData) {
        // Check for param.xxx
        if (str_starts_with($ref, 'param.')) {
            $key = substr($ref, 6);
            $value = $userParams[$key] ?? null;
            // Normalize boolean-like strings from URL query parameters
            if ($value === 'true' || $value === 'on' || $value === '1') return true;
            if ($value === 'false' || $value === 'off' || $value === '0') return false;
            return $value;
        }
        
        // Check for data.xxx
        if (str_starts_with($ref, 'data.')) {
            $parts = explode('.', substr($ref, 5));
            $value = $fetchedData;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return null;
                }
            }
            return $value;
        }
        
        // Check fetchedData for bare references (e.g., "styles.css" → $fetchedData['styles']['css'])
        $parts = explode('.', $ref);
        if (count($parts) >= 1 && isset($fetchedData[$parts[0]])) {
            $value = $fetchedData[$parts[0]];
            for ($i = 1; $i < count($parts); $i++) {
                if (is_array($value) && isset($value[$parts[$i]])) {
                    $value = $value[$parts[$i]];
                } else {
                    return null;
                }
            }
            return $value;
        }
        
        // Direct param check (for backward compatibility)
        return $userParams[$ref] ?? null;
    }
    
    /**
     * Parse a condition value (handle quotes, numbers, booleans)
     */
    private function parseConditionValue(string $value) {
        $value = trim($value);
        
        // Quoted string
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
            return $matches[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        
        // Number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        return $value;
    }
    
    /**
     * Build commands context from relatedCommands + fetched help data
     * 
     * Maps relatedCommands to their help data fetched via dataRequirements.
     * Looks for dataRequirement IDs matching "help*" that used the help command.
     */
    private function buildCommandsContext(array $workflow, array $fetchedData): array {
        $relatedCommands = $workflow['relatedCommands'] ?? [];
        if (empty($relatedCommands)) return [];
        
        $commands = [];
        foreach ($relatedCommands as $cmdName) {
            // Try to find help data in fetchedData (convention: "helpCommandName" or "help_commandName")
            $found = false;
            foreach ($fetchedData as $dataId => $dataValue) {
                // Match helpEditStyles, helpSetRootVariables, etc.
                $normalizedCmd = strtolower($cmdName);
                $normalizedId = strtolower($dataId);
                if ($normalizedId === 'help' . $normalizedCmd || $normalizedId === 'help_' . $normalizedCmd) {
                    if (is_array($dataValue) && !isset($dataValue['_error'])) {
                        $commands[$cmdName] = $dataValue;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                // Try fetching help live if CommandRunner is available
                try {
                    $helpData = CommandRunner::extractData('help', [], [$cmdName], 'data');
                    if ($helpData) {
                        $commands[$cmdName] = $helpData;
                    }
                } catch (\Throwable $e) {
                    // Skip this command
                }
            }
        }
        
        return $commands;
    }
    
    /**
     * Process {{#each}} loops in template
     * 
     * Supports:
     * - {{#each data.xxx}} — iterate over fetchedData[xxx]
     * - {{#each commands}} — iterate over built commands context
     * - {{formatCommand @key this}} — format command help as markdown
     * - {{@key}}, {{this}}, {{this.field}} — standard loop variables
     */
    private function processEachLoops(string $template, array $fetchedData, array $commandsContext = []): string {
        // Generic pattern: {{#each SOMETHING}}...{{/each}}
        $pattern = '/\{\{#each\s+([\w.]+)\}\}(.*?)\{\{\/each\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($fetchedData, $commandsContext) {
            $source = $matches[1];
            $loopContent = $matches[2];
            
            // Resolve the items to iterate over
            $items = null;
            if ($source === 'commands') {
                $items = $commandsContext;
            } elseif (str_starts_with($source, 'data.')) {
                $dataKey = substr($source, 5);
                $items = $fetchedData[$dataKey] ?? null;
            } else {
                // Bare name — check fetchedData
                $items = $fetchedData[$source] ?? null;
            }
            
            if (!is_array($items) || empty($items)) {
                return '';
            }
            
            $output = '';
            
            foreach ($items as $key => $item) {
                $itemOutput = $loopContent;
                
                // Replace {{formatCommand @key this}} — special helper
                $itemOutput = preg_replace_callback('/\{\{formatCommand\s+@key\s+this\}\}/', function() use ($key, $item) {
                    return $this->formatCommand($key, is_array($item) ? $item : []);
                }, $itemOutput);
                
                // Replace {{@key}}
                $itemOutput = str_replace('{{@key}}', (string)$key, $itemOutput);
                
                // Replace {{this}} with the whole item (formatted)
                $itemOutput = str_replace('{{this}}', $this->formatValue($item), $itemOutput);
                
                // Replace {{this.field}} for object properties
                if (is_array($item)) {
                    $itemOutput = preg_replace_callback('/\{\{this\.(\w+)\}\}/', function($m) use ($item) {
                        return isset($item[$m[1]]) ? $this->formatValue($item[$m[1]]) : '';
                    }, $itemOutput);
                }
                
                $output .= $itemOutput;
            }
            
            return $output;
        }, $template);
    }
    
    /**
     * Format a value for output in prompt
     */
    private function formatValue($value): string {
        if (is_null($value)) {
            return '(empty)';
        }
        
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        
        if (is_array($value)) {
            // Check if it's an associative array (object-like)
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            
            // Simple array - format as list
            return implode(', ', array_map(function($v) {
                return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }, $value));
        }
        
        return (string)$value;
    }
    
    /**
     * Validate a workflow against the schema
     * 
     * @param array $workflow Workflow data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateWorkflow(array $workflow): array {
        $errors = [];
        
        // Required fields - only id, version, and meta are truly required
        $requiredFields = ['id', 'version', 'meta'];
        foreach ($requiredFields as $field) {
            if (!isset($workflow[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Must have either promptTemplate OR steps (or both)
        if (!isset($workflow['promptTemplate']) && !isset($workflow['steps'])) {
            $errors[] = "Workflow must have either 'promptTemplate' or 'steps' (or both)";
        }
        
        // Validate meta
        if (isset($workflow['meta'])) {
            $meta = $workflow['meta'];
            
            // Must have either titleKey (core/i18n) or name (custom/direct)
            if (!isset($meta['titleKey']) && !isset($meta['name'])) {
                $errors[] = "Missing required meta field: titleKey or name";
            }
            if (!isset($meta['category'])) {
                $errors[] = "Missing required meta field: category";
            } else {
                // Validate category value
                $validCategories = ['creation', 'modification', 'advanced', 'style', 'template', 'content', 'wip'];
                if (!in_array($meta['category'], $validCategories)) {
                    $errors[] = "Invalid category: {$meta['category']}. Must be one of: " . implode(', ', $validCategories);
                }
            }
        }
        
        // Validate parameters if present
        if (isset($workflow['parameters'])) {
            foreach ($workflow['parameters'] as $index => $param) {
                if (!isset($param['id'])) {
                    $errors[] = "Parameter at index {$index} missing required field: id";
                }
                if (!isset($param['type'])) {
                    $errors[] = "Parameter at index {$index} missing required field: type";
                }
            }
        }
        
        // Validate dataRequirements if present
        if (isset($workflow['dataRequirements'])) {
            foreach ($workflow['dataRequirements'] as $index => $req) {
                if (!isset($req['id'])) {
                    $errors[] = "Data requirement at index {$index} missing required field: id";
                }
                if (!isset($req['command'])) {
                    $errors[] = "Data requirement at index {$index} missing required field: command";
                }
            }
        }
        
        // Validate steps if present
        if (isset($workflow['steps'])) {
            foreach ($workflow['steps'] as $index => $step) {
                if (!isset($step['command']) && !isset($step['template'])) {
                    $errors[] = "Step at index {$index} missing required field: command or template";
                }
            }
        }
        
        // Validate pins / warnings (Phase 3) — must be string arrays whose IDs map to existing files.
        $warnings = [];
        foreach (['pins' => 'pins', 'warnings' => 'warnings'] as $field => $folder) {
            if (!isset($workflow[$field])) continue;
            if (!is_array($workflow[$field])) {
                $errors[] = "Field '{$field}' must be an array of string IDs";
                continue;
            }
            foreach ($workflow[$field] as $i => $id) {
                if (!is_string($id) || $id === '') {
                    $errors[] = "{$field}[{$i}] must be a non-empty string ID";
                    continue;
                }
                $path = $this->workflowsBasePath . '/' . $folder . '/' . $id . '.md';
                if (!file_exists($path)) {
                    $warnings[] = "{$field}[{$i}] references missing file: {$folder}/{$id}.md";
                }
            }
        }
        
        // Scan promptTemplate (when it's a .md filename) for {{> name}} references and warn on missing files.
        if (isset($workflow['promptTemplate']) && preg_match('/\.md$/', (string)$workflow['promptTemplate'])) {
            $folder = $workflow['_folder'] ?? ($this->workflowsBasePath . '/core');
            $mdPath = $folder . '/' . $workflow['promptTemplate'];
            if (file_exists($mdPath)) {
                $tplContent = (string)@file_get_contents($mdPath);
                if (preg_match_all('/\{\{>\s+([A-Za-z][\w.\-\$]*)\}\}/', $tplContent, $m)) {
                    foreach (array_unique($m[1]) as $partialName) {
                        // Skip command.* — resolved at runtime, not file-backed.
                        if (str_starts_with($partialName, 'command.')) continue;
                        $path = $this->partialFilePath($partialName);
                        if ($path === null || !file_exists($path)) {
                            $warnings[] = "Template references missing partial: {{> {$partialName}}}";
                        }
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Get a language's display name
     * 
     * @param string $code Language code
     * @return string Display name or the code if not found
     */
    public static function getLanguageName(string $code): string {
        return self::$languageNames[$code] ?? $code;
    }
    
    /**
     * Get all available language names
     * 
     * @return array Language code => name mappings
     */
    public static function getAllLanguageNames(): array {
        return self::$languageNames;
    }
    
    /**
     * Check if a workflow has manual steps (no AI prompting needed)
     * 
     * @param array $workflow The workflow definition
     * @return bool True if workflow has steps and no promptTemplate
     */
    public function isManualWorkflow(array $workflow): bool {
        return isset($workflow['steps']) && !isset($workflow['promptTemplate']);
    }
    
    /**
     * Check if a workflow requires AI generation
     * 
     * @param array $workflow The workflow definition
     * @return bool True if workflow has a promptTemplate
     */
    public function isAiWorkflow(array $workflow): bool {
        return isset($workflow['promptTemplate']);
    }
    
    /**
     * Format helper for commands in prompt.
     *
     * @deprecated Since 1.0.0-beta.7. Use {{> command.X}} or {{> command.$relatedCommands}} in
     *             workflow templates instead. The new partial syntax routes to formatCommandFull()
     *             which produces cleaner markdown. This helper remains for back-compat with custom
     *             workflows that have not yet migrated; the rendered prompt now carries an HTML
     *             comment marking it deprecated so reviewers spot it.
     * @see formatCommandFull()
     */
    public function formatCommand(string $command, array $params): string {
        error_log("[WorkflowManager] DEPRECATED: formatCommand('$command') called — migrate template to {{> command.$command}}");
        $parts = [
            "<!-- DEPRECATED: replace {{formatCommand}} with {{> command.{$command}}} — see docs/WORKFLOW_SYSTEM.md -->",
            "### **{$command}**",
        ];
        foreach ($params as $key => $value) {
            $formattedValue = is_array($value) 
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : (string)$value;
            $parts[] = "- **{$key}**: {$formattedValue}";
        }
        return implode("\n", $parts);
    }
    
    /**
     * Format a single command's help data as clean markdown for inclusion in an AI prompt.
     *
     * Used by the {{> command.X}} partial resolver. Default `$concise = true` keeps prompts tight;
     * verbose mode adds error_responses and a fuller example block.
     *
     * @param string $command   Command name (used for the heading)
     * @param array  $helpData  Output of `help` command for this command (description, method,
     *                          parameters, example, notes, error_responses, ...)
     * @param bool   $concise   When true (default) drop error_responses and trim the example.
     * @return string Markdown block (no trailing newline)
     */
    public function formatCommandFull(string $command, array $helpData, bool $concise = true): string {
        $out = ["### **{$command}**"];
        
        if (!empty($helpData['description'])) {
            $out[] = '';
            $out[] = (string)$helpData['description'];
        }
        
        if (!empty($helpData['method'])) {
            $out[] = '';
            $out[] = "- **Method:** `" . (string)$helpData['method'] . "`";
            if (!empty($helpData['url'])) {
                $out[] = "- **URL:** `" . (string)$helpData['url'] . "`";
            }
        }
        
        // Parameters block — table when multiple, bullet list when one.
        if (!empty($helpData['parameters']) && is_array($helpData['parameters'])) {
            $out[] = '';
            $out[] = '**Parameters:**';
            foreach ($helpData['parameters'] as $pname => $pdef) {
                if (!is_array($pdef)) { $pdef = ['description' => (string)$pdef]; }
                $required = !empty($pdef['required']) ? ' *(required)*' : '';
                $type = isset($pdef['type']) ? " `" . (string)$pdef['type'] . "`" : '';
                $desc = (string)($pdef['description'] ?? '');
                $out[] = "- **{$pname}**{$type}{$required} — {$desc}";
            }
        }
        
        // Example — pick the smallest meaningful one in concise mode.
        $example = $helpData['example'] ?? ($helpData['examples'][0] ?? null);
        if ($example !== null) {
            $out[] = '';
            $out[] = '**Example:**';
            if (is_array($example)) {
                $out[] = '```json';
                $out[] = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $out[] = '```';
            } else {
                $out[] = '```';
                $out[] = (string)$example;
                $out[] = '```';
            }
        }
        
        if (!empty($helpData['notes'])) {
            $out[] = '';
            $out[] = '**Notes:** ' . (is_array($helpData['notes']) ? implode(' ', $helpData['notes']) : (string)$helpData['notes']);
        }
        
        if (!$concise && !empty($helpData['error_responses']) && is_array($helpData['error_responses'])) {
            $out[] = '';
            $out[] = '**Error responses:**';
            foreach ($helpData['error_responses'] as $code => $msg) {
                $out[] = "- `{$code}` — " . (is_array($msg) ? json_encode($msg) : (string)$msg);
            }
        }
        
        return implode("\n", $out);
    }
    
    /**
     * Look up help data for a single command, going through the per-render cache first.
     * Returns null if neither cache nor live fetch yields data.
     */
    private function resolveHelpForCommand(string $command): ?array {
        if (isset($this->helpCache[$command]) && is_array($this->helpCache[$command])) {
            return $this->helpCache[$command];
        }
        try {
            $helpData = CommandRunner::extractData('help', [], [$command], 'data');
            if (is_array($helpData)) {
                $this->helpCache[$command] = $helpData;
                return $helpData;
            }
        } catch (\Throwable $e) {
            error_log("[WorkflowManager] resolveHelpForCommand('$command') failed: " . $e->getMessage());
        }
        return null;
    }
    
    /**
     * Prepend ## Pins / ## Warnings sections (as {{> pin.X}} / {{> warning.X}} references)
     * to the template, when the workflow declares them. The partial resolver inlines the
     * actual content. Workflows can opt out via meta.suppressPinsHeader = true.
     */
    private function prependPinsWarnings(string $template, array $workflow): string {
        if (!empty($workflow['meta']['suppressPinsHeader'])) {
            return $template;
        }
        $prefix = '';
        $pins = $workflow['pins'] ?? [];
        $warnings = $workflow['warnings'] ?? [];
        
        if (is_array($pins) && !empty($pins)) {
            $prefix .= "## Pins\n\n";
            foreach ($pins as $id) {
                if (is_string($id) && $id !== '') {
                    $prefix .= "{{> pin.{$id}}}\n\n";
                }
            }
        }
        if (is_array($warnings) && !empty($warnings)) {
            $prefix .= "## Warnings\n\n";
            foreach ($warnings as $id) {
                if (is_string($id) && $id !== '') {
                    $prefix .= "{{> warning.{$id}}}\n\n";
                }
            }
        }
        if ($prefix !== '') {
            $prefix .= "---\n\n";
        }
        return $prefix . $template;
    }
    
    /**
     * Resolve {{> name}} partial references.
     *
     * Supported forms:
     *   {{> blockname}}                  → secure/admin/workflows/blocks/blockname.md
     *   {{> pin.X}}                      → secure/admin/workflows/pins/X.md
     *   {{> warning.X}}                  → secure/admin/workflows/warnings/X.md
     *   {{> example.X}}                  → secure/admin/workflows/examples/X.md
     *   {{> command.X}}                  → formatCommandFull(X, helpCache[X])
     *   {{> command.$relatedCommands}}   → formatCommandFull for each command in the
     *                                       workflow's relatedCommands list, joined with ---
     *
     * Recursive: inlined block content is itself scanned for further {{> ...}} references
     * up to PARTIALS_MAX_DEPTH levels deep. Cycles are detected via the $visited set.
     * Unknown / missing partials are LEFT INTACT in the template (not silently dropped)
     * and an error_log entry is written so typos are visible.
     */
    private function resolvePartials(string $template, array $workflow = [], int $depth = 0, array $visited = []): string {
        if ($depth >= self::PARTIALS_MAX_DEPTH) {
            error_log('[WorkflowManager] resolvePartials: max depth reached, leaving remaining placeholders intact');
            return $template;
        }
        
        $pattern = '/\{\{>\s+([A-Za-z][\w.\-\$]*)\}\}/';
        
        return preg_replace_callback($pattern, function($matches) use ($workflow, $depth, $visited) {
            $name = $matches[1];
            
            if (isset($visited[$name])) {
                error_log("[WorkflowManager] resolvePartials: cycle detected on '{$name}'");
                return $matches[0];
            }
            $visited[$name] = true;
            
            $resolved = $this->resolvePartialName($name, $workflow);
            if ($resolved === null) {
                // Leave placeholder intact, log already written by resolvePartialName.
                return $matches[0];
            }
            // Recurse so nested {{> ...}} inside the inlined block also resolve.
            return $this->resolvePartials($resolved, $workflow, $depth + 1, $visited);
        }, $template);
    }
    
    /**
     * Resolve a single partial name to its content, or null if not found.
     */
    private function resolvePartialName(string $name, array $workflow): ?string {
        // Namespaced: command.X / command.$relatedCommands
        if (str_starts_with($name, 'command.')) {
            $cmd = substr($name, strlen('command.'));
            
            if ($cmd === '$relatedCommands') {
                $list = $workflow['relatedCommands'] ?? [];
                if (!is_array($list) || empty($list)) {
                    error_log('[WorkflowManager] {{> command.$relatedCommands}} used but workflow has no relatedCommands');
                    return '';
                }
                $blocks = [];
                foreach ($list as $cmdName) {
                    if (!is_string($cmdName)) { continue; }
                    $help = $this->resolveHelpForCommand($cmdName);
                    if ($help === null) {
                        $blocks[] = "### **{$cmdName}**\n\n_(help data unavailable)_";
                        continue;
                    }
                    $blocks[] = $this->formatCommandFull($cmdName, $help, true);
                }
                return implode("\n\n---\n\n", $blocks);
            }
            
            $help = $this->resolveHelpForCommand($cmd);
            if ($help === null) {
                error_log("[WorkflowManager] {{> command.{$cmd}}} — no help data found");
                return null;
            }
            return $this->formatCommandFull($cmd, $help, true);
        }
        
        // File-backed: pin.X, warning.X, example.X, or bare blockname
        $path = $this->partialFilePath($name);
        if ($path === null) {
            error_log("[WorkflowManager] {{> {$name}}} — invalid partial name");
            return null;
        }
        if (!file_exists($path)) {
            error_log("[WorkflowManager] {{> {$name}}} — file not found at {$path}");
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : rtrim($content, "\r\n");
    }
    
    /**
     * Map a partial name to its on-disk file path.
     * Returns null for names that don't map to a file (e.g. command.* — handled separately).
     */
    private function partialFilePath(string $name): ?string {
        $base = $this->workflowsBasePath;
        if (str_starts_with($name, 'pin.')) {
            return $base . '/pins/' . substr($name, 4) . '.md';
        }
        if (str_starts_with($name, 'warning.')) {
            return $base . '/warnings/' . substr($name, 8) . '.md';
        }
        if (str_starts_with($name, 'example.')) {
            return $base . '/examples/' . substr($name, 8) . '.md';
        }
        if (str_contains($name, '.')) {
            // Unknown namespace
            return null;
        }
        return $base . '/blocks/' . $name . '.md';
    }
    
    /**
     * Resolve $each loops in params
     * Used for generating arrays from config values like LANGUAGES_NAME or param values
     */
    private function resolveEachLoops(array $params, array $context): array {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                // Check if this is an $each loop
                if (isset($value['$each'])) {
                    $source = $this->resolveStepParamValue($value['$each'], $context);
                    $template = $value['$item'] ?? [];
                    
                    // If source is a comma-separated string, convert to array
                    if (is_string($source) && !empty($source)) {
                        $source = array_map('trim', explode(',', $source));
                    }
                    
                    if (is_array($source) && !empty($source)) {
                        $params[$key] = [];
                        foreach ($source as $itemKey => $itemValue) {
                            // Create context with $key and $value
                            $itemContext = array_merge($context, [
                                '$key' => $itemKey,
                                '$value' => $itemValue
                            ]);
                            $resolvedItem = $this->resolveStepParams($template, $itemContext);
                            // Apply $each recursively for nested loops and resolve value filters
                            $resolvedItem = $this->resolveEachLoops($resolvedItem, $itemContext);
                            $params[$key][] = $resolvedItem;
                        }
                    } else {
                        // Source not resolved yet (might depend on AI output)
                        // Leave the $each intact for late resolution
                        $params[$key] = $value;
                    }
                } else {
                    // Recurse into nested objects
                    $params[$key] = $this->resolveEachLoops($value, $context);
                }
            }
        }
        
        return $params;
    }
}
