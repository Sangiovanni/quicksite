<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/AssetMetadataManager.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_favicon_repoint, qs_asset_write_failed
require_once SECURE_FOLDER_PATH . '/src/functions/projectPublicArtifacts.php';
require_once SECURE_FOLDER_PATH . '/src/functions/quota.php'; // qs_small_write_removal_only

// This command only removes content: neither the size cap nor the over-quota rule refuses
// its writes, even when a re-save makes a file larger (qs_small_write_removal_only()).
qs_small_write_removal_only();

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
$metaManager = new AssetMetadataManager(PROJECT_PATH . '/data');

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
 * Returns ['success' => true/false, ...] result array; on success 'listChanged' says whether
 * the asset list had an entry for the file (removed from it, to be saved), and 'faviconFailed'
 * whether the favicon setting named the file and could not be cleared.
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

    // The mirror — remove the project-folder copy too. Deleting while editing the
    // SERVED project unlinks from the BASE live public/ only; the copy in the
    // project's own folder (the authoritative one) would survive and resurrect the
    // asset on the next serve/switch. Tolerant: a missing mirror is not an error
    // (an older asset may never have had one).
    qs_delete_public_artifact('assets/' . $category . '/' . $filename);

    // Remove metadata
    $listChanged = $metaManager->remove($category, $filename);

    // editFavicon stores a POINTER, not a copy, so deleting the chosen image
    // would leave the site emitting an icon link to a file that is gone. Only
    // images can be the favicon, so nothing else needs asking.
    $faviconFailed = $category === 'images' && qs_favicon_repoint(PROJECT_PATH, $filename, null) === 'failed';

    return ['success' => true, 'filename' => $filename, 'category' => $category, 'listChanged' => $listChanged,
        'faviconFailed' => $faviconFailed];
}

/**
 * The answer when the files are deleted but what names them could not be saved: the asset list
 * (it still names them, so a file uploaded later under the same name would inherit their entries)
 * and the favicon setting (it still names a deleted image). $notSaved says which, 'asset_list'
 * before 'favicon', and the answer carries it as not_saved.
 *
 * @param string $deleted what was deleted ("The file was deleted")
 * @param string $one     the deleted file the favicon names ("it", "one of them")
 * @param string $tail    what follows the sentence (the batch's failures), or ''
 */
function deletedButNotSaved(string $deleted, string $one, array $notSaved, string $tail, array $data): ApiResponse {
    if ($notSaved === ['asset_list']) {
        $what = 'the asset list could not be updated';
    } elseif ($notSaved === ['favicon']) {
        $what = "the project's favicon setting still names $one and could not be updated";
    } else {
        $what = "neither the asset list nor the project's favicon setting could be updated";
    }
    return qs_asset_write_failed("$deleted, but $what$tail.", $data + ['not_saved' => $notSaved]);
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

    // The list is saved only when it named the file: a save that changes nothing cannot leave
    // it stale, and is not attempted.
    $notSaved = [];
    if ($result['listChanged'] && !$metaManager->save()) {
        $notSaved[] = 'asset_list';
    }
    if ($result['faviconFailed']) {
        $notSaved[] = 'favicon';
    }
    if ($notSaved !== []) {
        deletedButNotSaved('The file was deleted', 'it', $notSaved, '', [
            'filename' => $result['filename'],
            'category' => $result['category']
        ])->send();
    }

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
$listChanged = false;
$faviconFailed = false;

foreach ($filenamesToDelete as $fname) {
    $result = validateAndDeleteAsset($fname, $metaManager);
    if ($result['success']) {
        $deleted[] = ['filename' => $result['filename'], 'category' => $result['category']];
        $listChanged = $listChanged || $result['listChanged'];
        $faviconFailed = $faviconFailed || $result['faviconFailed'];
    } else {
        $failed[] = ['filename' => $result['filename'], 'error' => $result['error']];
    }
}

$totalDeleted = count($deleted);
$totalFailed = count($failed);

// Save metadata once for all deletions, when the list named one of the files
$notSaved = [];
if ($listChanged && !$metaManager->save()) {
    $notSaved[] = 'asset_list';
}
if ($faviconFailed) {
    $notSaved[] = 'favicon';
}
if ($notSaved !== []) {
    deletedButNotSaved("{$totalDeleted} file" . ($totalDeleted > 1 ? 's were' : ' was') . ' deleted',
        $totalDeleted > 1 ? 'one of them' : 'it', $notSaved,
        $totalFailed > 0 ? " ({$totalFailed} failed)" : '', [
        'deleted' => $deleted,
        'failed' => $failed,
        'total_deleted' => $totalDeleted,
        'total_failed' => $totalFailed
    ])->send();
}

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