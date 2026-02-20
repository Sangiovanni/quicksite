<?php
/**
 * WorkflowManager - Manages workflow specification loading, validation, and rendering
 * 
 * This class handles:
 * - Loading workflow JSON files from core/ and custom/ directories
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
     * Get all available workflows (core + custom)
     * 
     * @return array List of workflow metadata
     */
    public function listWorkflows(): array {
        $workflows = [];
        
        // Load core workflows
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
        
        // Load custom workflows
        $customDir = $this->workflowsBasePath . '/custom';
        if (is_dir($customDir)) {
            foreach (glob($customDir . '/*.json') as $file) {
                $workflow = $this->loadWorkflow(basename($file, '.json'));
                if ($workflow) {
                    $workflow['_source'] = 'custom';
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
        
        // Try core first, then custom
        $paths = [
            $this->workflowsBasePath . '/core/' . $workflowId . '.json',
            $this->workflowsBasePath . '/custom/' . $workflowId . '.json'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $workflow = json_decode($content, true);
                
                if ($workflow && isset($workflow['id'])) {
                    $workflow['_filePath'] = $path;
                    $workflow['_folder'] = dirname($path);
                    $this->workflowsCache[$workflowId] = $workflow;
                    return $workflow;
                }
            }
        }
        
        return null;
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
                    $resolvedStep = [
                        'command' => $step['command'],
                        'method' => $step['method'] ?? 'POST',
                        'params' => $this->resolveStepParams($step['params'] ?? [], $context)
                    ];
                    
                    if (isset($step['abortOnFail'])) {
                        $resolvedStep['abortOnFail'] = $step['abortOnFail'];
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
            if (is_string($subWorkflow)) {
                $workflowId = $subWorkflow;
                $subParams = [];
            } else {
                $workflowId = $subWorkflow['id'] ?? null;
                $subParams = $subWorkflow['params'] ?? [];
            }
            
            if (!$workflowId) {
                continue;
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
            
            $resolvedStep = [
                'command' => $step['command'],
                'method' => $step['method'] ?? 'POST',
                'params' => $this->resolveStepParams($step['params'] ?? [], $itemContext)
            ];
            
            if (isset($step['abortOnFail'])) {
                $resolvedStep['abortOnFail'] = $step['abortOnFail'];
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
            
            // Parse the filter expression
            // Examples: "$key != 'home'", "$value.type == 'image'"
            
            if (preg_match('/^(\$\w+(?:\.\w+)?)\s*(===?|!==?|!=)\s*(.+)$/', $normalizedFilter, $matches)) {
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
                
                $result = match($operator) {
                    '=', '==' => $leftResolved == $rightParsed,
                    '===' => $leftResolved === $rightParsed,
                    '!=', '!==' => $leftResolved !== $rightParsed,
                    default => false
                };
                
                if ($result) {
                    $filtered[$key] = $value;
                }
            } else {
                // No operator - truthy check
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
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
                $resolved = $this->resolvePathWithFilters($path, $context, $value);
                return $resolved;
            }
            
            // Handle inline {{placeholders}} in strings (not containing nested braces)
            return preg_replace_callback('/\{\{([^{}]+)\}\}/', function($matches) use ($context) {
                $path = trim($matches[1]);
                $resolved = $this->resolvePathWithFilters($path, $context, $matches[0]);
                return is_scalar($resolved) ? $resolved : json_encode($resolved);
            }, $value);
        }
        
        if (is_array($value)) {
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
        // Handle negation
        $negate = false;
        if (str_starts_with($condition, '!')) {
            $negate = true;
            $condition = substr($condition, 1);
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
                // Try custom folder
                $customPath = $this->workflowsBasePath . '/custom/' . $template;
                if (file_exists($customPath)) {
                    $template = file_get_contents($customPath);
                } else {
                    return "Error: Template file not found: $template";
                }
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
        
        // First pass: Handle {{#if}} conditionals
        $template = $this->processConditionals($template, $userParams, $fetchedData);
        
        // Second pass: Replace {{#each}} loops
        $template = $this->processEachLoops($template, $fetchedData);
        
        // Third pass: Replace direct data references {{data.xxx}}
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
        
        // Fourth pass: Replace {{param.xxx}} user parameters
        $template = preg_replace_callback('/\{\{param\.(\w+)\}\}/', function($matches) use ($userParams) {
            $key = $matches[1];
            return $userParams[$key] ?? '';
        }, $template);
        
        // Fifth pass: Replace {{helpers.xxx}} - static helper values
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
            return $userParams[$key] ?? null;
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
     * Process {{#each}} loops in template
     */
    private function processEachLoops(string $template, array $fetchedData): string {
        // Pattern: {{#each data.xxx}}...{{this}} or {{@key}} or {{this.field}}...{{/each}}
        $pattern = '/\{\{#each\s+data\.(\w+)\}\}(.*?)\{\{\/each\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($fetchedData) {
            $dataKey = $matches[1];
            $loopContent = $matches[2];
            
            if (!isset($fetchedData[$dataKey]) || !is_array($fetchedData[$dataKey])) {
                return '';
            }
            
            $items = $fetchedData[$dataKey];
            $output = '';
            
            foreach ($items as $key => $item) {
                $itemOutput = $loopContent;
                
                // Replace {{@key}}
                $itemOutput = str_replace('{{@key}}', $key, $itemOutput);
                
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
            
            if (!isset($meta['titleKey'])) {
                $errors[] = "Missing required meta field: titleKey";
            }
            if (!isset($meta['category'])) {
                $errors[] = "Missing required meta field: category";
            } else {
                // Validate category value
                $validCategories = ['creation', 'modification', 'advanced', 'style', 'template'];
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
                if (!isset($step['command'])) {
                    $errors[] = "Step at index {$index} missing required field: command";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
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
     * Format helper for commands in prompt (kept for backward compatibility)
     */
    public function formatCommand(string $command, array $params): string {
        $parts = ["### **{$command}**"];
        foreach ($params as $key => $value) {
            $formattedValue = is_array($value) 
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : (string)$value;
            $parts[] = "- **{$key}**: {$formattedValue}";
        }
        return implode("\n", $parts);
    }
    
    // ========== Legacy Aliases for backward compatibility ==========
    
    /**
     * @deprecated Use listWorkflows() instead
     */
    public function listSpecs(): array {
        return $this->listWorkflows();
    }
    
    /**
     * @deprecated Use loadWorkflow() instead
     */
    public function loadSpec(string $specId): ?array {
        return $this->loadWorkflow($specId);
    }
    
    /**
     * @deprecated Use validateWorkflow() instead
     */
    public function validateSpec(array $spec): array {
        return $this->validateWorkflow($spec);
    }
    
    /**
     * Generate commands from preCommands or postCommands array in AI workflows
     * 
     * This is similar to generateSteps but for AI workflow pre/post commands
     * 
     * @param array $workflow The workflow definition
     * @param array $userParams User-provided parameters
     * @param string $type Either 'preCommands' or 'postCommands'
     * @return array Generated command list
     */
    public function generateSpecCommands(array $workflow, array $userParams, string $type = 'preCommands'): array {
        $commands = $workflow[$type] ?? [];
        if (empty($commands)) {
            return [];
        }
        
        // Build context for resolution
        $config = [];
        $configFile = PUBLIC_FOLDER_ROOT . '/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
        }
        
        $context = [
            'param' => $userParams,
            'config' => $config
        ];
        
        $result = [];
        
        foreach ($commands as $cmd) {
            // Check condition
            if (isset($cmd['condition'])) {
                if (!$this->evaluateStepCondition($cmd['condition'], $context)) {
                    continue;
                }
            }
            
            // Handle template reference
            if (isset($cmd['template'])) {
                // Templates are deprecated - skip
                continue;
            }
            
            // Regular command
            if (isset($cmd['command'])) {
                $resolvedParams = $this->resolveStepParams($cmd['params'] ?? [], $context);
                
                // Handle $each loops in params (for dynamic arrays from config)
                $resolvedParams = $this->resolveEachLoops($resolvedParams, $context);
                
                $result[] = [
                    'command' => $cmd['command'],
                    'method' => $cmd['method'] ?? 'POST',
                    'params' => $resolvedParams
                ];
            }
        }
        
        return $result;
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
