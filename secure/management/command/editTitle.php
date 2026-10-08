<?php
/**
 * Edit Title Command (formerly modifyTitle)
 * 
 * Updates the title for a specific page in a specific language.
 */

require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/languageRegistry.php';

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

// The one page-name rule every command shares (qs_page_name_refusal()): a nested page
// ('guides/installation') and a parameter route's page ('products/:slug') have a title too,
// read by the page as page.titles.<route path>.
$refusal = qs_page_name_refusal($route, 'route');
if ($refusal !== null) {
    $refusal->send();
}
// The key the page reads has no leading or trailing slash.
$route = trim($route, '/');

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

// An EXISTING language: it must be one of the project's.
if (!qs_project_has_language($lang)) {
    qs_language_not_in_project_response($lang, 'lang')->send();
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
$translationFile = PROJECT_PATH . '/translate/' . $lang . '.json';

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

// The shared translation writer (translationHelpers.php), as setTranslationKeys uses: it
// writes the language's file and keeps default.json in step. A single-language site reads
// default.json, so a title written to <lang>.json alone never reached its pages.
require_once SECURE_FOLDER_PATH . '/src/functions/translationHelpers.php';
$writeResult = writeTranslationsToFile($lang, ['page' => ['titles' => [$route => $title]]]);
if (!$writeResult['ok']) {
    if ($writeResult['reason'] === 'collisions') {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage($writeResult['collisions'][0]['suggestion'])
            ->withErrors($writeResult['collisions'])
            ->send();
    }
    if ($writeResult['reason'] === 'json_encode_failed') {
        ApiResponse::create(500, 'server.json_encode_failed')
            ->withMessage('Failed to encode translation data')
            ->withData(['language' => $lang])
            ->send();
    }
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage('Failed to write translation file')
        ->withData(['language' => $lang])
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