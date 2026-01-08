<?php
/**
 * AiSpecManager - Manages AI specification loading, validation, and rendering
 * 
 * This class handles:
 * - Loading spec JSON files from core/ and custom/ directories
 * - Validating specs against the JSON schema
 * - Fetching data requirements via CommandRunner
 * - Rendering prompt templates with variable substitution
 * 
 * @package QuickSite\Admin
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CommandRunner.php';

class AiSpecManager {
    
    /** @var string Base path for AI specs */
    private string $specsBasePath;
    
    /** @var array Cached specs */
    private array $specsCache = [];
    
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
        'hu' => 'Magyar'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->specsBasePath = SECURE_FOLDER_PATH . '/admin/ai_specs';
    }
    
    /**
     * Get all available specs (core + custom)
     * 
     * @return array List of spec metadata
     */
    public function listSpecs(): array {
        $specs = [];
        
        // Load core specs
        $coreDir = $this->specsBasePath . '/core';
        if (is_dir($coreDir)) {
            foreach (glob($coreDir . '/*.json') as $file) {
                $spec = $this->loadSpec(basename($file, '.json'));
                if ($spec) {
                    $spec['_source'] = 'core';
                    $specs[] = $spec;
                }
            }
        }
        
        // Load custom specs
        $customDir = $this->specsBasePath . '/custom';
        if (is_dir($customDir)) {
            foreach (glob($customDir . '/*.json') as $file) {
                $spec = $this->loadSpec(basename($file, '.json'));
                if ($spec) {
                    $spec['_source'] = 'custom';
                    $specs[] = $spec;
                }
            }
        }
        
        return $specs;
    }
    
    /**
     * Load a spec by ID
     * 
     * @param string $specId Spec identifier
     * @return array|null Spec data or null if not found
     */
    public function loadSpec(string $specId): ?array {
        // Check cache
        if (isset($this->specsCache[$specId])) {
            return $this->specsCache[$specId];
        }
        
        // Try core first, then custom
        $paths = [
            $this->specsBasePath . '/core/' . $specId . '.json',
            $this->specsBasePath . '/custom/' . $specId . '.json'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $spec = json_decode($content, true);
                
                if ($spec && isset($spec['id'])) {
                    $spec['_filePath'] = $path;
                    $spec['_folder'] = dirname($path);
                    $this->specsCache[$specId] = $spec;
                    return $spec;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Fetch all data requirements for a spec
     * 
     * @param array $spec The spec definition
     * @param array $userParams User-provided parameters (for condition evaluation)
     * @return array Fetched data keyed by requirement ID
     */
    public function fetchDataRequirements(array $spec, array $userParams = []): array {
        $data = [];
        
        if (!isset($spec['dataRequirements'])) {
            return $data;
        }
        
        foreach ($spec['dataRequirements'] as $req) {
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
     * Evaluate a condition for data requirements
     * 
     * @param string $condition Condition string (param name or expression)
     * @param array $userParams User parameters
     * @return bool Whether the condition is met
     */
    private function evaluateDataCondition(string $condition, array $userParams): bool {
        // Check for comparison operators
        if (preg_match('/^(.+?)\s*(===|!==|==|!=)\s*(.+)$/', $condition, $parts)) {
            $paramName = trim($parts[1]);
            $operator = $parts[2];
            $compareValue = trim($parts[3]);
            
            $paramValue = $userParams[$paramName] ?? null;
            
            // Normalize values for comparison
            if ($compareValue === 'true') $compareValue = true;
            elseif ($compareValue === 'false') $compareValue = false;
            elseif ($compareValue === 'null') $compareValue = null;
            
            if ($paramValue === 'true') $paramValue = true;
            elseif ($paramValue === 'false') $paramValue = false;
            
            switch ($operator) {
                case '===':
                    return $paramValue === $compareValue;
                case '!==':
                    return $paramValue !== $compareValue;
                case '==':
                    return $paramValue == $compareValue;
                case '!=':
                    return $paramValue != $compareValue;
            }
        }
        
        // Simple check: does the param exist and have a truthy value?
        $value = $userParams[$condition] ?? null;
        return !empty($value) && $value !== 'false';
    }
    
    /**
     * Load and render the prompt template for a spec
     * 
     * @param array $spec The spec definition
     * @param array $data Data from dataRequirements
     * @param array $params User-provided parameters
     * @return string Rendered prompt
     */
    public function renderPrompt(array $spec, array $data, array $params = []): string {
        // Load template file
        $templateFile = $spec['promptTemplate'] ?? $spec['id'] . '.md';
        $templatePath = $spec['_folder'] . '/' . $templateFile;
        
        if (!file_exists($templatePath)) {
            return "Error: Template file not found: $templateFile";
        }
        
        $template = file_get_contents($templatePath);
        
        // Prepare context for rendering
        $context = array_merge($data, [
            'param' => $params,
            'spec' => $spec
        ]);
        
        // Add source translations helper
        if (isset($data['translations']) && isset($data['defaultLang'])) {
            $context['sourceTranslations'] = $data['translations'][$data['defaultLang']] ?? [];
        }
        
        // Render the template
        return $this->renderTemplate($template, $context);
    }
    
    /**
     * Render a template string directly (for preview/editing)
     * 
     * @param string $template Template string
     * @param array $data Data context
     * @param array $params Optional user parameters
     * @return string Rendered string
     */
    public function renderTemplateString(string $template, array $data, array $params = []): string {
        // Prepare context for rendering
        $context = array_merge($data, [
            'param' => $params
        ]);
        
        // Add source translations helper
        if (isset($data['translations']) && isset($data['defaultLang'])) {
            $context['sourceTranslations'] = $data['translations'][$data['defaultLang']] ?? [];
        }
        
        // Render the template
        return $this->renderTemplate($template, $context);
    }
    
    /**
     * Render a template string with context data
     * 
     * Supports:
     * - {{variable}} - Simple variable substitution
     * - {{object.property}} - Dot notation access
     * - {{#if condition}}...{{else}}...{{/if}} - Conditionals
     *   - Simple: {{#if variableName}} (truthiness check)
     *   - Equality: {{#if var === value}}, {{#if var == value}}
     *   - Inequality: {{#if var !== value}}, {{#if var != value}}
     *   - Literals: true, false, null, "string", 123
     * - {{#each array}}...{{/each}} - Iteration
     * - {{json variable}} - JSON encode
     * - {{join array "separator"}} - Join array with separator
     * - {{langName code}} - Get language name
     * - {{formatCommand cmdData}} - Format command documentation
     * 
     * @param string $template Template string
     * @param array $context Data context
     * @return string Rendered string
     */
    public function renderTemplate(string $template, array $context): string {
        // Process {{#each ...}}...{{/each}} blocks first
        $template = $this->processEachBlocks($template, $context);
        
        // Process {{#if ...}}...{{/if}} blocks
        $template = $this->processIfBlocks($template, $context);
        
        // Process {{json ...}} helpers
        $template = preg_replace_callback(
            '/\{\{json\s+([^}]+)\}\}/',
            function ($matches) use ($context) {
                $value = $this->resolveValue(trim($matches[1]), $context);
                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            $template
        );
        
        // Process {{join array "separator"}} helpers
        $template = preg_replace_callback(
            '/\{\{join\s+([^\s}]+)\s+"([^"]*)"\}\}/',
            function ($matches) use ($context) {
                $value = $this->resolveValue(trim($matches[1]), $context);
                $separator = $matches[2];
                if (is_array($value)) {
                    return implode($separator, $value);
                }
                return is_string($value) ? $value : '';
            },
            $template
        );
        
        // Process {{langName ...}} helpers
        $template = preg_replace_callback(
            '/\{\{langName\s+([^}]+)\}\}/',
            function ($matches) use ($context) {
                $code = $this->resolveValue(trim($matches[1]), $context);
                return self::$languageNames[$code] ?? strtoupper($code);
            },
            $template
        );
        
        // Process {{formatCommand ...}} helpers
        $template = preg_replace_callback(
            '/\{\{formatCommand\s+([^}]+)\}\}/',
            function ($matches) use ($context) {
                $cmdData = $this->resolveValue(trim($matches[1]), $context);
                return $this->formatCommandDocumentation($cmdData);
            },
            $template
        );
        
        // Process simple {{variable}} and {{object.property}} substitutions
        $template = preg_replace_callback(
            '/\{\{([^#\/][^}]*)\}\}/',
            function ($matches) use ($context) {
                $key = trim($matches[1]);
                
                // Skip escaped/preserved braces:
                // - QuickSite placeholders: {{__current_page;lang=xx}}
                // - Component variable examples: {{$icon}}, {{$title}} ($ prefix)
                // - Nested braces
                if (strpos($key, '__') === 0 || strpos($key, '$') === 0 || strpos($key, '{') !== false) {
                    // For $-prefixed, strip the $ but keep the braces
                    if (strpos($key, '$') === 0) {
                        return '{{' . substr($key, 1) . '}}';
                    }
                    return $matches[0];
                }
                
                $value = $this->resolveValue($key, $context);
                
                if (is_array($value)) {
                    return implode(', ', $value);
                }
                
                return (string) $value;
            },
            $template
        );
        
        return $template;
    }
    
    /**
     * Process {{#each}} blocks
     */
    private function processEachBlocks(string $template, array $context): string {
        $pattern = '/\{\{#each\s+([^}]+)\}\}(.*?)\{\{\/each\}\}/s';
        
        return preg_replace_callback($pattern, function ($matches) use ($context) {
            $arrayKey = trim($matches[1]);
            $innerTemplate = $matches[2];
            $array = $this->resolveValue($arrayKey, $context);
            
            if (!is_array($array)) {
                return '';
            }
            
            $result = '';
            $count = count($array);
            $index = 0;
            
            foreach ($array as $key => $item) {
                $itemContext = $context;
                $itemContext['this'] = $item;
                $itemContext['@key'] = $key;
                $itemContext['@index'] = $index;
                $itemContext['@first'] = ($index === 0);
                $itemContext['@last'] = ($index === $count - 1);
                
                // Process nested blocks first
                $rendered = $this->processEachBlocks($innerTemplate, $itemContext);
                $rendered = $this->processIfBlocks($rendered, $itemContext);
                
                // Simple variable replacement within loop
                $rendered = preg_replace_callback(
                    '/\{\{([^#\/][^}]*)\}\}/',
                    function ($m) use ($itemContext) {
                        $key = trim($m[1]);
                        // Skip preserved variables: __, $-prefixed, or nested braces
                        if (strpos($key, '__') === 0 || strpos($key, '$') === 0 || strpos($key, '{') !== false) {
                            if (strpos($key, '$') === 0) {
                                return '{{' . substr($key, 1) . '}}';
                            }
                            return $m[0];
                        }
                        $value = $this->resolveValue($key, $itemContext);
                        if (is_array($value)) {
                            return implode(', ', $value);
                        }
                        return (string) $value;
                    },
                    $rendered
                );
                
                $result .= $rendered;
                $index++;
            }
            
            return $result;
        }, $template);
    }
    
    /**
     * Process {{#if}} blocks
     * 
     * Supports:
     * - Simple truthiness: {{#if variableName}}
     * - Equality: {{#if variable === value}}, {{#if variable == value}}
     * - Inequality: {{#if variable !== value}}, {{#if variable != value}}
     * - Boolean comparison: {{#if param.multilingual === true}}
     */
    private function processIfBlocks(string $template, array $context): string {
        // Handle if/else/endif with proper nesting support
        // Find {{#if ...}} and match to correct {{/if}} considering nesting
        
        $offset = 0;
        while (preg_match('/\{\{#if\s+([^}]+)\}\}/s', $template, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $condition = trim($match[1][0]);
            $startPos = $match[0][1];
            $afterOpenTag = $startPos + strlen($match[0][0]);
            
            // Find matching {{/if}} considering nested blocks
            $depth = 1;
            $pos = $afterOpenTag;
            $endPos = null;
            $elsePos = null;
            
            while ($depth > 0 && $pos < strlen($template)) {
                // Find next {{#if, {{else}}, or {{/if}}
                $nextIf = strpos($template, '{{#if', $pos);
                $nextElse = strpos($template, '{{else}}', $pos);
                $nextEndIf = strpos($template, '{{/if}}', $pos);
                
                // Determine which comes first
                $positions = [];
                if ($nextIf !== false) $positions['if'] = $nextIf;
                if ($nextElse !== false) $positions['else'] = $nextElse;
                if ($nextEndIf !== false) $positions['endif'] = $nextEndIf;
                
                if (empty($positions)) {
                    break; // No more tags found, malformed template
                }
                
                $minPos = min($positions);
                $minType = array_search($minPos, $positions);
                
                if ($minType === 'if') {
                    $depth++;
                    $pos = $minPos + 5; // Skip past {{#if
                } elseif ($minType === 'else') {
                    if ($depth === 1 && $elsePos === null) {
                        $elsePos = $minPos; // Only capture else at current depth
                    }
                    $pos = $minPos + 8; // Skip past {{else}}
                } elseif ($minType === 'endif') {
                    $depth--;
                    if ($depth === 0) {
                        $endPos = $minPos;
                    }
                    $pos = $minPos + 7; // Skip past {{/if}}
                }
            }
            
            if ($endPos === null) {
                // No matching {{/if}} found, skip this tag
                $offset = $afterOpenTag;
                continue;
            }
            
            // Extract blocks
            if ($elsePos !== null) {
                $trueBlock = substr($template, $afterOpenTag, $elsePos - $afterOpenTag);
                $falseBlock = substr($template, $elsePos + 8, $endPos - $elsePos - 8);
            } else {
                $trueBlock = substr($template, $afterOpenTag, $endPos - $afterOpenTag);
                $falseBlock = '';
            }
            
            // Evaluate condition
            $isTruthy = $this->evaluateCondition($condition, $context);
            $result = $isTruthy ? $trueBlock : $falseBlock;
            
            // Recursively process nested blocks in the result
            $result = $this->processIfBlocks($result, $context);
            
            // Replace in template
            $fullMatch = substr($template, $startPos, $endPos + 7 - $startPos);
            $template = substr_replace($template, $result, $startPos, strlen($fullMatch));
            
            // Continue from after the replacement
            $offset = $startPos + strlen($result);
        }
        
        return $template;
    }
    
    /**
     * Evaluate a condition expression
     * 
     * @param string $condition Condition string
     * @param array $context Data context
     * @return bool Whether the condition is truthy
     */
    private function evaluateCondition(string $condition, array $context): bool {
        // Check for comparison operators
        if (preg_match('/^(.+?)\s*(===|!==|==|!=)\s*(.+)$/', $condition, $parts)) {
            $leftExpr = trim($parts[1]);
            $operator = $parts[2];
            $rightExpr = trim($parts[3]);
            
            $leftValue = $this->resolveConditionValue($leftExpr, $context);
            $rightValue = $this->resolveConditionValue($rightExpr, $context);
            
            switch ($operator) {
                case '===':
                    return $leftValue === $rightValue;
                case '!==':
                    return $leftValue !== $rightValue;
                case '==':
                    return $leftValue == $rightValue;
                case '!=':
                    return $leftValue != $rightValue;
            }
        }
        
        // Simple truthiness check
        $value = $this->resolveValue($condition, $context);
        return !empty($value) && $value !== false && $value !== null && $value !== 'false';
    }
    
    /**
     * Resolve a value in a condition expression
     * Handles literals (true, false, null, strings, numbers) and variables
     */
    private function resolveConditionValue(string $expr, array $context) {
        $expr = trim($expr);
        
        // Boolean literals
        if ($expr === 'true') return true;
        if ($expr === 'false') return false;
        if ($expr === 'null') return null;
        
        // String literals (single or double quotes)
        if (preg_match('/^["\'](.*)["\']\s*$/', $expr, $m)) {
            return $m[1];
        }
        
        // Numeric literals
        if (is_numeric($expr)) {
            return strpos($expr, '.') !== false ? (float)$expr : (int)$expr;
        }
        
        // Variable - resolve from context
        $value = $this->resolveValue($expr, $context);
        
        // Normalize string "true"/"false" to actual booleans for comparison
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        return $value;
    }
    
    /**
     * Resolve a dot-notation value from context
     * 
     * @param string $key Dot-notation key (e.g., "data.languages" or "param.targetLanguage")
     * @param array $context Data context
     * @return mixed Resolved value
     */
    private function resolveValue(string $key, array $context) {
        // Handle special @variables
        if (strpos($key, '@') === 0) {
            return $context[$key] ?? null;
        }
        
        // Handle 'this' keyword
        if ($key === 'this') {
            return $context['this'] ?? null;
        }
        
        $parts = explode('.', $key);
        $current = $context;
        
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } elseif (is_object($current) && isset($current->$part)) {
                $current = $current->$part;
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Format command documentation from help data
     * 
     * @param array|null $cmdData Command data from help command
     * @return string Formatted documentation
     */
    private function formatCommandDocumentation(?array $cmdData): string {
        if (!$cmdData || isset($cmdData['_error'])) {
            return '*Command documentation not available*';
        }
        
        $output = '';
        
        if (isset($cmdData['description'])) {
            $output .= $cmdData['description'] . "\n\n";
        }
        
        if (isset($cmdData['method'])) {
            $output .= "**Method:** `{$cmdData['method']}`\n\n";
        }
        
        if (isset($cmdData['parameters']) && !empty($cmdData['parameters'])) {
            $output .= "**Parameters:**\n";
            foreach ($cmdData['parameters'] as $name => $param) {
                $required = ($param['required'] ?? false) ? '*(required)*' : '*(optional)*';
                $type = $param['type'] ?? 'mixed';
                $desc = $param['description'] ?? '';
                $output .= "- `$name` ($type) $required - $desc\n";
            }
            $output .= "\n";
        }
        
        if (isset($cmdData['example_post'])) {
            $output .= "**Example:**\n```\n{$cmdData['example_post']}\n```\n";
        } elseif (isset($cmdData['example_patch'])) {
            $output .= "**Example:**\n```\n{$cmdData['example_patch']}\n```\n";
        }
        
        return $output;
    }
    
    /**
     * Get specs grouped by category
     * 
     * @return array Specs grouped by category
     */
    public function getSpecsByCategory(): array {
        $specs = $this->listSpecs();
        $grouped = [];
        
        foreach ($specs as $spec) {
            $category = $spec['meta']['category'] ?? 'other';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $spec;
        }
        
        return $grouped;
    }
    
    /**
     * Get project configuration
     * 
     * @return array Project config from config.php
     */
    public function getProjectConfig(): array {
        $configPath = PROJECT_PATH . '/config.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }
        return [];
    }
    
    /**
     * Generate pre/post commands for a spec
     * 
     * @param array $spec The spec definition
     * @param array $userParams User-provided parameters
     * @param string $type 'preCommands' or 'postCommands'
     * @return array Array of resolved command objects ready for execution
     */
    public function generateSpecCommands(array $spec, array $userParams, string $type): array {
        $commands = $spec[$type] ?? [];
        if (empty($commands)) {
            return [];
        }
        
        $config = $this->getProjectConfig();
        $result = [];
        
        foreach ($commands as $cmdDef) {
            // Evaluate condition
            if (isset($cmdDef['condition'])) {
                if (!$this->evaluateSpecCommandCondition($cmdDef['condition'], $userParams, $config)) {
                    continue;
                }
            }
            
            // Handle template type
            if (!empty($cmdDef['template'])) {
                $result[] = [
                    'type' => 'template',
                    'template' => $cmdDef['template'],
                    'params' => $cmdDef['params'] ?? []
                ];
                continue;
            }
            
            // Handle command type
            if (!empty($cmdDef['command'])) {
                $resolvedParams = $this->resolveCommandParams($cmdDef['params'] ?? [], $userParams, $config);
                $result[] = [
                    'type' => 'command',
                    'command' => $cmdDef['command'],
                    'params' => $resolvedParams
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Evaluate a condition for pre/post commands
     * 
     * @param string $condition Condition expression
     * @param array $userParams User parameters (param.X)
     * @param array $config Project config (config.X)
     * @return bool Whether condition is met
     */
    private function evaluateSpecCommandCondition(string $condition, array $userParams, array $config): bool {
        // Replace param.X and config.X with actual values
        $context = [
            'param' => $userParams,
            'config' => $config
        ];
        
        // Check for comparison operators
        if (preg_match('/^(.+?)\s*(===|!==|==|!=)\s*(.+)$/', $condition, $parts)) {
            $leftExpr = trim($parts[1]);
            $operator = $parts[2];
            $rightExpr = trim($parts[3]);
            
            $leftValue = $this->resolveConditionValue($leftExpr, $context);
            $rightValue = $this->resolveConditionValue($rightExpr, $context);
            
            switch ($operator) {
                case '===':
                    return $leftValue === $rightValue;
                case '!==':
                    return $leftValue !== $rightValue;
                case '==':
                    return $leftValue == $rightValue;
                case '!=':
                    return $leftValue != $rightValue;
            }
        }
        
        // Simple truthiness check
        $value = $this->resolveValue($condition, $context);
        return !empty($value) && $value !== false && $value !== null && $value !== 'false';
    }
    
    /**
     * Resolve command parameters, handling $each loops and {{config.X}} placeholders
     * 
     * @param array $params Raw params from spec
     * @param array $userParams User parameters
     * @param array $config Project config
     * @return array Resolved params
     */
    private function resolveCommandParams(array $params, array $userParams, array $config): array {
        $context = [
            'param' => $userParams,
            'config' => $config
        ];
        
        return $this->resolveParamsRecursive($params, $context);
    }
    
    /**
     * Recursively resolve parameters, handling $each loops
     * 
     * @param mixed $data Data to resolve
     * @param array $context Resolution context
     * @return mixed Resolved data
     */
    private function resolveParamsRecursive($data, array $context) {
        if (is_string($data)) {
            // Resolve {{config.X}} and {{param.X}} placeholders
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $key = trim($matches[1]);
                    // Skip QuickSite placeholders ({{__current_page}}, etc.)
                    if (strpos($key, '__') === 0) {
                        return $matches[0];
                    }
                    $value = $this->resolveValue($key, $context);
                    return is_string($value) ? $value : json_encode($value);
                },
                $data
            );
        }
        
        if (!is_array($data)) {
            return $data;
        }
        
        // Check for $each loop
        if (isset($data['$each']) && isset($data['$item'])) {
            return $this->processEachLoop($data, $context);
        }
        
        // Recursively process array
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->resolveParamsRecursive($value, $context);
        }
        return $result;
    }
    
    /**
     * Process a $each loop to generate array items
     * 
     * @param array $loopDef Loop definition with $each and $item
     * @param array $context Resolution context
     * @return array Generated items
     */
    private function processEachLoop(array $loopDef, array $context): array {
        $sourceExpr = $loopDef['$each'];
        $itemTemplate = $loopDef['$item'];
        
        // Resolve the source (e.g., {{config.LANGUAGES_NAME}})
        $source = null;
        if (preg_match('/^\{\{([^}]+)\}\}$/', $sourceExpr, $matches)) {
            $source = $this->resolveValue(trim($matches[1]), $context);
        }
        
        // Special case: if LANGUAGES_NAME is empty but LANGUAGES_SUPPORTED exists,
        // auto-populate LANGUAGES_NAME with display names
        if ($sourceExpr === '{{config.LANGUAGES_NAME}}' && (empty($source) || !is_array($source))) {
            $supported = $context['config']['LANGUAGES_SUPPORTED'] ?? [];
            if (!empty($supported)) {
                $commonLanguages = [
                    'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch',
                    'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'ru' => 'Русский',
                    'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어', 'ar' => 'العربية'
                ];
                $source = [];
                foreach ($supported as $langCode) {
                    $source[$langCode] = $commonLanguages[$langCode] ?? ucfirst($langCode);
                }
            }
        }
        
        if (!is_array($source)) {
            return [];
        }
        
        $result = [];
        foreach ($source as $key => $value) {
            // Create item context with $key and $value
            $itemContext = array_merge($context, [
                '$key' => $key,
                '$value' => $value
            ]);
            
            // Resolve the item template
            $item = $this->resolveItemTemplate($itemTemplate, $itemContext);
            $result[] = $item;
        }
        
        return $result;
    }
    
    /**
     * Resolve an item template within a $each loop
     * 
     * @param mixed $template Template to resolve
     * @param array $context Context with $key and $value
     * @return mixed Resolved template
     */
    private function resolveItemTemplate($template, array $context) {
        if (is_string($template)) {
            // First pass: resolve inner placeholders like {{$key}}, {{$value}} inside other placeholders
            // This handles cases like {{__current_page;lang={{$key}}}}
            $template = preg_replace_callback(
                '/\{\{(\$key|\$value)\}\}/',
                function ($matches) use ($context) {
                    $key = $matches[1];
                    if (isset($context[$key])) {
                        $value = $context[$key];
                        return is_string($value) ? $value : (is_scalar($value) ? (string)$value : json_encode($value));
                    }
                    return $matches[0];
                },
                $template
            );
            
            // Second pass: resolve remaining {{...}} placeholders (skip QuickSite __ placeholders)
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function ($matches) use ($context) {
                    $key = trim($matches[1]);
                    // Skip QuickSite placeholders (they start with __)
                    if (strpos($key, '__') === 0) {
                        return $matches[0];
                    }
                    $value = $this->resolveValue($key, $context);
                    return is_string($value) ? $value : (is_scalar($value) ? (string)$value : json_encode($value));
                },
                $template
            );
        }
        
        if (!is_array($template)) {
            return $template;
        }
        
        // Check for nested $each
        if (isset($template['$each']) && isset($template['$item'])) {
            return $this->processEachLoop($template, $context);
        }
        
        // Recursively process array
        $result = [];
        foreach ($template as $k => $v) {
            $result[$k] = $this->resolveItemTemplate($v, $context);
        }
        return $result;
    }
    
    /**
     * Get language name from code
     * 
     * @param string $code Language code
     * @return string Language name
     */
    public static function getLanguageName(string $code): string {
        return self::$languageNames[$code] ?? strtoupper($code);
    }
    
    /**
     * Validate a spec against the schema
     * 
     * @param array $spec Spec to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateSpec(array $spec): array {
        $errors = [];
        
        // Required fields
        $required = ['id', 'version', 'meta', 'dataRequirements', 'relatedCommands'];
        foreach ($required as $field) {
            if (!isset($spec[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }
        
        // Meta required fields
        if (isset($spec['meta'])) {
            $metaRequired = ['icon', 'titleKey', 'descriptionKey', 'category'];
            foreach ($metaRequired as $field) {
                if (!isset($spec['meta'][$field])) {
                    $errors[] = "Missing required meta field: $field";
                }
            }
            
            // Valid categories
            $validCategories = ['creation', 'modification', 'content', 'style', 'advanced', 'wip'];
            if (isset($spec['meta']['category']) && !in_array($spec['meta']['category'], $validCategories)) {
                $errors[] = "Invalid category: {$spec['meta']['category']}";
            }
        }
        
        // ID format
        if (isset($spec['id']) && !preg_match('/^[a-z][a-z0-9-]*$/', $spec['id'])) {
            $errors[] = "Invalid ID format: {$spec['id']} (must be kebab-case)";
        }
        
        // Version format
        if (isset($spec['version']) && !preg_match('/^\d+\.\d+\.\d+$/', $spec['version'])) {
            $errors[] = "Invalid version format: {$spec['version']} (must be semver)";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
