<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AssetMetadataManager.php';

/**
 * Delete Asset Command
 * 
 * Deletes one or more files from assets folders.
 * Category is auto-detected from the file extension.
 * 
 * Parameters:
 * - filename (string): Single filename to delete
 * - filenames (array of strings): Multiple filenames to delete (batch mode)
 * 
 * Provide either filename or filenames, not both.
 * Batch mode (filenames) max 50 files per request.
 */

// Get parameters from POST/JSON
$params = $trimParametersManagement->params();
$singleFilename = $params['filename'] ?? null;
$batchFilenames = $params['filenames'] ?? null;

// Initialize AssetMetadataManager for category resolution and metadata cleanup
$metaManager = new AssetMetadataManager(
    PROJECT_PATH . '/data',
    SECURE_FOLDER_PATH . '/management/config/assetExtensions.php'
);

// Determine mode: single or batch
if ($singleFilename !== null && $batchFilenames !== null) {
    ApiResponse::create(400, 'validation.invalid_params')
        ->withMessage('Provide either filename or filenames, not both')
        ->send();
}

if ($singleFilename === null && $batchFilenames === null) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('filename or filenames parameter is required')
        ->withData(['required_fields' => ['filename or filenames']])
        ->send();
}

// Build the list of filenames to process
$filenamesToDelete = [];

if ($batchFilenames !== null) {
    if (!is_array($batchFilenames)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The filenames parameter must be an array of strings.')
            ->withErrors([['field' => 'filenames', 'reason' => 'invalid_type', 'expected' => 'array']])
            ->send();
    }

    if (empty($batchFilenames)) {
        ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('The filenames array must not be empty.')
            ->withErrors([['field' => 'filenames', 'reason' => 'empty_array']])
            ->send();
    }

    if (count($batchFilenames) > 50) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('Maximum 50 files per batch delete request.')
            ->withErrors([['field' => 'filenames', 'reason' => 'too_many', 'max' => 50, 'actual' => count($batchFilenames)]])
            ->send();
    }

    $filenamesToDelete = $batchFilenames;
} else {
    $filenamesToDelete = [$singleFilename];
}

/**
 * Validate and delete a single asset file.
 * Returns ['success' => true/false, ...] result array.
 */
function validateAndDeleteAsset(string $filename, AssetMetadataManager $metaManager): array {
    // Type check
    if (!is_string($filename)) {
        return ['success' => false, 'filename' => (string)$filename, 'error' => 'Filename must be a string'];
    }

    // Path traversal check
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false ||
        strpos($filename, '\\') !== false || strpos($filename, "\0") !== false) {
        return ['success' => false, 'filename' => $filename, 'error' => 'Invalid path characters'];
    }

    $filename = basename($filename);

    if (empty($filename)) {
        return ['success' => false, 'filename' => $filename, 'error' => 'Empty filename'];
    }

    if (strlen($filename) > 100) {
        return ['success' => false, 'filename' => $filename, 'error' => 'Filename exceeds 100 characters'];
    }

    if (!RegexPatterns::match('file_name_with_ext', $filename)) {
        return ['success' => false, 'filename' => $filename, 'error' => 'Invalid filename format'];
    }

    // Auto-detect category
    $category = $metaManager->resolveCategory($filename);
    if ($category === null) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return ['success' => false, 'filename' => $filename, 'error' => "Unrecognized extension '.{$ext}'"];
    }

    // Build path and check existence
    $filePath = PUBLIC_CONTENT_PATH . '/assets/' . $category . '/' . $filename;

    if (!file_exists($filePath) || !is_file($filePath)) {
        return ['success' => false, 'filename' => $filename, 'category' => $category, 'error' => 'File not found'];
    }

    // Delete the file
    if (!unlink($filePath)) {
        return ['success' => false, 'filename' => $filename, 'category' => $category, 'error' => 'Failed to delete file'];
    }

    // Remove metadata
    $metaManager->remove($category, $filename);

    return ['success' => true, 'filename' => $filename, 'category' => $category];
}

// === Single file mode ===
if ($singleFilename !== null) {
    // Validate type upfront for single mode (better error messages)
    if (!is_string($singleFilename)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The filename parameter must be a string.')
            ->withErrors([['field' => 'filename', 'reason' => 'invalid_type', 'expected' => 'string']])
            ->send();
    }

    if (empty($singleFilename)) {
        ApiResponse::create(400, 'validation.missing_field')
            ->withMessage('filename parameter is required')
            ->withData(['required_fields' => ['filename']])
            ->send();
    }

    $result = validateAndDeleteAsset($singleFilename, $metaManager);

    if (!$result['success']) {
        $status = str_contains($result['error'], 'not found') ? 404 : 400;
        $code = str_contains($result['error'], 'not found') ? 'asset.not_found' : 'validation.invalid_format';
        ApiResponse::create($status, $code)
            ->withMessage($result['error'])
            ->withData(['filename' => $result['filename'], 'category' => $result['category'] ?? null])
            ->send();
    }

    $metaManager->save();

    ApiResponse::create(204, 'operation.success')
        ->withMessage("File deleted successfully")
        ->withData([
            'filename' => $result['filename'],
            'category' => $result['category']
        ])
        ->send();
}

// === Batch mode ===
$deleted = [];
$failed = [];

foreach ($filenamesToDelete as $fname) {
    $result = validateAndDeleteAsset($fname, $metaManager);
    if ($result['success']) {
        $deleted[] = ['filename' => $result['filename'], 'category' => $result['category']];
    } else {
        $failed[] = ['filename' => $result['filename'], 'error' => $result['error']];
    }
}

// Save metadata once for all deletions
if (!empty($deleted)) {
    $metaManager->save();
}

$totalDeleted = count($deleted);
$totalFailed = count($failed);

if ($totalDeleted === 0) {
    ApiResponse::create(400, 'operation.failed')
        ->withMessage("No files were deleted ({$totalFailed} failed)")
        ->withData(['deleted' => [], 'failed' => $failed])
        ->send();
}

$message = "{$totalDeleted} file" . ($totalDeleted > 1 ? 's' : '') . " deleted successfully";
if ($totalFailed > 0) {
    $message .= " ({$totalFailed} failed)";
}

ApiResponse::create(204, 'operation.success')
    ->withMessage($message)
    ->withData([
        'deleted' => $deleted,
        'failed' => $failed,
        'total_deleted' => $totalDeleted,
        'total_failed' => $totalFailed
    ])
    ->send();