<?php
// filepath: c:\wamp64\www\template_vitrinne\secure_template\management\command\modifyTitle.php

/**
 * Modify Page Title Command
 * 
 * Updates the title for a specific page in all translation files
 * Requires: route (page route name), titles (object with lang => title)
 */

$params = $trimParametersManagement->params();

// Get parameters
$route = $params['route'] ?? null;
$titles = $params['titles'] ?? null;

// Validate route
if (empty($route)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('route parameter is required')
        ->withData([
            'required_fields' => ['route', 'titles']
        ])
        ->send();
}

// Validate titles format
if (empty($titles) || !is_array($titles)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('titles must be an object with language codes as keys')
        ->withData([
            'example' => [
                'titles' => [
                    'en' => 'New Title - Site Name',
                    'fr' => 'Nouveau Titre - Nom du Site'
                ]
            ]
        ])
        ->send();
}

// Validate route exists in ROUTES
if (!in_array($route, ROUTES)) {
    ApiResponse::create(404, 'validation.invalid_route')
        ->withMessage('Route does not exist')
        ->withData([
            'provided_route' => $route,
            'available_routes' => ROUTES
        ])
        ->send();
}

$updatedLanguages = [];
$errors = [];

// Update each language translation file
foreach ($titles as $lang => $title) {
    // Validate language is supported
    if (!in_array($lang, CONFIG['LANGUAGES_SUPPORTED'])) {
        $errors[] = "Language '{$lang}' is not supported";
        continue;
    }
    
    // Load translation file
    $translationFile = SECURE_FOLDER_PATH . '/translations/' . $lang . '.json';
    
    if (!file_exists($translationFile)) {
        $errors[] = "Translation file for '{$lang}' not found";
        continue;
    }
    
    $translationJson = @file_get_contents($translationFile);
    if ($translationJson === false) {
        $errors[] = "Failed to read translation file for '{$lang}'";
        continue;
    }
    
    $translations = json_decode($translationJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = "Invalid JSON in '{$lang}' translation file";
        continue;
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
        $errors[] = "Failed to encode JSON for '{$lang}'";
        continue;
    }
    
    if (file_put_contents($translationFile, $updatedJson) === false) {
        $errors[] = "Failed to write translation file for '{$lang}'";
        continue;
    }
    
    $updatedLanguages[] = $lang;
}

// Check if any updates succeeded
if (empty($updatedLanguages)) {
    ApiResponse::create(500, 'server.file_operation_failed')
        ->withMessage('Failed to update any translation files')
        ->withData([
            'errors' => $errors
        ])
        ->send();
}

// Success response (even if some languages failed)
$response = ApiResponse::create(200, 'success.title_updated')
    ->withMessage('Page title updated successfully')
    ->withData([
        'route' => $route,
        'updated_languages' => $updatedLanguages,
        'translation_key' => 'page.titles.' . $route
    ]);

if (!empty($errors)) {
    $response->withData([
        'route' => $route,
        'updated_languages' => $updatedLanguages,
        'translation_key' => 'page.titles.' . $route,
        'partial_errors' => $errors
    ]);
}

$response->send();