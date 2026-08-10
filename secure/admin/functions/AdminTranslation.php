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
        // The admin language rides the SAME session as the login — a bare
        // session_start() here would open PHP's DEFAULT session instead, giving
        // the panel a second cookie and, worse, leaving $_SESSION pointing at
        // the wrong session for anything that reads the login afterwards.
        require_once SECURE_FOLDER_PATH . '/src/functions/AuthManagement.php';
        qs_session_boot(true);

        // URL parameter has HIGHEST priority (user clicking language switcher).
        // is_string: a query parameter arrives as whatever the caller sent, and
        // `?lang[]=x` is an ARRAY. Passing it to isValidLanguage(string) raised an
        // uncaught TypeError — a fatal on every admin page, this one included,
        // i.e. reachable with no credentials at all (beta.10 C13 F-C13-22).
        if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
            $requestedLang = $_GET['lang'];
            if ($this->isValidLanguage($requestedLang)) {
                $this->currentLang = $requestedLang;
                $_SESSION['admin_lang'] = $requestedLang;
                return;
            }
        }

        // Then check session. Re-validated rather than trusted: it is only ever
        // written from a checked value above, but a session written BEFORE that
        // check existed would otherwise keep its bad value alive for the life of
        // the session (F-C13-23).
        if (!empty($_SESSION['admin_lang']) && is_string($_SESSION['admin_lang'])
            && $this->isValidLanguage($_SESSION['admin_lang'])) {
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
     * Check if language is available.
     *
     * SHAPE FIRST, EXISTENCE SECOND (beta.10 C13 F-C13-23). This value is
     * concatenated into a filesystem path, and it used to go in unexamined — so
     * `?lang=../../projects/<id>/config/members` resolved out of the translations
     * directory, `file_exists` said yes, and loadTranslations() read that file
     * into the translation array. Two consequences, both reachable on the LOGIN
     * page with no credentials: any readable .json on the box could be loaded,
     * and the true/false answer was a project-EXISTENCE ORACLE — which is exactly
     * what surface B's uniform 404 and the management API's uniform 403 exist to
     * deny.
     *
     * The gate is a shape, not a registry of known codes: no dots (so `..` cannot
     * be spelled), no slashes or backslashes (so no sub-path), letters, digits
     * and hyphens only, bounded — which leaves `en`, `fr`, `pt-BR` and anything
     * else a translator drops into the directory working without a code change.
     * The file_exists check still decides whether the language is really there;
     * this only decides whether the name is allowed to become a path at all.
     */
    private function isValidLanguage(string $lang): bool {
        if (preg_match('/^[A-Za-z0-9-]{1,32}$/', $lang) !== 1) {
            return false;
        }
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

    /**
     * Get a nested translation value as-is (string, array, or null).
     * Unlike t(), this preserves sub-trees so callers can inject whole
     * objects (e.g. eventTooltips map) into the JS layer in one go.
     */
    public function getRaw(string $key): mixed {
        $key = $this->applyKeyAlias($key);
        $keys = explode('.', $key);
        $value = $this->translations;
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }
        return $value;
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

/**
 * Helper to resolve workflow translation keys.
 *
 * Workflows are SHIPPED (core only) since the custom workflow feature was
 * removed in beta.10 C8, so every key resolves directly through __admin().
 *
 * @param array $spec  The workflow spec (unused; kept for call-site stability)
 * @param string $key  Translation key
 * @param string $fallback  Fallback if nothing resolves
 * @return string
 */
function __workflow(array $spec, string $key, string $fallback = ''): string {
    if ($key === '') {
        return $fallback;
    }
    return __admin($key, $fallback);
}
