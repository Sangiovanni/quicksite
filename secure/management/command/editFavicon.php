<?php
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/filePolicy.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';

/**
 * editFavicon — point the site's favicon at an existing image asset.
 *
 * A POINTER, NOT A COPY. The command writes `CONFIG['FAVICON_PATH']` and
 * touches no file in `assets/images/`. It used to `copy()` the chosen image
 * over `assets/images/favicon.png` and `rename()` the previous favicon to
 * `favicon_backup_<timestamp>.png` — backups that accumulated in the asset
 * folder forever, showed up in the media browser, counted against the project's
 * storage quota, and were never read by anything. It also never wrote
 * FAVICON_PATH at all, so the config key the renderers look for stayed unset
 * and both fell back to a hardcoded path.
 *
 * The READ side already existed: `Page.php` and `PageManagement.php` both read
 * `CONFIG['FAVICON_PATH']`, accept an absolute URL or a root-relative path,
 * honour `PUBLIC_FOLDER_SPACE`, and fall back to `/assets/images/favicon.png`
 * when the key is absent. This command is the writer they were waiting for.
 * Because a build copies `config.php` verbatim, the pointer travels into a
 * built site with no extra step.
 *
 * VALIDATION IS AT WRITE TIME, not only at render. The value lands in a PHP
 * array literal in `config.php` and is echoed through `htmlspecialchars` in the
 * page head. `var_export` (via `qs_config_mutate`) guarantees the file stays
 * parsable; the checks below guarantee the value names a real image asset in
 * this project, so a stored pointer cannot be a path, a URL, or a traversal.
 *
 * FILE VALIDATION IS NOT REPEATED HERE. `uploadAsset` already proves an asset's
 * bytes match its name (extension gate, server-side MIME detection, SVG
 * sanitising). Re-deriving that from `getimagesize()` — which is what this
 * command did, and which is why it was PNG-only — would be a second, weaker
 * opinion about a file the engine has already accepted.
 *
 * @method POST
 * @url    /management/p/<projectId>/editFavicon
 *
 * Body:
 *   { "imageName": "logo.svg" }   an existing file in assets/images/
 *   { "imageName": null }         clears the pointer, restoring the default
 */

$params = $trimParametersManagement->params();

$hasKey    = array_key_exists('imageName', $params);
$imageName = $params['imageName'] ?? null;

if (!$hasKey) {
    ApiResponse::create(400, 'validation.missing_field')
        ->withMessage('imageName parameter is required')
        ->withData(['required_fields' => ['imageName']])
        ->send();
}

// ── Clearing the pointer ──────────────────────────────────────────────────
// An explicit null (or empty string) removes FAVICON_PATH, which puts the
// renderers back on their built-in default. This is the only way to undo a
// selection, and the media page's radio needs it to mean "none selected".
$clearing = ($imageName === null || $imageName === '');

if (!$clearing) {
    if (!is_string($imageName)) {
        ApiResponse::create(400, 'validation.invalid_type')
            ->withMessage('The imageName parameter must be a string.')
            ->withErrors([
                ['field' => 'imageName', 'reason' => 'invalid_type', 'expected' => 'string']
            ])
            ->send();
    }

    // A stored pointer must name a FILE, never a path. basename() first so a
    // traversal cannot survive as far as the format check, and the format check
    // second so what is stored is a plain asset filename either way.
    $imageName = basename($imageName);

    if ($imageName === '') {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Invalid filename provided')
            ->send();
    }

    if (strlen($imageName) > 100) {
        ApiResponse::create(400, 'validation.invalid_length')
            ->withMessage('The image filename must not exceed 100 characters.')
            ->withErrors([
                ['field' => 'imageName', 'value' => $imageName, 'max_length' => 100]
            ])
            ->send();
    }

    if (!RegexPatterns::match('file_name_with_ext', $imageName)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage('Image filename must contain only letters, numbers, hyphens and underscores, plus a favicon-capable image extension')
            ->withErrors([RegexPatterns::validationError('file_name_with_ext', 'imageName', $imageName)])
            ->send();
    }

    // Favicon-capable formats only — narrower than the `images` category,
    // because <link rel="icon"> is not a general image slot. The list lives in
    // filePolicy.php with the rest of the asset taxonomy.
    $extension = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
    $allowed   = qs_favicon_extensions();
    if (!in_array($extension, $allowed, true)) {
        ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("'.{$extension}' cannot be used as a favicon")
            ->withData([
                'provided_extension' => $extension,
                'allowed_extensions' => $allowed,
            ])
            ->send();
    }

    $imagePath = PUBLIC_CONTENT_PATH . '/assets/images/' . $imageName;
    if (!is_file($imagePath)) {
        ApiResponse::create(404, 'file.not_found')
            ->withMessage('Image file not found in assets/images')
            ->withData([
                'requested_file' => $imageName,
                'expected_path'  => '/assets/images/' . $imageName,
            ])
            ->send();
    }
}

// ── Write the pointer ─────────────────────────────────────────────────────
$faviconPath = $clearing ? null : '/assets/images/' . $imageName;
$previous    = null;

$result = qs_config_mutate(
    PROJECT_PATH . '/config.php',
    function (array &$config) use ($faviconPath, &$previous): bool {
        $previous = $config['FAVICON_PATH'] ?? null;
        if ($faviconPath === null) {
            unset($config['FAVICON_PATH']);
        } else {
            $config['FAVICON_PATH'] = $faviconPath;
        }
        return true;
    }
);

if (!$result['ok']) {
    ApiResponse::create(500, 'server.file_write_failed')
        ->withMessage($result['reason'] === 'read_failed'
            ? 'Failed to read configuration file'
            : 'Failed to update configuration file')
        ->send();
}

if ($previous === $faviconPath) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage($clearing
            ? 'No favicon was set'
            : 'This image is already set as the favicon')
        ->withData([
            'favicon_path' => $faviconPath,
            'changed'      => false,
        ])
        ->send();
}

ApiResponse::create(200, 'operation.success')
    ->withMessage($clearing ? 'Favicon cleared' : 'Favicon updated successfully')
    ->withData([
        'favicon_path'  => $faviconPath,
        'previous_path' => $previous,
        'source_image'  => $clearing ? null : $imageName,
        'changed'       => true,
    ])
    ->send();
