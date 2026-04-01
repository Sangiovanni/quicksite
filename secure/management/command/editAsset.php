<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AssetMetadataManager.php';

/**
 * Edit Asset Command
 * 
 * Edits an existing asset's filename and/or metadata (alt, description).
 * Category is auto-detected from file extension.
 * 
 * Parameters:
 * - filename (required): Current filename of the asset
 * - newFilename (optional): Desired new name (extension auto-appended if omitted, must match original if provided)
 * - alt (optional): Alt text for images (max 250 chars, empty string removes)
 * - description (optional): Description text (max 500 chars, empty string removes)
 * 
 * At least one optional parameter must be provided.
 */

$params = $trimParametersManagement->params();

$filename = $params['filename'] ?? null;
$newFilename = $params['newFilename'] ?? null;
$alt = $params['alt'] ?? null;
$description = $params['description'] ?? null;
$starred = $params['starred'] ?? null;

// --- Validate filename (required) ---
if (empty($filename)) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('filename parameter is required')
        ->withData(['required_fields' => ['filename']])
        ->send();
}

if (!is_string($filename)) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage('The filename parameter must be a string.')
        ->withErrors([['field' => 'filename', 'reason' => 'invalid_type', 'expected' => 'string']])
        ->send();
}

// Path traversal check
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false ||
    strpos($filename, '\\') !== false || strpos($filename, "\0") !== false) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename contains invalid path characters')
        ->withErrors([['field' => 'filename', 'reason' => 'path_traversal_attempt']])
        ->send();
}

$filename = basename($filename);

if (empty($filename) || strlen($filename) > 100) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Invalid filename (empty or exceeds 100 characters)')
        ->withErrors([['field' => 'filename']])
        ->send();
}

if (!RegexPatterns::match('file_name_with_ext', $filename)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Filename must contain only letters, numbers, hyphens, and underscores, with a valid extension')
        ->withErrors([RegexPatterns::validationError('file_name_with_ext', 'filename', $filename)])
        ->send();
}

// --- Resolve category from extension ---
$metaManager = new AssetMetadataManager(
    PROJECT_PATH . '/data',
    SECURE_FOLDER_PATH . '/management/config/assetExtensions.php'
);

$category = $metaManager->resolveCategory($filename);
if ($category === null) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    ApiResponse::create(400, 'validation.invalid_extension')
        ->withMessage("Unrecognized file extension '.{$ext}' — cannot determine asset category")
        ->withErrors([['field' => 'filename', 'reason' => 'unknown_extension', 'extension' => $ext]])
        ->send();
}

// --- Check source file exists ---
$categoryDir = PUBLIC_CONTENT_PATH . '/assets/' . $category;
$sourcePath = $categoryDir . '/' . $filename;

if (!file_exists($sourcePath) || !is_file($sourcePath)) {
    ApiResponse::create(404, 'asset.not_found')
        ->withMessage("File '{$filename}' not found in category '{$category}'")
        ->withData(['filename' => $filename, 'category' => $category])
        ->send();
}

// --- Validate at least one edit parameter ---
if ($newFilename === null && $alt === null && $description === null && $starred === null) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('At least one of newFilename, alt, description, or starred must be provided')
        ->withData(['optional_fields' => ['newFilename', 'alt', 'description', 'starred']])
        ->send();
}

// --- Validate newFilename if provided ---
if ($newFilename !== null) {
    if (!is_string($newFilename)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The newFilename parameter must be a string.')
            ->withErrors([['field' => 'newFilename', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }

    // Path traversal check
    if (strpos($newFilename, '..') !== false || strpos($newFilename, '/') !== false ||
        strpos($newFilename, '\\') !== false || strpos($newFilename, "\0") !== false) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('New filename contains invalid path characters')
            ->withErrors([['field' => 'newFilename', 'reason' => 'path_traversal_attempt']])
            ->send();
    }

    $newFilename = basename($newFilename);

    // Auto-append original extension if none provided
    $oldExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $newExt = strtolower(pathinfo($newFilename, PATHINFO_EXTENSION));

    if (empty($newExt)) {
        $newFilename .= '.' . $oldExt;
        $newExt = $oldExt;
    }

    if (empty($newFilename) || strlen($newFilename) > 100) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid new filename (empty or exceeds 100 characters)')
            ->withErrors([['field' => 'newFilename']])
            ->send();
    }

    if (!RegexPatterns::match('file_name_with_ext', $newFilename)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('New filename must contain only letters, numbers, hyphens, and underscores, with a valid extension')
            ->withErrors([RegexPatterns::validationError('file_name_with_ext', 'newFilename', $newFilename)])
            ->send();
    }

    // Extension must match original
    if ($oldExt !== $newExt) {
        ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("File extension cannot change: .{$oldExt} → .{$newExt}")
            ->withErrors([['field' => 'newFilename', 'reason' => 'extension_mismatch', 'expected' => $oldExt, 'actual' => $newExt]])
            ->send();
    }

    // Same name = no-op
    if ($filename === $newFilename) {
        ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('New filename is the same as the current filename')
            ->withErrors([['field' => 'newFilename', 'reason' => 'same_name']])
            ->send();
    }

    // Target must not exist
    $targetPath = $categoryDir . '/' . $newFilename;
    if (file_exists($targetPath)) {
        ApiResponse::create(409, 'asset.already_exists')
            ->withMessage("A file named '{$newFilename}' already exists in category '{$category}'")
            ->withData(['newFilename' => $newFilename, 'category' => $category])
            ->send();
    }
}

// --- Validate description if provided ---
if ($description !== null) {
    if (!is_string($description)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The description parameter must be a string.')
            ->withErrors([['field' => 'description', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }

    if (strlen($description) > 500) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The description parameter must not exceed 500 characters.')
            ->withErrors([['field' => 'description', 'max_length' => 500, 'actual_length' => strlen($description)]])
            ->send();
    }

    $description = trim($description);
}

// --- Validate alt if provided ---
if ($alt !== null) {
    if (!is_string($alt)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The alt parameter must be a string.')
            ->withErrors([['field' => 'alt', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }

    if (strlen($alt) > 250) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The alt parameter must not exceed 250 characters.')
            ->withErrors([['field' => 'alt', 'max_length' => 250, 'actual_length' => strlen($alt)]])
            ->send();
    }

    $alt = trim($alt);
}

// --- Validate starred if provided ---
if ($starred !== null) {
    if (is_string($starred)) {
        $starred = in_array(strtolower($starred), ['true', '1'], true);
    } else {
        $starred = (bool) $starred;
    }
}

// ============================================================
// Apply changes
// ============================================================
$updated = [];
$currentFilename = $filename;

// 1. Rename file if requested
if ($newFilename !== null) {
    if (!rename($sourcePath, $categoryDir . '/' . $newFilename)) {
        ApiResponse::create(500, 'asset.rename_failed')
            ->withMessage('Failed to rename file')
            ->send();
    }

    $metaManager->rename($category, $filename, $newFilename);
    $currentFilename = $newFilename;
    $updated[] = 'filename';
}

// 2. Update metadata fields
$metaUpdates = [];

if ($description !== null) {
    if (empty($description)) {
        // Empty string removes the field — need direct manipulation
        $existing = $metaManager->get($category, $currentFilename);
        if ($existing !== null) {
            unset($existing['description']);
            // Re-set the entire entry without description
            $metaUpdates = $existing;
            $metaUpdates['meta_updated'] = date('c');
        }
        $updated[] = 'description removed';
    } else {
        $metaUpdates['description'] = $description;
        $updated[] = 'description';
    }
}

if ($alt !== null) {
    if (empty($alt)) {
        $existing = $metaManager->get($category, $currentFilename) ?? [];
        unset($existing['alt']);
        $metaUpdates = array_merge($existing, $metaUpdates);
        $metaUpdates['meta_updated'] = date('c');
        $updated[] = 'alt removed';
    } else {
        $metaUpdates['alt'] = $alt;
        $updated[] = 'alt';
    }
}

if ($starred !== null) {
    $metaUpdates['starred'] = $starred;
    if (!$starred) {
        // Remove the key entirely when unstarring
        $existing = $metaManager->get($category, $currentFilename) ?? [];
        unset($existing['starred']);
        $metaUpdates = array_merge($existing, $metaUpdates);
    }
    $updated[] = $starred ? 'starred' : 'unstarred';
}

if (!empty($metaUpdates)) {
    if (!isset($metaUpdates['meta_updated'])) {
        $metaUpdates['meta_updated'] = date('c');
    }

    // Ensure metadata entry exists (auto-detect file info if new)
    $existing = $metaManager->get($category, $currentFilename);
    if ($existing === null) {
        $filePath = $categoryDir . '/' . $currentFilename;
        $fileInfo = $metaManager->detectFileInfo($filePath);
        $fileInfo['uploaded'] = date('c', filemtime($filePath));
        $metaManager->set($category, $currentFilename, $fileInfo);
    }

    $metaManager->set($category, $currentFilename, $metaUpdates);
}

// Save metadata
$metaManager->save();

// Build response
$finalPath = '/assets/' . $category . '/' . $currentFilename;
$responseData = [
    'category' => $category,
    'filename' => $currentFilename,
    'path' => $finalPath,
    'updated_fields' => $updated
];

if ($newFilename !== null) {
    $responseData['oldFilename'] = $filename;
}

$meta = $metaManager->get($category, $currentFilename);
if ($meta !== null) {
    $responseData['metadata'] = $meta;
}

ApiResponse::create(200, 'operation.success')
    ->withMessage("Asset updated: " . implode(', ', $updated))
    ->withData($responseData)
    ->send();
