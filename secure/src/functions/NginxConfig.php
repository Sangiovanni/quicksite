<?php
/**
 * Nginx configuration generator for QuickSite
 * 
 * Generates dynamic_routes.conf with try_files location blocks
 * for nginx servers where .htaccess is not supported.
 * 
 * Apache users can ignore this entirely — .htaccess files handle routing.
 * Nginx users include the generated config in their server block.
 */

/**
 * Generate nginx location block content for QuickSite routing
 * 
 * Creates 4 location blocks in order of specificity:
 *   1. /prefix/admin/api/    — Admin panel AJAX helper
 *   2. /prefix/management/   — Management API (108 commands)
 *   3. /prefix/admin/        — Admin panel
 *   4. /prefix/              — Public site (catch-all)
 * 
 * @param string $publicFolderSpace URL prefix (e.g., 'quicksite/test' or '')
 * @return string Nginx configuration content
 */
function generate_nginx_config(string $publicFolderSpace): string {
    $prefix = $publicFolderSpace !== '' ? '/' . trim($publicFolderSpace, '/') : '';
    
    $date = date('Y-m-d H:i:s');
    
    $config = "# ==========================================================\n";
    $config .= "# QuickSite — nginx dynamic routes configuration\n";
    $config .= "# ==========================================================\n";
    $config .= "# Auto-generated on {$date} by QuickSite\n";
    $config .= "# Do NOT edit manually — regenerated when public space changes.\n";
    $config .= "#\n";
    $config .= "# Usage: Include this file in your nginx server {} block:\n";
    $config .= "#   include /path/to/secure/nginx/dynamic_routes.conf;\n";
    $config .= "#\n";
    $config .= "# QuickSite attempts to reload nginx automatically when\n";
    $config .= "# the public space configuration changes (requires sudoers setup).\n";
    $config .= "#\n";
    $config .= "# Manual reload: nginx -t && nginx -s reload\n";
    $config .= "# Cron fallback: secure/cron/nginx_reload.sh (optional)\n";
    $config .= "# ==========================================================\n\n";

    // Admin API (most specific path — must come first)
    $config .= "# Admin panel API (AJAX helper for dynamic form fields)\n";
    $config .= "location {$prefix}/admin/api/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/api/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Management API
    $config .= "# Management API (QuickSite command endpoint)\n";
    $config .= "location {$prefix}/management/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/management/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Admin panel
    $config .= "# Admin panel\n";
    $config .= "location {$prefix}/admin/ {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/admin/index.php\$is_args\$args;\n";
    $config .= "}\n\n";

    // Public site catch-all
    $locationPath = $prefix !== '' ? "{$prefix}/" : '/';
    $config .= "# Public site (catch-all for QuickSite routes)\n";
    $config .= "location {$locationPath} {\n";
    $config .= "    try_files \$uri \$uri/ {$prefix}/index.php\$is_args\$args;\n";
    $config .= "}\n";

    return $config;
}

/**
 * Write nginx dynamic_routes.conf and attempt to reload nginx
 * 
 * Creates secure/nginx/ directory if needed, writes the config file,
 * then attempts to reload nginx directly (requires sudoers setup).
 * If direct reload fails, sets a .pending_reload flag for the optional
 * cron-based fallback script (secure/cron/nginx_reload.sh).
 * 
 * @param string $publicFolderSpace URL prefix (e.g., 'quicksite/test' or '')
 * @param string $secureFolderPath  Absolute path to the secure folder
 * @return array{success: bool, config_path: string, nginx_reloaded: bool, error?: string, reload_error?: string}
 */
function write_nginx_dynamic_routes(string $publicFolderSpace, string $secureFolderPath): array {
    $nginxDir = $secureFolderPath . DIRECTORY_SEPARATOR . 'nginx';
    $configPath = $nginxDir . DIRECTORY_SEPARATOR . 'dynamic_routes.conf';

    // Create nginx directory if it doesn't exist
    if (!is_dir($nginxDir)) {
        if (!mkdir($nginxDir, 0755, true)) {
            return [
                'success' => false,
                'config_path' => $configPath,
                'nginx_reloaded' => false,
                'error' => 'Failed to create nginx directory: ' . $nginxDir
            ];
        }
    }

    // Generate and write config
    $content = generate_nginx_config($publicFolderSpace);

    if (file_put_contents($configPath, $content, LOCK_EX) === false) {
        return [
            'success' => false,
            'config_path' => $configPath,
            'nginx_reloaded' => false,
            'error' => 'Failed to write nginx config: ' . $configPath
        ];
    }

    // Attempt direct nginx reload (requires sudoers setup)
    $reloaded = try_nginx_reload($nginxDir);

    return [
        'success' => true,
        'config_path' => $configPath,
        'nginx_reloaded' => $reloaded['reloaded'],
        'reload_note' => $reloaded['reloaded']
            ? 'nginx reloaded successfully'
            : $reloaded['reason']
    ];
}

/**
 * Attempt to test and reload nginx directly via shell
 * 
 * Tries: sudo nginx -t && sudo nginx -s reload
 * 
 * If shell_exec is disabled or sudo is not configured, this fails silently
 * and sets a .pending_reload flag for the optional cron fallback.
 * 
 * To enable direct reload (recommended, no cron needed):
 *   echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx' | sudo tee /etc/sudoers.d/quicksite-nginx
 * 
 * @param string $nginxDir Path to secure/nginx/ directory
 * @return array{reloaded: bool, reason: string}
 */
function try_nginx_reload(string $nginxDir): array {
    $flagPath = $nginxDir . DIRECTORY_SEPARATOR . '.pending_reload';

    // Check if shell_exec is available
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'reason' => 'shell_exec disabled — set up cron fallback or reload nginx manually'
        ];
    }

    // Check if shell_exec is in disabled_functions
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled)) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'reason' => 'shell_exec in disabled_functions — set up cron fallback or reload nginx manually'
        ];
    }

    // Try nginx -t first (validate config)
    $testOutput = shell_exec('sudo nginx -t 2>&1');
    if ($testOutput === null || strpos($testOutput, 'successful') === false) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'reason' => 'nginx -t failed: ' . ($testOutput ?? 'no output (sudo not configured?)')
        ];
    }

    // Config valid — reload
    $reloadOutput = shell_exec('sudo nginx -s reload 2>&1');
    if ($reloadOutput === null || (trim($reloadOutput) !== '' && strpos($reloadOutput, 'error') !== false)) {
        file_put_contents($flagPath, date('Y-m-d H:i:s') . "\n", LOCK_EX);
        return [
            'reloaded' => false,
            'reason' => 'nginx -s reload failed: ' . ($reloadOutput ?? 'no output')
        ];
    }

    // Success — remove any stale flag
    if (file_exists($flagPath)) {
        unlink($flagPath);
    }

    // Log successful reload
    $logDir = dirname($nginxDir) . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($logDir)) {
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'nginx_reload.log';
        file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] OK: nginx reloaded by PHP (public space change)' . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    return [
        'reloaded' => true,
        'reason' => 'nginx reloaded directly via sudo'
    ];
}
