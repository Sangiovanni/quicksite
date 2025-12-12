<?php
/**
 * listAliases - List all URL redirect aliases
 * Method: GET
 * URL: /management/listAliases
 * 
 * Returns all defined URL aliases with their targets and types.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Load aliases file
$aliasesFile = SECURE_FOLDER_PATH . '/config/aliases.json';

if (!file_exists($aliasesFile)) {
    ApiResponse::create(200, 'operation.success')
        ->withMessage('No aliases defined')
        ->withData([
            'aliases' => [],
            'count' => 0
        ])
        ->send();
}

$content = file_get_contents($aliasesFile);
$aliases = json_decode($content, true);

if ($aliases === null) {
    ApiResponse::create(500, 'api.error.read_failed')
        ->withMessage('Failed to parse aliases file')
        ->send();
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

ApiResponse::create(200, 'operation.success')
    ->withMessage('Aliases listed successfully')
    ->withData([
        'aliases' => $aliasList,
        'count' => count($aliasList),
        'by_type' => [
            'redirect' => $redirectCount,
            'internal' => $internalCount
        ]
    ])
    ->send();
