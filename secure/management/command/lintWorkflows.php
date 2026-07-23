<?php
/**
 * lintWorkflows - Find duplicated prose across workflow templates
 *
 * Scans every *.md template under secure/admin/workflows/core/, hashes
 * paragraphs (>= 3 lines), and reports paragraphs that occur in 3 or more workflows.
 * Each report includes a suggested block name and the list of workflows containing it,
 * so the developer can decide whether to extract the paragraph into
 * secure/admin/workflows/blocks/.
 *
 * Pure dev tooling — does NOT modify any file.
 *
 * @method GET
 * @url /management/lintWorkflows
 * @auth required
 * @permission admin (will move to superadmin when that role lands; see beta.7 objective 8)
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

function __command_lintWorkflows(array $params = [], array $urlParams = []): ApiResponse {
    $base = SECURE_FOLDER_PATH . '/admin/workflows';
    $files = glob($base . '/core/*.md') ?: [];

    // hash => ['text' => ..., 'workflows' => [...]]
    $byHash = [];

    foreach ($files as $file) {
        $workflowId = basename($file, '.md');
        $content = (string)@file_get_contents($file);

        // Split into paragraphs separated by blank lines.
        $paragraphs = preg_split('/\r?\n\s*\r?\n/', $content) ?: [];

        foreach ($paragraphs as $para) {
            $lines = preg_split('/\r?\n/', trim($para)) ?: [];
            if (count($lines) < 3) continue;

            // Skip paragraphs that look like fenced code blocks or pure JSON examples
            // — those are usually intentionally per-workflow, not extractable prose.
            $first = trim($lines[0]);
            if (str_starts_with($first, '```') || str_starts_with($first, '{') || str_starts_with($first, '[')) {
                continue;
            }
            // Skip paragraphs that already use partials (not eligible for re-extraction).
            if (str_contains($para, '{{>')) continue;

            $normalized = preg_replace('/\s+/', ' ', trim($para));
            $hash = sha1($normalized);

            if (!isset($byHash[$hash])) {
                $byHash[$hash] = [
                    'text' => mb_substr($normalized, 0, 200),
                    'line_count' => count($lines),
                    'workflows' => [],
                ];
            }
            if (!in_array($workflowId, $byHash[$hash]['workflows'], true)) {
                $byHash[$hash]['workflows'][] = $workflowId;
            }
        }
    }

    $threshold = 3; // appears in this many workflows or more
    $duplications = [];
    foreach ($byHash as $hash => $info) {
        if (count($info['workflows']) < $threshold) continue;
        $duplications[] = [
            'hash' => substr($hash, 0, 12),
            'occurrences' => count($info['workflows']),
            'line_count' => $info['line_count'],
            'preview' => $info['text'],
            'workflows' => $info['workflows'],
            'suggested_block_name' => __lintWorkflows_suggestName($info['text']),
        ];
    }
    usort($duplications, fn($a, $b) => $b['occurrences'] <=> $a['occurrences']);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage(count($duplications) . ' duplicated paragraph(s) found across ' . count($files) . ' workflow template(s)')
        ->withData([
            'duplications' => $duplications,
            'scanned_files' => count($files),
            'threshold' => $threshold,
        ]);
}

function __lintWorkflows_suggestName(string $text): string {
    $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
    $stop = ['the','a','an','to','of','for','and','or','in','on','is','are','must','do','not','this','that','your','you','use','it'];
    $picked = [];
    foreach ($words as $w) {
        if ($w === '' || in_array($w, $stop, true) || mb_strlen($w) < 3) continue;
        $picked[] = $w;
        if (count($picked) >= 3) break;
    }
    return implode('-', $picked) ?: 'extracted-block';
}

if (!defined('COMMAND_INTERNAL_CALL')) {
    __command_lintWorkflows()->send();
}
