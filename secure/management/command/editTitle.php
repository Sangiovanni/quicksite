<?php
/**
 * Edit Title Command (formerly modifyTitle)
 * 
 * Updates the title for a specific page in a specific language.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';

/**
 * Modify Page Title Command
 * 
 * Updates the title for a specific page in a specific language
 * Requires: route (page route name), lang (language code), title (new title text)
 */

$params = $trimParametersManagement->params();

// Get parameters
$route = $params['route'] ?? null;
$lang = $params['lang'] ?? null;
$title = $params['title'] ?? null;

// Validate route parameter is present
if (empty($route)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('route parameter is required')
        ->withData([
            'required_fields' => ['route', 'lang', 'title']
        ])
        ->send();
}

// Validate route is string (allow numeric for routes like "404")
if (is_int($route) || is_float($route)) {
    $route = (string) $route;
}

if (!is_string($route)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('route must be a string')
        ->withData([
            'field' => 'route',
            'expected_type' => 'string',
            'received_type' => gettype($route)
        ])
        ->send();
}

// Check for path traversal in route
if (strpos($route, '..') !== false || strpos($route, '/') !== false || strpos($route, '\\') !== false || strpos($route, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('route contains invalid characters')
        ->withData([
            'field' => 'route',
            'reason' => 'Path traversal characters not allowed'
        ])
        ->send();
}

// Validate route length
if (strlen($route) > 100) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('route is too long')
        ->withData([
            'field' => 'route',
            'max_length' => 100,
            'received_length' => strlen($route)
        ])
        ->send();
}

// Validate route format (alphanumeric, hyphens, underscores)
if (!RegexPatterns::match('identifier_alphanum', $route)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('route contains invalid characters')
        ->withErrors([
            'field' => 'route',
            'allowed' => RegexPatterns::getDescription('identifier_alphanum'),
            'examples' => RegexPatterns::getExamples('identifier_alphanum')
        ])
        ->send();
}

// Special pages that exist but are not in ROUTES (error pages, etc.)
$specialPages = ['404', '500', '403', '401'];

// Validate route exists in ROUTES or is a special page
if (!in_array($route, ROUTES) && !in_array($route, $specialPages, true)) {
    ApiResponse::create(404, 'validation.invalid_route')
        ->withMessage('Route does not exist')
        ->withData([
            'provided_route' => $route,
            'available_routes' => ROUTES,
            'special_pages' => $specialPages
        ])
        ->send();
}

// Validate lang parameter is present
if (empty($lang)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('lang parameter is required')
        ->withData([
            'required_fields' => ['route', 'lang', 'title']
        ])
        ->send();
}

// Validate language code is string
if (!is_string($lang)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('lang must be a string')
        ->withData([
            'field' => 'lang',
            'expected_type' => 'string',
            'received_type' => gettype($lang)
        ])
        ->send();
}

// Check for path traversal in language code
if (strpos($lang, '..') !== false || strpos($lang, '/') !== false || strpos($lang, '\\') !== false || strpos($lang, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('lang contains invalid characters')
        ->withData([
            'field' => 'lang',
            'reason' => 'Path traversal characters not allowed'
        ])
        ->send();
}

// Validate language code length
if (strlen($lang) > 10) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('lang is too long')
        ->withData([
            'field' => 'lang',
            'max_length' => 10,
            'received_length' => strlen($lang)
        ])
        ->send();
}

// Validate language code format (2-3 lowercase letters, optional locale)
if (!RegexPatterns::match('language_code_extended', $lang)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('lang has invalid format')
        ->withErrors([RegexPatterns::validationError('language_code_extended', 'lang', $lang)])
        ->send();
}

// Validate language is supported
if (!in_array($lang, CONFIG['LANGUAGES_SUPPORTED'])) {
    ApiResponse::create(400, 'validation.unsupported_language')
        ->withMessage('Language is not supported')
        ->withData([
            'provided_language' => $lang,
            'supported_languages' => CONFIG['LANGUAGES_SUPPORTED']
        ])
        ->send();
}

// Validate title parameter is present
if ($title === null || $title === '') {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('title parameter is required')
        ->withData([
            'required_fields' => ['route', 'lang', 'title']
        ])
        ->send();
}

// Validate title is string
if (!is_string($title)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('title must be a string')
        ->withData([
            'field' => 'title',
            'expected_type' => 'string',
            'received_type' => gettype($title)
        ])
        ->send();
}

// Validate title length (reasonable page title limit)
if (strlen($title) > 200) {
    ApiResponse::create(400, 'validation.invalid_length')
        ->withMessage('title is too long')
        ->withData([
            'field' => 'title',
            'max_length' => 200,
            'received_length' => strlen($title)
        ])
        ->send();
}

// Load translation file
$translationFile = SECURE_FOLDER_PATH . '/translate/' . $lang . '.json';

if (!file_exists($translationFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Translation file not found')
        ->withData([
            'language' => $lang,
            'expected_file' => 'translate/' . $lang . '.json'
        ])
        ->send();
}

$translationJson = @file_get_contents($translationFile);
if ($translationJson === false) {
    ApiResponse::create(500, 'server.file_read_failed')
        ->withMessage('Failed to read translation file')
        ->withData([
            'language' => $lang
        ])
        ->send();
}

$translations = json_decode($translationJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ApiResponse::create(500, 'server.invalid_json')
        ->withMessage('Translation file contains invalid JSON')
        ->withData([
            'language' => $lang,
            'json_error' => json_last_error_msg()
        ])
        ->send();
}

// Ensure page.titles structure exists
if (!isset($translations['page'])) {
    $translations['page'] = [];
}
if (!isset($translations['page']['titles'])) {
    $translations['page']['titles'] = [];
}

// Update the title
$translations['page']['titles'][$route] = $title;

// Write back to file with pretty formatting
$updatedJson = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($updatedJson === false) {
    ApiResponse::create(500, 'server.json_encode_failed')
        ->withMessage('Failed to encode translation data')
        ->withData([
            'language' => $lang
        ])
        ->send();
}

if (file_put_contents($translationFile, $updatedJson) === false) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to write translation file')
        ->withData([
            'language' => $lang
        ])
        ->send();
}

// Success response
ApiResponse::create(200, 'success.title_updated')
    ->withMessage('Page title updated successfully')
    ->withData([
        'route' => $route,
        'language' => $lang,
        'title' => $title,
        'translation_key' => 'page.titles.' . $route
    ])
    ->send();