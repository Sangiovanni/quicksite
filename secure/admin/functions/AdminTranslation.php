<?php
/**
 * Admin Panel Translation Helper
 * 
 * Provides multilingual support for the admin panel.
 * Translations are stored separately from main site translations.
 * 
 * @version 1.6.0
 */

class AdminTranslation {
    private static ?AdminTranslation $instance = null;
    private array $translations = [];
    private string $currentLang = 'en';
    private string $fallbackLang = 'en';
    private array $availableLanguages = [];

    private function __construct() {
        $this->detectLanguage();
        $this->loadTranslations();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): AdminTranslation {
        if (self::$instance === null) {
            self::$instance = new AdminTranslation();
        }
        return self::$instance;
    }

    /**
     * Detect user's preferred language
     */
    private function detectLanguage(): void {
        // Check session first
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // URL parameter has HIGHEST priority (user clicking language switcher)
        if (!empty($_GET['lang'])) {
            $requestedLang = $_GET['lang'];
            if ($this->isValidLanguage($requestedLang)) {
                $this->currentLang = $requestedLang;
                $_SESSION['admin_lang'] = $requestedLang;
                return;
            }
        }
        
        // Then check session
        if (!empty($_SESSION['admin_lang'])) {
            $this->currentLang = $_SESSION['admin_lang'];
            return;
        }
        
        // Finally check browser preference
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
            if ($this->isValidLanguage($browserLang)) {
                $this->currentLang = $browserLang;
            }
        }
    }

    /**
     * Load translation files
     */
    private function loadTranslations(): void {
        $translationDir = SECURE_FOLDER_PATH . '/admin/translations';
        
        // Scan available languages
        if (is_dir($translationDir)) {
            foreach (glob($translationDir . '/*.json') as $file) {
                $lang = basename($file, '.json');
                $this->availableLanguages[] = $lang;
            }
        }
        
        // Load current language
        $currentFile = $translationDir . '/' . $this->currentLang . '.json';
        if (file_exists($currentFile)) {
            $this->translations = json_decode(file_get_contents($currentFile), true) ?? [];
        }
        
        // Load fallback if different
        if ($this->currentLang !== $this->fallbackLang) {
            $fallbackFile = $translationDir . '/' . $this->fallbackLang . '.json';
            if (file_exists($fallbackFile)) {
                $fallback = json_decode(file_get_contents($fallbackFile), true) ?? [];
                $this->translations = array_merge($fallback, $this->translations);
            }
        }
    }

    /**
     * Check if language is available
     */
    private function isValidLanguage(string $lang): bool {
        $file = SECURE_FOLDER_PATH . '/admin/translations/' . $lang . '.json';
        return file_exists($file);
    }

    /**
     * Set current language
     */
    public function setLanguage(string $lang): void {
        if ($this->isValidLanguage($lang)) {
            $this->currentLang = $lang;
            $_SESSION['admin_lang'] = $lang;
            $this->loadTranslations();
        }
    }

    /**
     * Get current language
     */
    public function getCurrentLanguage(): string {
        return $this->currentLang;
    }

    /**
     * Get available languages
     */
    public function getAvailableLanguages(): array {
        return $this->availableLanguages;
    }

    /**
     * Translate a key
     * 
     * @param string $key Dot-notation key (e.g., 'login.title')
     * @param array $params Replacement parameters (e.g., ['name' => 'John'])
     * @return string Translated string or key if not found
     */
    public function t(string $key, array $params = []): string {
        $value = $this->getNestedValue($this->translations, $key);
        
        if ($value === null) {
            return $key; // Return key if translation not found
        }
        
        // Replace parameters
        foreach ($params as $param => $replacement) {
            $value = str_replace(':' . $param, $replacement, $value);
        }
        
        return $value;
    }

    /**
     * Key aliases for backward compatibility
     * Maps old keys to new keys during transition
     */
    private static array $keyAliases = [
        'ai' => 'workflows',
        'ai.spec' => 'workflows.spec',
        'ai.specs' => 'workflows.specs',
    ];

    /**
     * Apply key aliases for backward compatibility
     */
    private function applyKeyAlias(string $key): string {
        // Sort aliases by length descending to match longest first
        $sortedAliases = self::$keyAliases;
        uksort($sortedAliases, fn($a, $b) => strlen($b) - strlen($a));
        
        foreach ($sortedAliases as $oldPrefix => $newPrefix) {
            if (str_starts_with($key, $oldPrefix . '.') || $key === $oldPrefix) {
                return $newPrefix . substr($key, strlen($oldPrefix));
            }
        }
        return $key;
    }

    /**
     * Get nested value from array using dot notation
     */
    private function getNestedValue(array $array, string $key): ?string {
        // Apply key alias if exists
        $key = $this->applyKeyAlias($key);
        
        $keys = explode('.', $key);
        $value = $array;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }
        
        return is_string($value) ? $value : null;
    }

    /**
     * Check if translation key exists
     */
    public function has(string $key): bool {
        return $this->getNestedValue($this->translations, $key) !== null;
    }
}

/**
 * Helper function for quick translation
 * 
 * @param string $key Translation key in dot notation
 * @param array|string $paramsOrFallback Either params array or fallback string
 * @param array $params Parameters when fallback is provided
 * @return string Translated string, fallback, or key if not found
 */
function __admin(string $key, array|string $paramsOrFallback = [], array $params = []): string {
    $instance = AdminTranslation::getInstance();
    
    // Handle second parameter being either params array or fallback string
    if (is_string($paramsOrFallback)) {
        $fallback = $paramsOrFallback;
        $actualParams = $params;
    } else {
        $fallback = null;
        $actualParams = $paramsOrFallback;
    }
    
    // Check if key exists
    if (!$instance->has($key)) {
        return $fallback ?? $key;
    }
    
    return $instance->t($key, $actualParams);
}

/**
 * Helper function for translation escaped for JavaScript single-quoted strings
 * Escapes apostrophes, backslashes, and newlines
 */
function __adminJs(string $key, array $params = []): string {
    $value = AdminTranslation::getInstance()->t($key, $params);
    // Escape backslashes first, then single quotes, then newlines
    return str_replace(
        ['\\', "'", "\r\n", "\n", "\r"],
        ['\\\\', "\\'", '\\n', '\\n', '\\n'],
        $value
    );
}
