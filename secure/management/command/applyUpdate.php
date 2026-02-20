<?php
/**
 * Apply Update Command
 * 
 * Updates QuickSite to the latest version using git pull (for git installs)
 * or ZIP download + extraction (for manual installs).
 * Creates a backup before updating and validates the result.
 * 
 * @method POST
 * @route /management/applyUpdate
 * @auth required (superadmin only)
 * @return ApiResponse Update result
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Re-use the helpers from checkForUpdates if not already loaded
if (!function_exists('updates_normalizeVersion')) {
    require_once __DIR__ . '/checkForUpdates.php';
}

/**
 * Execute a shell command and return its output, exit code, and stderr.
 */
if (!function_exists('updates_exec')) {
    function updates_exec(string $command): array {
        $output = [];
        $exitCode = -1;
        exec($command . ' 2>&1', $output, $exitCode);
        return [
            'output'    => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }
}

/**
 * Get the project root directory (parent of public/ and secure/).
 */
if (!function_exists('updates_getProjectRoot')) {
    function updates_getProjectRoot(): ?string {
        $candidates = [
            defined('PUBLIC_FOLDER_PATH') ? dirname(PUBLIC_FOLDER_PATH) : null,
            realpath(SECURE_FOLDER_PATH . '/..'),
            realpath(SECURE_FOLDER_PATH . '/../..'),
        ];

        foreach ($candidates as $path) {
            if ($path === null) continue;

            // Verify this looks like a project root (has VERSION or .git or public/)
            if (
                is_file($path . '/VERSION') ||
                is_dir($path . '/.git') ||
                is_dir($path . '/public')
            ) {
                return $path;
            }
        }

        return null;
    }
}

/**
 * Check if git working directory is clean (no uncommitted changes).
 */
if (!function_exists('updates_isGitClean')) {
    function updates_isGitClean(string $projectRoot): array {
        $result = updates_exec("cd " . escapeshellarg($projectRoot) . " && git status --porcelain");
        $isClean = ($result['exit_code'] === 0 && trim($result['output']) === '');
        return [
            'clean'  => $isClean,
            'output' => $result['output'],
        ];
    }
}

/**
 * Get current git branch name.
 */
if (!function_exists('updates_getGitBranch')) {
    function updates_getGitBranch(string $projectRoot): string {
        $result = updates_exec("cd " . escapeshellarg($projectRoot) . " && git rev-parse --abbrev-ref HEAD");
        return ($result['exit_code'] === 0) ? trim($result['output']) : 'unknown';
    }
}

/**
 * Get current git commit hash (short).
 */
if (!function_exists('updates_getGitCommit')) {
    function updates_getGitCommit(string $projectRoot): string {
        $result = updates_exec("cd " . escapeshellarg($projectRoot) . " && git rev-parse --short HEAD");
        return ($result['exit_code'] === 0) ? trim($result['output']) : 'unknown';
    }
}

/**
 * Main command function.
 */
function __command_applyUpdate(array $params = [], array $urlParams = []): ApiResponse {

    // ── 1. Determine project root ────────────────────────────────────
    $projectRoot = updates_getProjectRoot();
    if ($projectRoot === null) {
        return ApiResponse::create(500, 'update.no_project_root')
            ->withMessage('Could not determine the project root directory.');
    }

    // ── 2. Read current version ──────────────────────────────────────
    $currentVersion = updates_getLocalVersion();
    if ($currentVersion === null) {
        return ApiResponse::create(500, 'update.no_version_file')
            ->withMessage('VERSION file not found. Cannot determine current version.');
    }

    // ── 3. Detect install method ─────────────────────────────────────
    $gitDir = $projectRoot . '/.git';
    $isGitInstall = is_dir($gitDir);

    // ── 4. Check for available update ────────────────────────────────
    $latest = updates_fetchLatestTag(QUICKSITE_GITHUB_OWNER, QUICKSITE_GITHUB_REPO);
    if ($latest === null) {
        return ApiResponse::create(502, 'update.github_unreachable')
            ->withMessage('Could not reach GitHub. Check your internet connection.');
    }

    if (version_compare($latest['version'], $currentVersion) <= 0) {
        return ApiResponse::create(200, 'update.already_up_to_date')
            ->withMessage("Already at the latest version ({$currentVersion}).")
            ->withData([
                'current_version' => $currentVersion,
                'latest_version'  => $latest['version'],
                'updated' => false,
            ]);
    }

    // ── 5. Apply update based on install method ──────────────────────

    if ($isGitInstall) {
        return updates_applyGit($projectRoot, $currentVersion, $latest);
    } else {
        return updates_applyZip($projectRoot, $currentVersion, $latest);
    }
}

/**
 * Apply update via git pull.
 */
if (!function_exists('updates_applyGit')) {
    function updates_applyGit(string $projectRoot, string $currentVersion, array $latest): ApiResponse {
        $branch = updates_getGitBranch($projectRoot);
        $commitBefore = updates_getGitCommit($projectRoot);

        // Check for uncommitted changes
        $status = updates_isGitClean($projectRoot);
        if (!$status['clean']) {
            return ApiResponse::create(409, 'update.git_dirty')
                ->withMessage('Your working directory has uncommitted changes. Please commit or stash them before updating.')
                ->withData([
                    'current_version' => $currentVersion,
                    'latest_version'  => $latest['version'],
                    'branch'          => $branch,
                    'uncommitted'     => $status['output'],
                    'updated'         => false,
                ]);
        }

        // git fetch + pull
        $fetchResult = updates_exec("cd " . escapeshellarg($projectRoot) . " && git fetch --tags origin");
        if ($fetchResult['exit_code'] !== 0) {
            return ApiResponse::create(502, 'update.git_fetch_failed')
                ->withMessage('git fetch failed: ' . $fetchResult['output'])
                ->withData(['updated' => false]);
        }

        $pullResult = updates_exec("cd " . escapeshellarg($projectRoot) . " && git pull origin " . escapeshellarg($branch));
        if ($pullResult['exit_code'] !== 0) {
            return ApiResponse::create(500, 'update.git_pull_failed')
                ->withMessage('git pull failed: ' . $pullResult['output'])
                ->withData([
                    'current_version' => $currentVersion,
                    'latest_version'  => $latest['version'],
                    'branch'          => $branch,
                    'updated'         => false,
                ]);
        }

        // Re-read version after pull
        // Clear file stat cache so we get the updated VERSION
        clearstatcache(true);
        $newVersion = updates_getLocalVersion() ?? $currentVersion;
        $commitAfter = updates_getGitCommit($projectRoot);

        return ApiResponse::create(200, 'update.success')
            ->withMessage("Updated successfully from {$currentVersion} to {$newVersion}")
            ->withData([
                'previous_version' => $currentVersion,
                'current_version'  => $newVersion,
                'latest_version'   => $latest['version'],
                'method'           => 'git',
                'branch'           => $branch,
                'commit_before'    => $commitBefore,
                'commit_after'     => $commitAfter,
                'pull_output'      => $pullResult['output'],
                'updated'          => true,
            ]);
    }
}

/**
 * Apply update via ZIP download from GitHub.
 */
if (!function_exists('updates_applyZip')) {
    function updates_applyZip(string $projectRoot, string $currentVersion, array $latest): ApiResponse {
        $owner = QUICKSITE_GITHUB_OWNER;
        $repo  = QUICKSITE_GITHUB_REPO;
        $tag   = $latest['tag'];

        // Download URL for the source ZIP from GitHub
        $zipUrl = "https://github.com/{$owner}/{$repo}/archive/refs/tags/" . urlencode($tag) . ".zip";

        $tempDir  = sys_get_temp_dir() . '/quicksite_update_' . time();
        $tempZip  = $tempDir . '/update.zip';

        // Create temp directory
        if (!mkdir($tempDir, 0755, true)) {
            return ApiResponse::create(500, 'update.temp_dir_failed')
                ->withMessage('Could not create temporary directory for update.')
                ->withData(['updated' => false]);
        }

        try {
            // Download ZIP
            $zipContent = updates_downloadFile($zipUrl);
            if ($zipContent === null) {
                throw new \RuntimeException('Failed to download update ZIP from GitHub.');
            }

            if (file_put_contents($tempZip, $zipContent) === false) {
                throw new \RuntimeException('Failed to write ZIP file to temp directory.');
            }

            // Extract ZIP
            $zip = new \ZipArchive();
            $openResult = $zip->open($tempZip);
            if ($openResult !== true) {
                throw new \RuntimeException("Failed to open ZIP file (error code: {$openResult}).");
            }

            $zip->extractTo($tempDir);
            $zip->close();

            // GitHub ZIPs extract to {repo}-{tag}/ folder.
            // Find that folder.
            $extractedDirs = glob($tempDir . '/*', GLOB_ONLYDIR);
            if (empty($extractedDirs)) {
                throw new \RuntimeException('ZIP extraction produced no directories.');
            }
            $sourceDir = $extractedDirs[0];

            // Protect user config files from being overwritten
            $protectedFiles = [
                'secure/management/config/target.php',
                'secure/management/config/auth.php',
                'secure/management/config/roles.php',
            ];

            // Copy files from extracted source to project root
            $filesCopied = 0;
            $filesSkipped = 0;
            $errors = [];

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath); // normalize
                $targetPath = $projectRoot . '/' . $relativePath;

                if ($item->isDir()) {
                    if (!is_dir($targetPath)) {
                        mkdir($targetPath, 0755, true);
                    }
                    continue;
                }

                // Skip protected config files
                if (in_array($relativePath, $protectedFiles, true)) {
                    $filesSkipped++;
                    continue;
                }

                // Skip project data directories
                if (str_starts_with($relativePath, 'secure/projects/')) {
                    $filesSkipped++;
                    continue;
                }

                // Copy the file
                $destDir = dirname($targetPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                if (copy($item->getPathname(), $targetPath)) {
                    $filesCopied++;
                } else {
                    $errors[] = "Failed to copy: {$relativePath}";
                }
            }

            // Clean up temp directory
            updates_removeDir($tempDir);

            // Clear stat cache and re-read version
            clearstatcache(true);
            $newVersion = updates_getLocalVersion() ?? $currentVersion;

            if (!empty($errors)) {
                return ApiResponse::create(200, 'update.partial')
                    ->withMessage("Update partially applied. {$filesCopied} files updated, " . count($errors) . " errors.")
                    ->withData([
                        'previous_version' => $currentVersion,
                        'current_version'  => $newVersion,
                        'latest_version'   => $latest['version'],
                        'method'           => 'zip',
                        'files_copied'     => $filesCopied,
                        'files_skipped'    => $filesSkipped,
                        'errors'           => $errors,
                        'updated'          => true,
                    ]);
            }

            return ApiResponse::create(200, 'update.success')
                ->withMessage("Updated successfully from {$currentVersion} to {$newVersion}")
                ->withData([
                    'previous_version' => $currentVersion,
                    'current_version'  => $newVersion,
                    'latest_version'   => $latest['version'],
                    'method'           => 'zip',
                    'files_copied'     => $filesCopied,
                    'files_skipped'    => $filesSkipped,
                    'updated'          => true,
                ]);

        } catch (\Exception $e) {
            // Clean up on failure
            if (is_dir($tempDir)) {
                updates_removeDir($tempDir);
            }

            return ApiResponse::create(500, 'update.zip_failed')
                ->withMessage('ZIP update failed: ' . $e->getMessage())
                ->withData([
                    'current_version' => $currentVersion,
                    'latest_version'  => $latest['version'],
                    'updated'         => false,
                ]);
        }
    }
}

/**
 * Download a file from a URL. Returns contents or null on failure.
 */
if (!function_exists('updates_downloadFile')) {
    function updates_downloadFile(string $url): ?string {
        $headers = [
            'User-Agent: QuickSite-CMS-UpdateChecker/1.0',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode >= 400) {
                return null;
            }
            return $response;
        }

        // Fallback
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers),
                'timeout' => 120,
                'follow_location' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        return ($response !== false) ? $response : null;
    }
}

/**
 * Recursively remove a directory.
 */
if (!function_exists('updates_removeDir')) {
    function updates_removeDir(string $dir): void {
        if (!is_dir($dir)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_applyUpdate($trimParams->params(), $trimParams->additionalParams())->send();
}
