<?php
/**
 * CommandRunner - Execute management commands internally without HTTP overhead
 * 
 * This class allows calling command functions directly from PHP code,
 * useful for AI specs, batch operations, and internal tooling.
 * 
 * @version 1.0.0
 * @since December 2025
 */

require_once __DIR__ . '/ApiResponse.php';

class CommandRunner {
    
    /**
     * Whitelist of commands allowed for internal execution
     * This prevents arbitrary code execution from untrusted sources
     */
    private static array $allowedCommands = [
        // Read-only commands (safe for AI specs)
        'getTranslations',
        'getTranslation',
        'getTranslationKeys',
        'getUnusedTranslationKeys',
        'getLangList',
        'getStructure',
        'getRoutes',
        'getSiteMap',
        'getStyles',
        'getRootVariables',
        'getStyleRule',
        'getKeyframes',
        'getBuild',
        'getCommandHistory',
        'help',
        'listComponents',
        'listPages',
        'listAssets',
        'listBuilds',
        'listStyleRules',
        'listTokens',
        'listAliases',
        'validateTranslations',
        'analyzeTranslations',
        'checkStructureMulti',
    ];
    
    /**
     * Cache of loaded command functions
     */
    private static array $loadedCommands = [];
    
    /**
     * Execute a command internally and return the ApiResponse
     * 
     * @param string $command Command name (e.g., 'getStructure')
     * @param array $params Parameters to pass (body params)
     * @param array $urlParams URL segments (e.g., ['page', 'home', 'showIds'])
     * @return ApiResponse
     */
    public static function execute(string $command, array $params = [], array $urlParams = []): ApiResponse {
        // Security: only allow whitelisted commands
        if (!in_array($command, self::$allowedCommands, true)) {
            return ApiResponse::create(403, 'command.not_allowed')
                ->withMessage("Command '{$command}' is not allowed for internal execution")
                ->withErrors([['field' => 'command', 'value' => $command, 'allowed' => self::$allowedCommands]]);
        }
        
        $functionName = '__command_' . $command;
        
        // Load command file if function not yet available
        if (!function_exists($functionName)) {
            $file = SECURE_FOLDER_PATH . '/management/command/' . $command . '.php';
            
            if (!file_exists($file)) {
                return ApiResponse::create(404, 'command.not_found')
                    ->withMessage("Command file not found: {$command}")
                    ->withErrors([['field' => 'command', 'value' => $command]]);
            }
            
            // Mark that we're doing an internal call (command should NOT send response)
            if (!defined('COMMAND_INTERNAL_CALL')) {
                define('COMMAND_INTERNAL_CALL', true);
            }
            
            require_once $file;
            
            // Check if function was defined
            if (!function_exists($functionName)) {
                return ApiResponse::create(500, 'command.function_missing')
                    ->withMessage("Command '{$command}' does not define function {$functionName}()")
                    ->withErrors([['field' => 'function', 'expected' => $functionName]]);
            }
            
            self::$loadedCommands[$command] = true;
        }
        
        // Execute the command function
        try {
            return $functionName($params, $urlParams);
        } catch (\Throwable $e) {
            return ApiResponse::create(500, 'command.execution_error')
                ->withMessage("Error executing command '{$command}': " . $e->getMessage())
                ->withErrors([
                    ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                ]);
        }
    }
    
    /**
     * Execute a command and extract specific data field
     * 
     * @param string $command Command name
     * @param array $params Parameters
     * @param array $urlParams URL segments
     * @param string $dataPath Dot-notation path to extract (e.g., 'data.languages')
     * @return mixed The extracted value, or null if not found
     */
    public static function extractData(string $command, array $params, array $urlParams, string $dataPath) {
        $response = self::execute($command, $params, $urlParams);
        
        // If command failed, return null
        if ($response->getStatus() >= 400) {
            return null;
        }
        
        // Get the response data
        $responseArray = $response->toArray();
        
        // Navigate the path
        $parts = explode('.', $dataPath);
        $current = $responseArray;
        
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }
        
        return $current;
    }
    
    /**
     * Check if a command is allowed for internal execution
     * 
     * @param string $command Command name
     * @return bool
     */
    public static function isAllowed(string $command): bool {
        return in_array($command, self::$allowedCommands, true);
    }
    
    /**
     * Get list of allowed commands
     * 
     * @return array
     */
    public static function getAllowedCommands(): array {
        return self::$allowedCommands;
    }
    
    /**
     * Add a command to the whitelist (for extensions)
     * Use with caution - only add trusted commands
     * 
     * @param string $command Command name
     */
    public static function addAllowedCommand(string $command): void {
        if (!in_array($command, self::$allowedCommands, true)) {
            self::$allowedCommands[] = $command;
        }
    }
}
