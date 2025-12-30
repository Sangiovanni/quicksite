<?php
/**
 * listAliases - List all URL redirect aliases
 * 
 * @method GET
 * @url /management/listAliases
 * @auth required
 * @permission read
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_listAliases(array $params = [], array $urlParams = []): ApiResponse {
    // Load aliases file
    $aliasesFile = PROJECT_PATH . '/data/aliases.json';

    if (!file_exists($aliasesFile)) {
        return ApiResponse::create(200, 'operation.success')
            ->withMessage('No aliases defined')
            ->withData([
                'aliases' => [],
                'count' => 0
            ]);
    }

    $content = file_get_contents($aliasesFile);
    $aliases = json_decode($content, true);

    if ($aliases === null) {
        return ApiResponse::create(500, 'api.error.read_failed')
            ->withMessage('Failed to parse aliases file');
    }

    // Format aliases as array with full info
    $aliasList = [];
    foreach ($aliases as $path => $info) {
        $aliasList[] = [
            'alias' => $path,
            'target' => $info['target'],
            'type' => $info['type'],
            'redirect_code' => $info['type'] === 'redirect' ? 301 : null,
            'created' => $info['created'] ?? null
        ];
    }

    // Sort by alias path
    usort($aliasList, fn($a, $b) => strcasecmp($a['alias'], $b['alias']));

    // Count by type
    $redirectCount = count(array_filter($aliasList, fn($a) => $a['type'] === 'redirect'));
    $internalCount = count(array_filter($aliasList, fn($a) => $a['type'] === 'internal'));

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Aliases listed successfully')
        ->withData([
            'aliases' => $aliasList,
            'count' => count($aliasList),
            'by_type' => [
                'redirect' => $redirectCount,
                'internal' => $internalCount
            ]
        ]);
}

// Execute via HTTP (only when not called internally)
if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listAliases()->send();
}
