<?php
/**
 * listWorkflowBlocks - List reusable prompt blocks discoverable by the workflow editor
 *
 * Returns the contents of secure/admin/workflows/{blocks,pins,warnings,examples}/
 * grouped by category, so the AI-Editor can populate multi-select dropdowns for the
 * workflow `pins` / `warnings` JSON fields and surface available `{{> name}}` partials.
 *
 * @method GET
 * @url /management/listWorkflowBlocks
 * @auth required
 * @permission admin (workflow editing surface; will move to superadmin when that role lands)
 *
 * NOTE (beta.7 sub-task / objective 8): superadmin gating audit pending.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

function __command_listWorkflowBlocks(array $params = [], array $urlParams = []): ApiResponse {
    $base = SECURE_FOLDER_PATH . '/admin/workflows';
    $categories = ['blocks', 'pins', 'warnings', 'examples'];
    $out = [];
    $total = 0;

    foreach ($categories as $cat) {
        $dir = $base . '/' . $cat;
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.md') as $file) {
                $id = basename($file, '.md');
                $contents = (string)@file_get_contents($file);
                // First non-empty markdown line as a preview snippet.
                $preview = '';
                foreach (preg_split('/\r?\n/', $contents) as $line) {
                    $trim = trim($line);
                    if ($trim !== '' && !str_starts_with($trim, '```')) {
                        $preview = mb_substr($trim, 0, 120);
                        break;
                    }
                }
                $items[] = [
                    'id' => $id,
                    'partial' => $cat === 'blocks' ? "{{> {$id}}}" : "{{> " . rtrim($cat, 's') . ".{$id}}}",
                    'size_bytes' => filesize($file) ?: 0,
                    'preview' => $preview,
                ];
            }
            usort($items, fn($a, $b) => strcasecmp($a['id'], $b['id']));
        }
        $out[$cat] = $items;
        $total += count($items);
    }

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Workflow blocks listed successfully')
        ->withData([
            'categories' => $out,
            'count' => $total,
        ]);
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_listWorkflowBlocks()->send();
}
