<?php
/**
 * RouteLayoutManager
 * 
 * Manages per-route layout settings (menu/footer visibility).
 * Routes without explicit entries inherit from nearest ancestor or default (both true).
 * 
 * Storage: secure/projects/{project}/config/route-layout.json (per-project)
 * 
 * Structure:
 * {
 *   "version": "1.0",
 *   "routes": {
 *     "guides": { "menu": false, "footer": true },
 *     "guides/install": { "menu": true }
 *   }
 * }
 * 
 * Inheritance: /guides/install/step1 with no entry inherits from /guides/install,
 * then /guides, then root default (menu: true, footer: true).
 */

class RouteLayoutManager {
    
    /** @var string Path to route-layout.json config */
    private string $configPath;
    
    /** @var array Default layout when no entry exists */
    private array $defaultLayout = ['menu' => true, 'footer' => true];
    
    /**
     * Constructor
     * @param string|null $projectPath Optional project path override
     */
    public function __construct(?string $projectPath = null) {
        if ($projectPath !== null) {
            $basePath = $projectPath;
        } elseif (defined('PROJECT_PATH')) {
            $basePath = PROJECT_PATH;
        } else {
            $basePath = SECURE_FOLDER_PATH;
        }
        
        $this->configPath = $basePath . '/config/route-layout.json';
        
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
            'description' => 'Per-route layout settings (menu/footer visibility). Routes without entries inherit from nearest ancestor or default (both true).',
            'routes' => new \stdClass()
        ];
        
        return $this->save($data);
    }
    
    /**
     * Load the full config
     * @return array
     */
    public function load(): array {
        if (!file_exists($this->configPath)) {
            return [
                'version' => '1.0',
                'routes' => []
            ];
        }
        
        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);
        
        if ($data === null) {
            return [
                'version' => '1.0',
                'routes' => []
            ];
        }
        
        // Ensure routes is an array
        if (!isset($data['routes']) || !is_array($data['routes'])) {
            $data['routes'] = [];
        }
        
        return $data;
    }
    
    /**
     * Save the full config
     * @param array $data
     * @return bool
     */
    public function save(array $data): bool {
        // Ensure routes is object for JSON (empty {} not [])
        if (empty($data['routes'])) {
            $data['routes'] = new \stdClass();
        }
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($this->configPath, $json) !== false;
    }
    
    /**
     * Get the effective layout for a route, resolving inheritance
     * 
     * @param string $route Route path (e.g., "guides/install/step1")
     * @return array ['menu' => bool, 'footer' => bool]
     */
    public function getEffectiveLayout(string $route): array {
        $config = $this->load();
        $routes = $config['routes'] ?? [];
        
        // Normalize route (remove leading/trailing slashes)
        $route = trim($route, '/');
        
        // Build ancestor chain: guides/install/step1 → [guides/install/step1, guides/install, guides]
        $ancestors = $this->getAncestorChain($route);
        
        // Start with defaults
        $effectiveMenu = $this->defaultLayout['menu'];
        $effectiveFooter = $this->defaultLayout['footer'];
        
        // Walk from root to leaf, applying any explicit settings
        // (reverse order so more specific overrides less specific)
        foreach (array_reverse($ancestors) as $ancestor) {
            if (isset($routes[$ancestor])) {
                $entry = $routes[$ancestor];
                if (isset($entry['menu'])) {
                    $effectiveMenu = (bool) $entry['menu'];
                }
                if (isset($entry['footer'])) {
                    $effectiveFooter = (bool) $entry['footer'];
                }
            }
        }
        
        return [
            'menu' => $effectiveMenu,
            'footer' => $effectiveFooter
        ];
    }
    
    /**
     * Get ancestor chain for a route
     * 
     * @param string $route Route path
     * @return array List from most specific to least specific
     */
    private function getAncestorChain(string $route): array {
        $route = trim($route, '/');
        if ($route === '') {
            return [''];
        }
        
        $parts = explode('/', $route);
        $ancestors = [];
        
        // Build list: [guides/install/step1, guides/install, guides]
        while (!empty($parts)) {
            $ancestors[] = implode('/', $parts);
            array_pop($parts);
        }
        
        // Add root
        $ancestors[] = '';
        
        return $ancestors;
    }
    
    /**
     * Set layout for a specific route
     * 
     * @param string $route Route path
     * @param bool|null $menu Menu visibility (null = don't change/remove)
     * @param bool|null $footer Footer visibility (null = don't change/remove)
     * @return bool Success
     */
    public function setLayout(string $route, ?bool $menu = null, ?bool $footer = null): bool {
        $config = $this->load();
        $route = trim($route, '/');
        
        // If both null, remove the entry
        if ($menu === null && $footer === null) {
            return $this->removeLayout($route);
        }
        
        // Get current entry or create new
        $entry = $config['routes'][$route] ?? [];
        
        // Update values
        if ($menu !== null) {
            $entry['menu'] = $menu;
        }
        if ($footer !== null) {
            $entry['footer'] = $footer;
        }
        
        // If entry matches inherited values, we could remove it (optimization)
        // But for clarity, we keep explicit entries
        
        $config['routes'][$route] = $entry;
        
        return $this->save($config);
    }
    
    /**
     * Set layout for a route and all its descendants
     * 
     * @param string $route Route path
     * @param bool|null $menu Menu visibility
     * @param bool|null $footer Footer visibility
     * @param array $allRoutes List of all routes in the project (to find descendants)
     * @return array ['success' => bool, 'affected' => array of route names]
     */
    public function propagateLayout(string $route, ?bool $menu, ?bool $footer, array $allRoutes): array {
        $config = $this->load();
        $route = trim($route, '/');
        $affected = [];
        
        // Find all routes that are descendants of this route
        $prefix = $route === '' ? '' : $route . '/';
        
        foreach ($allRoutes as $r) {
            $r = trim($r, '/');
            
            // Include the route itself
            if ($r === $route) {
                $this->applyLayoutToEntry($config, $r, $menu, $footer);
                $affected[] = $r === '' ? '(root)' : $r;
                continue;
            }
            
            // Include descendants
            if ($route === '' || strpos($r, $prefix) === 0) {
                $this->applyLayoutToEntry($config, $r, $menu, $footer);
                $affected[] = $r;
            }
        }
        
        $success = $this->save($config);
        
        return [
            'success' => $success,
            'affected' => $affected
        ];
    }
    
    /**
     * Apply layout to a config entry (helper for propagate)
     */
    private function applyLayoutToEntry(array &$config, string $route, ?bool $menu, ?bool $footer): void {
        if (!isset($config['routes'][$route])) {
            $config['routes'][$route] = [];
        }
        
        if ($menu !== null) {
            $config['routes'][$route]['menu'] = $menu;
        }
        if ($footer !== null) {
            $config['routes'][$route]['footer'] = $footer;
        }
        
        // Clean up entry if it's now empty
        if (empty($config['routes'][$route])) {
            unset($config['routes'][$route]);
        }
    }
    
    /**
     * Remove explicit layout entry for a route (revert to inheritance)
     * 
     * @param string $route Route path
     * @return bool Success
     */
    public function removeLayout(string $route): bool {
        $config = $this->load();
        $route = trim($route, '/');
        
        if (isset($config['routes'][$route])) {
            unset($config['routes'][$route]);
            return $this->save($config);
        }
        
        return true; // Nothing to remove
    }
    
    /**
     * Get raw layout entry for a route (without inheritance resolution)
     * 
     * @param string $route Route path
     * @return array|null The entry or null if no explicit entry
     */
    public function getExplicitLayout(string $route): ?array {
        $config = $this->load();
        $route = trim($route, '/');
        
        return $config['routes'][$route] ?? null;
    }
    
    /**
     * Get all explicit layout entries
     * 
     * @return array All routes with explicit layout settings
     */
    public function getAllLayouts(): array {
        $config = $this->load();
        return $config['routes'] ?? [];
    }
    
    /**
     * Check if a route has an explicit layout entry
     * 
     * @param string $route Route path
     * @return bool
     */
    public function hasExplicitLayout(string $route): bool {
        $config = $this->load();
        $route = trim($route, '/');
        
        return isset($config['routes'][$route]);
    }
    
    /**
     * Rename a route's layout entry (for moveRoute)
     * 
     * @param string $oldRoute Old route path
     * @param string $newRoute New route path
     * @return bool Success
     */
    public function renameRoute(string $oldRoute, string $newRoute): bool {
        $config = $this->load();
        $oldRoute = trim($oldRoute, '/');
        $newRoute = trim($newRoute, '/');
        
        if (!isset($config['routes'][$oldRoute])) {
            return true; // Nothing to rename
        }
        
        // Move the entry
        $config['routes'][$newRoute] = $config['routes'][$oldRoute];
        unset($config['routes'][$oldRoute]);
        
        return $this->save($config);
    }
    
    /**
     * Copy a route's layout entry (for copyRoute)
     * 
     * @param string $sourceRoute Source route path
     * @param string $targetRoute Target route path
     * @return bool Success
     */
    public function copyRoute(string $sourceRoute, string $targetRoute): bool {
        $config = $this->load();
        $sourceRoute = trim($sourceRoute, '/');
        $targetRoute = trim($targetRoute, '/');
        
        if (!isset($config['routes'][$sourceRoute])) {
            return true; // Nothing to copy
        }
        
        // Copy the entry
        $config['routes'][$targetRoute] = $config['routes'][$sourceRoute];
        
        return $this->save($config);
    }
}
