<?php

// The single point that answers "what language is this request, for this
// project?" — see the file's header for why it is not a method here.
require_once __DIR__ . '/../functions/projectLanguage.php';

class Translator {
    private static $translations = null;
    private static $lang = null;

    /**
     * @param string|null $langFromRouter The language the router already
     *                                    resolved, if any. Null (or a code the
     *                                    project does not declare) falls
     *                                    through to the shared detector rather
     *                                    than being trusted.
     */
    public function __construct($langFromRouter = null) {
        $resolved = qs_resolve_project_language(is_string($langFromRouter) ? $langFromRouter : null);

        // The loaded table belongs to the language it was loaded for. Drop it
        // when the language changes, or the first language served in a request
        // wins every lookup after it. That ordering is real: on the per-project
        // view the artifact regeneration calls translate() statically before
        // the page template constructs its own Translator.
        if ($resolved !== self::$lang) {
            self::$translations = null;
        }
        self::$lang = $resolved;
    }

    public static function loadTranslations() {
        // Reached with self::$lang unset whenever translate() is called
        // statically and no Translator was constructed in this request —
        // ApiEndpointManager and CallTransformer both do that. Resolving from
        // the shared detector is what makes those paths work; they used to
        // call a method that did not exist and took the whole request down.
        if (self::$lang === null || self::$lang === '') {
            self::$lang = qs_resolve_project_language();
        }
        $lang = self::$lang;

        $fileTranslate = PROJECT_PATH . "/translate/{$lang}.json";
        if(!MULTILINGUAL_SUPPORT){
            $fileTranslate = PROJECT_PATH . "/translate/default.json";
        }
        if (!file_exists($fileTranslate)) {
             $fileTranslate = PROJECT_PATH . "/translate/default.json";
        }

        $json = @file_get_contents($fileTranslate);
        if ($json === false) {
             error_log('Failed to read translation file: ' . $fileTranslate);
             self::$translations = [];
             return;
        }

        $translations = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Failed to decode translation file: ' . $fileTranslate . ' Error: ' . json_last_error_msg());
            $translations = [];
        }

        self::$translations = $translations;
    }

    /**
     * Retrieves a translation string using dot notation (e.g., 'footer.language').
     * Supports i18next-style interpolation with {{variable}} syntax.
     * 
     * @param string $key The key to lookup.
     * @param array $params Optional parameters for interpolation (e.g., ['name' => 'John'])
     * @return string The translated string or the key/default if not found.
     */
    public static function translate(string $key, array $params = []): string {
        if (self::$translations === null) {
            self::loadTranslations();
        }

        // Split the key by dot to navigate the nested array
        $keys = explode('.', $key);
        $current = self::$translations;

        foreach ($keys as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                // Log missing translation for debugging
                error_log("Missing translation key: {$key} for language: " . self::$lang);
                
                // Return key itself wrapped in marker if no default provided
                return "{translation missing: {$key}}";
            }
            $current = $current[$segment];
        }

        // Defensive: path resolved to a NON-scalar (array of nested keys,
        // or some other shape). Most i18n systems disallow a key being
        // both a leaf and a branch; setTranslationKeys rejects writes
        // that would create the collision, but we can't assume the file
        // is well-formed — older data + manual edits + foreign imports
        // can all leave the collision in place. Return missing-marker
        // so the renderer stays safe (no `(string) $array` → "Array"
        // crashes, no leaked subtree of keys in the page output).
        if (!is_scalar($current)) {
            error_log(
                "Translator: key '{$key}' resolves to a non-scalar value "
                . "(likely a string-vs-nested collision in the translation file). "
                . "Lang: " . self::$lang
            );
            return "{translation missing: {$key}}";
        }

        $result = (string) $current;

        // Empty string is considered "untranslated" - treat like missing
        if ($result === '') {
            error_log("Empty translation for key: {$key} for language: " . self::$lang);
            return "{translation missing: {$key}}";
        }
        
        // Handle i18next-style interpolation: {{variable}}
        if (!empty($params)) {
            $result = preg_replace_callback('/\{\{(\w+)\}\}/', function($matches) use ($params) {
                $paramKey = $matches[1];
                return $params[$paramKey] ?? $matches[0]; // Keep original if param not found
            }, $result);
        }

        return $result;
    }
}