<?php
/**
 * TrimParameters - URL Parsing and Route Resolution
 * 
 * Parses the current URL and resolves it against the nested routes structure.
 * Supports hierarchical routes up to 5 levels deep.
 * 
 * @example URL: /en/guides/installation/step-1
 *   - lang: 'en'
 *   - route: ['guides', 'installation']
 *   - params: ['step-1']
 */

require_once __DIR__ . '/../functions/String.php';

class TrimParameters {
    /** @var string|null Current language code */
    private ?string $lang = null;
    
    /** @var array Route segments as array, e.g., ['guides', 'installation'] */
    private array $route = [];
    
    /** @var string Route as path string, e.g., 'guides/installation' */
    private string $routePath = '';
    
    /** @var array Remaining URL segments after route resolution */
    private array $params = [];
    
    /** @var bool Whether the route was found in routes.php */
    private bool $routeFound = false;
    
    /** @var array Supported languages from config */
    private static array $supportedLangs = [];
    
    /** @var array Routes structure from routes.php */
    private static array $routes = [];
    
    /** @var int Maximum route depth allowed */
    private const MAX_DEPTH = 5;

    public function __construct() {
        // Initialize static config
        self::$supportedLangs = (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) 
            ? (CONFIG['LANGUAGES_SUPPORTED'] ?? []) 
            : [];
        self::$routes = defined('ROUTES') ? ROUTES : [];
        
        // Set default language
        if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) {
            $this->lang = CONFIG['LANGUAGE_DEFAULT'] ?? 'en';
        }
        
        // Parse URL
        $this->parseUrl();
    }
    
    /**
     * Parse the current URL and resolve route
     */
    private function parseUrl(): void {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        
        // Handle malformed URLs
        if ($parsedPath === null || $parsedPath === false) {
            $this->route = ['home'];
            $this->routePath = 'home';
            $this->routeFound = true;
            return;
        }
        
        $path = trim($parsedPath, '/');
        
        // Remove PUBLIC_FOLDER_SPACE prefix if present
        $folder = defined('PUBLIC_FOLDER_SPACE') ? PUBLIC_FOLDER_SPACE : '';
        if ($folder) {
            $path = removePrefix($path, trim($folder, '/') . '/');
        }
        
        // Split into segments, filter empty
        $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));
        
        // Extract language if multilingual
        if (!empty($parts) && in_array($parts[0], self::$supportedLangs)) {
            if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) {
                $this->lang = array_shift($parts);
            }
        }
        
        // Resolve route against routes structure
        if (empty($parts)) {
            // Root URL → home
            $this->route = ['home'];
            $this->routePath = 'home';
            $this->routeFound = isset(self::$routes['home']);
        } else {
            $resolved = $this->resolveRoute($parts, self::$routes);
            $this->route = $resolved['route'];
            $this->routePath = implode('/', $resolved['route']);
            $this->params = $resolved['params'];
            $this->routeFound = $resolved['found'];
        }
    }
    
    /**
     * Resolve URL segments against nested routes structure
     * 
     * @param array $urlParts URL segments to resolve
     * @param array $routes Routes structure to match against
     * @return array ['route' => [...], 'params' => [...], 'found' => bool]
     */
    private function resolveRoute(array $urlParts, array $routes): array {
        $matched = [];
        $remaining = $urlParts;
        $current = $routes;
        $depth = 0;
        
        while (!empty($remaining) && $depth < self::MAX_DEPTH) {
            $segment = $remaining[0];
            
            if (isset($current[$segment])) {
                array_shift($remaining);
                $matched[] = $segment;
                $current = $current[$segment];
                $depth++;
            } else {
                break;
            }
        }
        
        // If nothing matched, it's a 404
        if (empty($matched)) {
            return [
                'route' => ['404'],
                'params' => $urlParts,
                'found' => false
            ];
        }
        
        return [
            'route' => $matched,
            'params' => $remaining,
            'found' => true
        ];
    }
    
    /**
     * Get route as array
     * @return array e.g., ['guides', 'installation']
     */
    public function route(): array {
        return $this->route;
    }
    
    /**
     * Get route as path string
     * @return string e.g., 'guides/installation'
     */
    public function routePath(): string {
        return $this->routePath;
    }
    
    /**
     * Get the top-level route (first segment)
     * @return string e.g., 'guides'
     */
    public function rootRoute(): string {
        return $this->route[0] ?? 'home';
    }
    
    /**
     * Get remaining URL parameters after route
     * @return array
     */
    public function params(): array {
        return $this->params;
    }
    
    /**
     * Get current language
     * @return string|null
     */
    public function lang(): ?string {
        return $this->lang;
    }
    
    /**
     * Check if route was found in routes.php
     * @return bool
     */
    public function routeFound(): bool {
        return $this->routeFound;
    }
    
    /**
     * Check if current route is home
     * @return bool
     */
    public function isHome(): bool {
        return $this->routePath === 'home';
    }
    
    // =========================================================================
    // LEGACY COMPATIBILITY METHODS
    // These maintain backward compatibility during migration
    // =========================================================================
    
    /**
     * @deprecated Use routePath() or route()[0] instead
     * Returns the last segment of the route for legacy template compatibility
     */
    public function page(): string {
        return end($this->route) ?: 'home';
    }
    
    /**
     * @deprecated No longer used - params now contains all remaining segments
     */
    public function id(): ?string {
        return $this->params[0] ?? null;
    }
    
    /**
     * Build URL for the same page in a different language
     * Used by language switcher
     * 
     * @param string|null $lang Target language code
     * @return string Full URL
     */
    public function samePageUrl(?string $lang = null): string {
        $targetLang = $lang ?? $this->lang;
        $url = defined('BASE_URL') ? BASE_URL : '/';
        
        // Add language prefix if multilingual
        if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) {
            $url .= $targetLang;
        }
        
        // Add route path (skip 'home' for cleaner URLs)
        if (!$this->isHome()) {
            $separator = (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) ? '/' : '';
            $url .= $separator . $this->routePath;
        } else {
            // For home, just ensure trailing slash if multilingual
            if (defined('MULTILINGUAL_SUPPORT') && MULTILINGUAL_SUPPORT) {
                $url .= '/';
            }
        }
        
        // Add remaining params if any
        if (!empty($this->params)) {
            $url .= '/' . implode('/', $this->params);
        }
        
        return $url;
    }
    
    // =========================================================================
    // STATIC UTILITY METHODS
    // =========================================================================
    
    /**
     * Check if a route path exists in routes structure
     * 
     * @param string $routePath Path like 'guides/installation'
     * @param array|null $routes Routes structure (uses ROUTES if null)
     * @return bool
     */
    public static function routeExists(string $routePath, ?array $routes = null): bool {
        $routes = $routes ?? (defined('ROUTES') ? ROUTES : []);
        $segments = array_filter(explode('/', $routePath), fn($p) => $p !== '');
        
        $current = $routes;
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return false;
            }
            $current = $current[$segment];
        }
        
        return true;
    }
    
    /**
     * Get children of a route
     * 
     * @param string $routePath Path like 'guides'
     * @param array|null $routes Routes structure
     * @return array Child route names
     */
    public static function getRouteChildren(string $routePath, ?array $routes = null): array {
        $routes = $routes ?? (defined('ROUTES') ? ROUTES : []);
        
        if (empty($routePath)) {
            return array_keys($routes);
        }
        
        $segments = array_filter(explode('/', $routePath), fn($p) => $p !== '');
        
        $current = $routes;
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return [];
            }
            $current = $current[$segment];
        }
        
        return array_keys($current);
    }
}
