<?php
/**
 * JsFunctionManager
 * 
 * Manages custom JavaScript functions for the QS namespace.
 * Handles reading/writing function definitions to JSON config,
 * regenerating qs-custom.js, and updating the whitelist.
 * 
 * Storage: secure/projects/{project}/config/custom-js-functions.json (per-project)
 * Output: public/scripts/qs-custom.js (regenerated on project switch)
 */

class JsFunctionManager {
    
    /** @var string Path to custom functions JSON config */
    private string $configPath;
    
    /** @var string Path to generated qs-custom.js */
    private string $outputPath;
    
    /** @var array Core function names (cannot be overwritten) */
    private array $coreFunctions = [
        'show', 'hide', 'toggle', 'toggleHide', 'addClass', 'removeClass',
        'setValue', 'redirect', 'filter', 'scrollTo', 'focus', 'blur'
    ];
    
    /**
     * Constructor
     * @param string|null $projectPath Optional project path override (for switchProject)
     */
    public function __construct(?string $projectPath = null) {
        // Use provided project path or current PROJECT_PATH
        if ($projectPath !== null) {
            $basePath = $projectPath;
        } elseif (defined('PROJECT_PATH')) {
            $basePath = PROJECT_PATH;
        } else {
            // Fallback to old global location for backwards compatibility
            $basePath = SECURE_FOLDER_PATH;
        }
        
        // Project-specific config path
        $this->configPath = $basePath . '/config/custom-js-functions.json';
        
        // Output always goes to live public folder
        $this->outputPath = PUBLIC_FOLDER_ROOT . '/scripts/qs-custom.js';
        
        // Ensure config directory exists
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        // Initialize empty config if it doesn't exist
        if (!file_exists($this->configPath)) {
            $this->initializeConfig();
        }
    }
    
    /**
     * Initialize an empty config file
     * @return bool
     */
    private function initializeConfig(): bool {
        $data = [
            'version' => '1.0',
            'generated' => date('Y-m-d H:i:s'),
            'functions' => []
        ];
        
        return file_put_contents(
            $this->configPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }
    
    /**
     * Get the config path (useful for debugging/migration)
     * @return string
     */
    public function getConfigPath(): string {
        return $this->configPath;
    }
    
    /**
     * Migrate functions from old global config to current project
     * Call this manually if needed: JsFunctionManager::migrateFromGlobal()
     * @return array Migration result
     */
    public static function migrateFromGlobal(): array {
        $globalPath = SECURE_FOLDER_PATH . '/config/custom-js-functions.json';
        
        if (!file_exists($globalPath)) {
            return ['success' => false, 'reason' => 'No global config found'];
        }
        
        $globalContent = file_get_contents($globalPath);
        $globalData = json_decode($globalContent, true);
        $globalFunctions = $globalData['functions'] ?? [];
        
        if (empty($globalFunctions)) {
            return ['success' => true, 'reason' => 'No functions to migrate', 'migrated' => 0];
        }
        
        // Instantiate for current project
        $manager = new self();
        $existingFunctions = $manager->getCustomFunctions();
        
        // Merge (don't overwrite existing)
        $migrated = 0;
        foreach ($globalFunctions as $func) {
            $exists = false;
            foreach ($existingFunctions as $ef) {
                if ($ef['name'] === $func['name']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $manager->addFunction(
                    $func['name'],
                    $func['code'],
                    $func['description'] ?? ''
                );
                $migrated++;
            }
        }
        
        return [
            'success' => true,
            'migrated' => $migrated,
            'skipped' => count($globalFunctions) - $migrated,
            'config_path' => $manager->getConfigPath()
        ];
    }
    
    /**
     * Get list of core function names
     * @return array
     */
    public function getCoreFunctions(): array {
        return $this->coreFunctions;
    }
    
    /**
     * Get all custom functions
     * @return array Array of function definitions
     */
    public function getCustomFunctions(): array {
        if (!file_exists($this->configPath)) {
            return [];
        }
        
        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);
        
        return $data['functions'] ?? [];
    }
    
    /**
     * Get a specific custom function by name
     * @param string $name Function name
     * @return array|null Function definition or null if not found
     */
    public function getFunction(string $name): ?array {
        $functions = $this->getCustomFunctions();
        
        foreach ($functions as $func) {
            if ($func['name'] === $name) {
                return $func;
            }
        }
        
        return null;
    }
    
    /**
     * Check if a function name exists (core or custom)
     * @param string $name Function name
     * @return bool
     */
    public function functionExists(string $name): bool {
        // Check core functions
        if (in_array($name, $this->coreFunctions, true)) {
            return true;
        }
        
        // Check custom functions
        return $this->getFunction($name) !== null;
    }
    
    /**
     * Check if a function is a core function
     * @param string $name Function name
     * @return bool
     */
    public function isCoreFunction(string $name): bool {
        return in_array($name, $this->coreFunctions, true);
    }
    
    /**
     * Validate function name format
     * @param string $name Function name
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateFunctionName(string $name): array {
        // Must start with letter, contain only letters and numbers
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name)) {
            return [
                'valid' => false,
                'error' => 'Function name must start with a letter and contain only letters and numbers'
            ];
        }
        
        // Reasonable length
        if (strlen($name) < 2 || strlen($name) > 50) {
            return [
                'valid' => false,
                'error' => 'Function name must be between 2 and 50 characters'
            ];
        }
        
        // Reserved names
        $reserved = ['constructor', 'prototype', '__proto__', 'toString', 'valueOf'];
        if (in_array(strtolower($name), $reserved, true)) {
            return [
                'valid' => false,
                'error' => 'Function name is reserved'
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Basic JavaScript syntax validation
     * @param string $code JavaScript function body
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateJsSyntax(string $code): array {
        // Check for balanced braces
        $braceCount = 0;
        $parenCount = 0;
        $bracketCount = 0;
        $inString = false;
        $stringChar = '';
        $escaped = false;
        
        for ($i = 0; $i < strlen($code); $i++) {
            $char = $code[$i];
            
            if ($escaped) {
                $escaped = false;
                continue;
            }
            
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            
            if ($inString) {
                if ($char === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            
            if ($char === '"' || $char === "'" || $char === '`') {
                $inString = true;
                $stringChar = $char;
                continue;
            }
            
            switch ($char) {
                case '{': $braceCount++; break;
                case '}': $braceCount--; break;
                case '(': $parenCount++; break;
                case ')': $parenCount--; break;
                case '[': $bracketCount++; break;
                case ']': $bracketCount--; break;
            }
            
            if ($braceCount < 0 || $parenCount < 0 || $bracketCount < 0) {
                return [
                    'valid' => false,
                    'error' => 'Unbalanced brackets/braces/parentheses'
                ];
            }
        }
        
        if ($braceCount !== 0 || $parenCount !== 0 || $bracketCount !== 0) {
            return [
                'valid' => false,
                'error' => 'Unbalanced brackets/braces/parentheses'
            ];
        }
        
        // Check for obvious dangerous patterns (not exhaustive, just basic)
        $dangerousPatterns = [
            '/\beval\s*\(/i' => 'eval() is not allowed',
            '/\bFunction\s*\(/i' => 'Function() constructor is not allowed',
            '/document\s*\.\s*write/i' => 'document.write is not allowed',
            '/innerHTML\s*=/i' => 'innerHTML assignment is discouraged, use textContent',
        ];
        
        foreach ($dangerousPatterns as $pattern => $message) {
            if (preg_match($pattern, $code)) {
                return [
                    'valid' => false,
                    'error' => $message
                ];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Add a new custom function
     * @param array $functionDef Function definition with: name, args, body, description
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function addFunction(array $functionDef): array {
        $name = $functionDef['name'] ?? '';
        $args = $functionDef['args'] ?? [];
        $body = $functionDef['body'] ?? '';
        $description = $functionDef['description'] ?? '';
        
        // Validate name
        $nameValidation = $this->validateFunctionName($name);
        if (!$nameValidation['valid']) {
            return ['success' => false, 'error' => $nameValidation['error']];
        }
        
        // Check if name already exists
        if ($this->functionExists($name)) {
            return ['success' => false, 'error' => "Function '{$name}' already exists"];
        }
        
        // Validate JS syntax
        $syntaxValidation = $this->validateJsSyntax($body);
        if (!$syntaxValidation['valid']) {
            return ['success' => false, 'error' => 'Invalid JavaScript: ' . $syntaxValidation['error']];
        }
        
        // Load existing functions
        $functions = $this->getCustomFunctions();
        
        // Add new function
        $functions[] = [
            'name' => $name,
            'args' => $args,
            'body' => $body,
            'description' => $description,
            'created' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s')
        ];
        
        // Save
        $result = $this->saveFunctions($functions);
        if (!$result['success']) {
            return $result;
        }
        
        // Regenerate qs-custom.js
        return $this->regenerateJsFile();
    }
    
    /**
     * Edit an existing custom function
     * @param string $name Original function name
     * @param array $updates Fields to update (args, body, description, newName)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function editFunction(string $name, array $updates): array {
        // Cannot edit core functions
        if ($this->isCoreFunction($name)) {
            return ['success' => false, 'error' => "Cannot edit core function '{$name}'"];
        }
        
        // Get existing function
        $functions = $this->getCustomFunctions();
        $foundIndex = null;
        
        foreach ($functions as $index => $func) {
            if ($func['name'] === $name) {
                $foundIndex = $index;
                break;
            }
        }
        
        if ($foundIndex === null) {
            return ['success' => false, 'error' => "Function '{$name}' not found"];
        }
        
        // Handle rename
        if (isset($updates['newName']) && $updates['newName'] !== $name) {
            $newName = $updates['newName'];
            
            // Validate new name
            $nameValidation = $this->validateFunctionName($newName);
            if (!$nameValidation['valid']) {
                return ['success' => false, 'error' => $nameValidation['error']];
            }
            
            // Check if new name already exists
            if ($this->functionExists($newName)) {
                return ['success' => false, 'error' => "Function '{$newName}' already exists"];
            }
            
            $functions[$foundIndex]['name'] = $newName;
        }
        
        // Update args if provided
        if (isset($updates['args'])) {
            $functions[$foundIndex]['args'] = $updates['args'];
        }
        
        // Update body if provided
        if (isset($updates['body'])) {
            $syntaxValidation = $this->validateJsSyntax($updates['body']);
            if (!$syntaxValidation['valid']) {
                return ['success' => false, 'error' => 'Invalid JavaScript: ' . $syntaxValidation['error']];
            }
            $functions[$foundIndex]['body'] = $updates['body'];
        }
        
        // Update description if provided
        if (isset($updates['description'])) {
            $functions[$foundIndex]['description'] = $updates['description'];
        }
        
        $functions[$foundIndex]['modified'] = date('Y-m-d H:i:s');
        
        // Save
        $result = $this->saveFunctions($functions);
        if (!$result['success']) {
            return $result;
        }
        
        // Regenerate qs-custom.js
        return $this->regenerateJsFile();
    }
    
    /**
     * Delete a custom function
     * @param string $name Function name
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function deleteFunction(string $name): array {
        // Cannot delete core functions
        if ($this->isCoreFunction($name)) {
            return ['success' => false, 'error' => "Cannot delete core function '{$name}'"];
        }
        
        $functions = $this->getCustomFunctions();
        $foundIndex = null;
        
        foreach ($functions as $index => $func) {
            if ($func['name'] === $name) {
                $foundIndex = $index;
                break;
            }
        }
        
        if ($foundIndex === null) {
            return ['success' => false, 'error' => "Function '{$name}' not found"];
        }
        
        // Remove function
        array_splice($functions, $foundIndex, 1);
        
        // Save
        $result = $this->saveFunctions($functions);
        if (!$result['success']) {
            return $result;
        }
        
        // Regenerate qs-custom.js
        return $this->regenerateJsFile();
    }
    
    /**
     * Get all allowed function names (core + custom)
     * @return array
     */
    public function getAllowedFunctions(): array {
        $customNames = array_map(fn($f) => $f['name'], $this->getCustomFunctions());
        return array_merge($this->coreFunctions, $customNames);
    }
    
    /**
     * Save functions to config file
     * @param array $functions
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function saveFunctions(array $functions): array {
        // Ensure config directory exists
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create config directory'];
            }
        }
        
        $data = [
            'version' => '1.0',
            'generated' => date('Y-m-d H:i:s'),
            'functions' => $functions
        ];
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($this->configPath, $json) === false) {
            return ['success' => false, 'error' => 'Failed to save functions config'];
        }
        
        return ['success' => true, 'error' => null];
    }
    
    /**
     * Regenerate qs-custom.js from function definitions
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function regenerateJsFile(): array {
        $functions = $this->getCustomFunctions();
        
        $header = <<<JS
/**
 * QuickSite Custom Functions (qs-custom.js)
 * 
 * User-defined functions extending QS namespace.
 * Managed via addJsFunction, editJsFunction, deleteJsFunction commands.
 * 
 * DO NOT EDIT MANUALLY - This file is regenerated when custom functions are modified.
 * 
 * @version 1.0.0
 * @generated %s
 */

(function(window) {
    'use strict';

    // Ensure QS namespace exists
    if (!window.QS) {
        console.warn('[QS-Custom] QS namespace not found. Load qs.js first.');
        window.QS = {};
    }

    // ============================================
    // CUSTOM FUNCTIONS START
    // ============================================

JS;
        
        $footer = <<<JS

    // ============================================
    // CUSTOM FUNCTIONS END
    // ============================================

})(window);

JS;
        
        $js = sprintf($header, date('Y-m-d H:i:s'));
        
        foreach ($functions as $func) {
            $name = $func['name'];
            $args = implode(', ', $func['args'] ?? []);
            $body = $func['body'];
            $desc = $func['description'] ?? '';
            
            // Build JSDoc comment
            $jsDoc = "    /**\n";
            if (!empty($desc)) {
                $jsDoc .= "     * {$desc}\n";
            }
            foreach (($func['args'] ?? []) as $arg) {
                $jsDoc .= "     * @param {*} {$arg}\n";
            }
            $jsDoc .= "     */\n";
            
            $js .= $jsDoc;
            $js .= "    QS.{$name} = function({$args}) {\n";
            
            // Indent body lines
            $bodyLines = explode("\n", $body);
            foreach ($bodyLines as $line) {
                $js .= "        {$line}\n";
            }
            
            $js .= "    };\n\n";
        }
        
        $js .= $footer;
        
        // Ensure scripts directory exists
        $scriptsDir = dirname($this->outputPath);
        if (!is_dir($scriptsDir)) {
            if (!mkdir($scriptsDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create scripts directory'];
            }
        }
        
        if (file_put_contents($this->outputPath, $js) === false) {
            return ['success' => false, 'error' => 'Failed to write qs-custom.js'];
        }
        
        return ['success' => true, 'error' => null];
    }
}
