<?php

class Translator {
    private static $translations = null;
    private static $lang = null;
    private static $supportedLangs = CONFIG['LANGUAGES_SUPPORTED'];
    private static $defaultLang = CONFIG['LANGUAGE_DEFAULT'];

    public function __construct($langFromRouter) {
        if(!MULTILINGUAL_SUPPORT){
            self::$lang = CONFIG['LANGUAGE_DEFAULT'];
            return;
        }
        $determinedLang = self::$defaultLang;
        if (in_array($langFromRouter, self::$supportedLangs)) {            
            $determinedLang =  $langFromRouter;
        }
        self::$lang = $determinedLang;
    }

    public static function loadTranslations() {
        $lang = self::$lang ?: self::determineLang();
        
        $fileTranslate = SECURE_FOLDER_PATH . "/translate/{$lang}.json";        
        if(!MULTILINGUAL_SUPPORT){
            $fileTranslate = SECURE_FOLDER_PATH . "/translate/default.json";
        }
        if (!file_exists($fileTranslate)) {
             $fileTranslate = SECURE_FOLDER_PATH . "/translate/default.json";
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
     * @param string $key The key to lookup.
     * @return string The translated string or the key/default if not found.
     */
    public static function translate(string $key): string {
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

        // Ensure the final value is a string before returning
        return (string) $current;
    }

    // A simple method to get the current language without needing to decode
    public static function getLang() {
        return self::$currentLang ?? self::determineLang();
    }
}