<?php
/**
 * Get Size Info Command
 * 
 * Returns detailed size information about the project structure.
 * Useful for monitoring disk usage and identifying what takes up space.
 * 
 * @method GET
 * @route /management/getSizeInfo
 * @auth required
 * @return ApiResponse Size information for all folders
 * 
 * @version 1.0.0
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Calculate directory size recursively
 */
if (!function_exists('sizeinfo_getDirectorySize')) {
    function sizeinfo_getDirectorySize($path) {
        if (!is_dir($path)) {
            return 0;
        }
        
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, 
                    RecursiveDirectoryIterator::SKIP_DOTS | 
                    RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            
            foreach ($iterator as $file) {
                try {
                    if ($file->isFile() && !$file->isLink()) {
                        $size += $file->getSize();
                    }
                } catch (Exception $e) {
                    // Skip inaccessible files
                }
            }
        } catch (Exception $e) {
            // If iterator fails, try a simple glob approach
            $files = glob($path . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $size += filesize($file);
                } elseif (is_dir($file)) {
                    $size += sizeinfo_getDirectorySize($file);
                }
            }
        }
        
        return $size;
    }
}

/**
 * Count files in directory
 */
if (!function_exists('sizeinfo_countFiles')) {
    function sizeinfo_countFiles($path) {
        if (!is_dir($path)) {
            return 0;
        }
        
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, 
                    RecursiveDirectoryIterator::SKIP_DOTS | 
                    RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            
            foreach ($iterator as $file) {
                try {
                    if ($file->isFile() && !$file->isLink()) {
                        $count++;
                    }
                } catch (Exception $e) {
                    // Skip inaccessible files
                }
            }
        } catch (Exception $e) {
            // If iterator fails, try a simple glob approach
            $files = glob($path . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $count++;
                } elseif (is_dir($file)) {
                    $count += sizeinfo_countFiles($file);
                }
            }
        }
        
        return $count;
    }
}

/**
 * Format size for display
 */
if (!function_exists('sizeinfo_formatSize')) {
    function sizeinfo_formatSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}

/**
 * Get folder info with size and file count
 */
if (!function_exists('sizeinfo_getFolderInfo')) {
    function sizeinfo_getFolderInfo($path, $name = null) {
        $size = sizeinfo_getDirectorySize($path);
        return [
            'name' => $name ?? basename($path),
            'path' => $path,
            'exists' => is_dir($path),
            'size' => $size,
            'size_formatted' => sizeinfo_formatSize($size),
            'files' => sizeinfo_countFiles($path)
        ];
    }
}

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters (unused)
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_getSizeInfo(array $params = [], array $urlParams = []): ApiResponse {
    // Normalize paths using realpath for proper resolution
    $publicRoot = realpath(PUBLIC_FOLDER_ROOT) ?: PUBLIC_FOLDER_ROOT;
    $secureRoot = realpath(SECURE_FOLDER_PATH) ?: SECURE_FOLDER_PATH;
    
    // Convert backslashes to forward slashes for consistency
    $publicRoot = str_replace('\\', '/', $publicRoot);
    $secureRoot = str_replace('\\', '/', $secureRoot);
    
    // ========================================
    // PUBLIC FOLDER BREAKDOWN
    // ========================================
    $publicFolders = [
        'admin' => sizeinfo_getFolderInfo($publicRoot . '/admin', 'admin'),
        'assets' => sizeinfo_getFolderInfo($publicRoot . '/assets', 'assets'),
        'build' => sizeinfo_getFolderInfo($publicRoot . '/build', 'build'),
        'management' => sizeinfo_getFolderInfo($publicRoot . '/management', 'management'),
        'style' => sizeinfo_getFolderInfo($publicRoot . '/style', 'style'),
    ];
    
    // Calculate total public size (including root files like index.php, init.php)
    $publicTotalSize = sizeinfo_getDirectorySize($publicRoot);
    $publicSubfoldersSize = array_sum(array_column($publicFolders, 'size'));
    $publicRootFilesSize = max(0, $publicTotalSize - $publicSubfoldersSize);
    
    $public = [
        'total' => [
            'size' => $publicTotalSize,
            'size_formatted' => sizeinfo_formatSize($publicTotalSize),
            'files' => sizeinfo_countFiles($publicRoot)
        ],
        'root_files' => [
            'size' => $publicRootFilesSize,
            'size_formatted' => sizeinfo_formatSize($publicRootFilesSize)
        ],
        'folders' => $publicFolders
    ];
    
    // ========================================
    // SECURE FOLDER BREAKDOWN
    // ========================================
    $secureFolders = [
        'admin' => sizeinfo_getFolderInfo($secureRoot . '/admin', 'admin'),
        'config' => sizeinfo_getFolderInfo($secureRoot . '/config', 'config'),
        'exports' => sizeinfo_getFolderInfo($secureRoot . '/exports', 'exports'),
        'logs' => sizeinfo_getFolderInfo($secureRoot . '/logs', 'logs'),
        'management' => sizeinfo_getFolderInfo($secureRoot . '/management', 'management'),
        'src' => sizeinfo_getFolderInfo($secureRoot . '/src', 'src'),
    ];
    
    // ========================================
    // PROJECTS BREAKDOWN (most important!)
    // ========================================
    $projectsRoot = $secureRoot . '/projects';
    $projectsData = [
        'total' => sizeinfo_getFolderInfo($projectsRoot, 'projects'),
        'projects' => []
    ];
    
    // Get active project
    $targetFile = $secureRoot . '/management/config/target.php';
    $activeProject = null;
    if (file_exists($targetFile)) {
        $target = include $targetFile;
        $activeProject = is_array($target) ? ($target['project'] ?? null) : $target;
    }
    
    // Iterate through each project
    if (is_dir($projectsRoot)) {
        $projectDirs = glob($projectsRoot . '/*', GLOB_ONLYDIR);
        
        foreach ($projectDirs as $projectDir) {
            $projectName = basename($projectDir);
            $projectSize = sizeinfo_getDirectorySize($projectDir);
            
            // Get backup sizes for this project
            $backupsDir = $projectDir . '/backups';
            $backupsInfo = [
                'total' => sizeinfo_getFolderInfo($backupsDir, 'backups'),
                'items' => []
            ];
            
            if (is_dir($backupsDir)) {
                $backupDirs = glob($backupsDir . '/*', GLOB_ONLYDIR);
                foreach ($backupDirs as $backupDir) {
                    $backupName = basename($backupDir);
                    $backupSize = sizeinfo_getDirectorySize($backupDir);
                    $backupsInfo['items'][] = [
                        'name' => $backupName,
                        'size' => $backupSize,
                        'size_formatted' => sizeinfo_formatSize($backupSize),
                        'is_pre_restore' => strpos($backupName, 'pre-restore') === 0
                    ];
                }
                // Sort by size descending
                usort($backupsInfo['items'], function($a, $b) {
                    return $b['size'] - $a['size'];
                });
            }
            
            // Calculate project size without backups
            $projectSizeWithoutBackups = $projectSize - $backupsInfo['total']['size'];
            
            $projectsData['projects'][$projectName] = [
                'name' => $projectName,
                'is_active' => $projectName === $activeProject,
                'total' => [
                    'size' => $projectSize,
                    'size_formatted' => sizeinfo_formatSize($projectSize),
                    'files' => sizeinfo_countFiles($projectDir)
                ],
                'without_backups' => [
                    'size' => $projectSizeWithoutBackups,
                    'size_formatted' => sizeinfo_formatSize($projectSizeWithoutBackups)
                ],
                'backups' => $backupsInfo
            ];
        }
    }
    
    // Add projects to secure folders
    $secureFolders['projects'] = $projectsData['total'];
    
    // Calculate total secure size
    $secureTotalSize = sizeinfo_getDirectorySize($secureRoot);
    $secureSubfoldersSize = array_sum(array_column($secureFolders, 'size'));
    $secureRootFilesSize = max(0, $secureTotalSize - $secureSubfoldersSize);
    
    $secure = [
        'total' => [
            'size' => $secureTotalSize,
            'size_formatted' => sizeinfo_formatSize($secureTotalSize),
            'files' => sizeinfo_countFiles($secureRoot)
        ],
        'root_files' => [
            'size' => $secureRootFilesSize,
            'size_formatted' => sizeinfo_formatSize($secureRootFilesSize)
        ],
        'folders' => $secureFolders,
        'projects_detail' => $projectsData['projects']
    ];
    
    // ========================================
    // SUMMARY BY CATEGORY
    // ========================================
    
    // Project-related: public/assets + public/style + public/build + secure/projects
    $projectSpace = $publicFolders['assets']['size'] 
                  + $publicFolders['style']['size'] 
                  + $publicFolders['build']['size']
                  + $projectsData['total']['size'];
    
    // Admin-related: public/admin + secure/admin
    $adminSpace = $publicFolders['admin']['size'] 
                + $secureFolders['admin']['size'];
    
    // Management/API-related: public/management + secure/management
    $managementSpace = $publicFolders['management']['size'] 
                     + $secureFolders['management']['size'];
    
    // Core/System: secure/src + secure/config + secure/logs + root files
    $coreSpace = $secureFolders['src']['size'] 
               + $secureFolders['config']['size'] 
               + $secureFolders['logs']['size']
               + $publicRootFilesSize
               + $secureRootFilesSize;
    
    // Total backups across all projects
    $totalBackupsSize = 0;
    foreach ($projectsData['projects'] as $project) {
        $totalBackupsSize += $project['backups']['total']['size'];
    }
    
    // Total project content (without backups)
    $totalProjectContentSize = 0;
    foreach ($projectsData['projects'] as $project) {
        $totalProjectContentSize += $project['without_backups']['size'];
    }
    
    $summary = [
        'total' => [
            'size' => $publicTotalSize + $secureTotalSize,
            'size_formatted' => sizeinfo_formatSize($publicTotalSize + $secureTotalSize)
        ],
        'by_category' => [
            'projects' => [
                'size' => $projectSpace,
                'size_formatted' => sizeinfo_formatSize($projectSpace),
                'description' => 'Project files (assets, styles, builds, project data)'
            ],
            'backups' => [
                'size' => $totalBackupsSize,
                'size_formatted' => sizeinfo_formatSize($totalBackupsSize),
                'description' => 'All project backups'
            ],
            'admin' => [
                'size' => $adminSpace,
                'size_formatted' => sizeinfo_formatSize($adminSpace),
                'description' => 'Admin panel interface'
            ],
            'management' => [
                'size' => $managementSpace,
                'size_formatted' => sizeinfo_formatSize($managementSpace),
                'description' => 'API and command system'
            ],
            'core' => [
                'size' => $coreSpace,
                'size_formatted' => sizeinfo_formatSize($coreSpace),
                'description' => 'Core system files (src, config, logs)'
            ]
        ],
        'active_project' => $activeProject ? [
            'name' => $activeProject,
            'size' => $projectsData['projects'][$activeProject]['total']['size'] ?? 0,
            'size_formatted' => $projectsData['projects'][$activeProject]['total']['size_formatted'] ?? '0 B',
            'backups_size' => $projectsData['projects'][$activeProject]['backups']['total']['size'] ?? 0,
            'backups_size_formatted' => $projectsData['projects'][$activeProject]['backups']['total']['size_formatted'] ?? '0 B',
            'backups_count' => count($projectsData['projects'][$activeProject]['backups']['items'] ?? [])
        ] : null
    ];
    
    return ApiResponse::create(200, 'size_info.success')
        ->withMessage('Size information retrieved successfully')
        ->withData([
            'summary' => $summary,
            'public' => $public,
            'secure' => $secure
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getSizeInfo($trimParams->params(), $trimParams->additionalParams())->send();
}
