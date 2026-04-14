<?php
/**
 * injectSnippetCss - Inject a snippet's saved CSS into the target project stylesheet
 * 
 * @method POST
 * @url /management/injectSnippetCss
 * @auth required
 * @permission edit
 * @param string $id Required - Snippet ID to read CSS from
 * @param string $mode Required - "missing" (add only missing selectors) or "replace" (overwrite all)
 * @param string $project Optional - Target project name (defaults to active project)
 * 
 * Mode "missing": appends only CSS rules for selectors not found in the target stylesheet.
 * Mode "replace": removes existing matching rules, then appends the snippet's full CSS block.
 * Both modes include :root variables referenced by the injected rules.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/functions/SnippetManagement.php';

/**
 * Command function for internal execution via CommandRunner or direct PHP call
 * 
 * @param array $params Body parameters
 * @param array $urlParams URL segments (unused)
 * @return ApiResponse
 */
function __command_injectSnippetCss(array $params = [], array $urlParams = []): ApiResponse {
    $snippetId = $params['id'] ?? null;
    $mode = $params['mode'] ?? null;
    $projectName = $params['project'] ?? null;

    // Validate required fields
    if (!$snippetId) {
        return ApiResponse::create(400, 'snippets.id_required')
            ->withMessage('Snippet ID is required');
    }

    if (!$mode || !in_array($mode, ['missing', 'replace'], true)) {
        return ApiResponse::create(400, 'snippets.invalid_mode')
            ->withMessage('Mode must be "missing" or "replace"');
    }

    // Get project name if not provided
    if (!$projectName) {
        $targetFile = SECURE_FOLDER_PATH . '/management/config/target.php';
        if (file_exists($targetFile)) {
            $target = include $targetFile;
            $projectName = is_array($target) ? ($target['project'] ?? null) : $target;
        }
    }

    if (!$projectName) {
        return ApiResponse::create(400, 'snippets.project_required')
            ->withMessage('No project specified and no active project found');
    }

    // Load snippet
    $snippet = getSnippetById($snippetId, $projectName);
    if ($snippet === null) {
        return ApiResponse::create(404, 'snippets.not_found')
            ->withMessage('Snippet not found: ' . $snippetId);
    }

    // Validate snippet has CSS data
    $snippetCss = $snippet['css'] ?? '';
    $snippetSelectors = $snippet['selectors'] ?? [];

    if (empty($snippetCss)) {
        return ApiResponse::create(400, 'snippets.no_css')
            ->withMessage('This snippet has no saved CSS to inject');
    }

    // Load or create target stylesheet — read from live public (source of truth)
    $liveStylePath = PUBLIC_CONTENT_PATH . '/style/style.css';
    $projectStylePath = SECURE_FOLDER_PATH . '/projects/' . $projectName . '/public/style/style.css';

    // Ensure both directories exist
    foreach ([$liveStylePath, $projectStylePath] as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // Read from live public file (what the browser actually serves)
    $existingCss = file_exists($liveStylePath) ? file_get_contents($liveStylePath) : '';

    // Parse the snippet's CSS string to extract individual rules
    $snippetParser = new CssParser($snippetCss);
    $snippetRules = $snippetParser->listSelectors();

    // Parse the target stylesheet
    $targetParser = new CssParser($existingCss);
    $targetSelectors = $targetParser->listSelectors();
    $targetSelectorNames = array_map(fn($s) => $s['selector'], $targetSelectors);

    // Build list of snippet class/ID selectors for matching
    $snippetClasses = $snippetSelectors['classes'] ?? [];
    $snippetIds = $snippetSelectors['ids'] ?? [];

    if ($mode === 'missing') {
        // Find which class/ID selectors are missing from target
        $missingClasses = [];
        foreach ($snippetClasses as $class) {
            $sel = '.' . $class;
            if (!in_array($sel, $targetSelectorNames, true)) {
                $missingClasses[] = $class;
            }
        }
        $missingIds = [];
        foreach ($snippetIds as $id) {
            $sel = '#' . $id;
            if (!in_array($sel, $targetSelectorNames, true)) {
                $missingIds[] = $id;
            }
        }

        if (empty($missingClasses) && empty($missingIds)) {
            return ApiResponse::create(200, 'snippets.css_already_present')
                ->withMessage('All CSS selectors already exist in the project stylesheet')
                ->withData(['injected' => [], 'mode' => $mode]);
        }

        // Extract only the rules for missing selectors from snippet CSS
        $filtered = $snippetParser->getCssForSelectors($missingClasses, $missingIds, []);
        $cssToInject = $snippetParser->formatExtractedCss($filtered);

        $injectedSelectors = array_merge(
            array_map(fn($c) => '.' . $c, $missingClasses),
            array_map(fn($i) => '#' . $i, $missingIds)
        );

    } else {
        // Mode "replace": remove existing matching rules, then append all
        $updatedCss = $existingCss;

        // For each rule in the snippet CSS, check if it matches a snippet selector
        // and if so, remove that rule from the target stylesheet
        foreach ($snippetRules as $ruleInfo) {
            $ruleSel = $ruleInfo['selector'];
            if ($ruleInfo['mediaQuery'] !== null) {
                continue; // Only remove global rules for simplicity
            }
            // Check if this rule references any snippet class/ID
            $allSnippetSelNames = array_merge(
                array_map(fn($c) => '.' . $c, $snippetClasses),
                array_map(fn($i) => '#' . $i, $snippetIds)
            );
            foreach ($allSnippetSelNames as $snipSel) {
                $escaped = preg_quote($snipSel, '/');
                if (preg_match('/' . $escaped . '(?![a-zA-Z0-9_-])/', $ruleSel)) {
                    $tempParser = new CssParser($updatedCss);
                    $updatedCss = $tempParser->removeGlobalRule($ruleSel);
                    break;
                }
            }
        }

        $existingCss = $updatedCss;
        $cssToInject = $snippetCss;
        $injectedSelectors = array_merge(
            array_map(fn($c) => '.' . $c, $snippetClasses),
            array_map(fn($i) => '#' . $i, $snippetIds)
        );
    }

    if (empty(trim($cssToInject))) {
        return ApiResponse::create(200, 'snippets.css_nothing_to_inject')
            ->withMessage('No CSS rules to inject')
            ->withData(['injected' => [], 'mode' => $mode]);
    }

    // Append CSS with comment marker
    $comment = "/* Snippet: {$snippetId} — " . ($mode === 'missing' ? 'added missing CSS' : 'replaced CSS') . " */";
    $newCss = rtrim($existingCss) . "\n\n{$comment}\n" . $cssToInject . "\n";

    // Write to BOTH locations: live public + project backup
    $writeFailed = false;
    if (file_put_contents($liveStylePath, $newCss, LOCK_EX) === false) {
        $writeFailed = true;
    }
    if (file_put_contents($projectStylePath, $newCss, LOCK_EX) === false) {
        $writeFailed = true;
    }
    if ($writeFailed) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write stylesheet');
    }

    return ApiResponse::create(200, 'snippets.css_injected')
        ->withMessage('CSS ' . ($mode === 'missing' ? 'added' : 'replaced') . ' successfully')
        ->withData([
            'injected' => $injectedSelectors,
            'mode' => $mode,
            'snippetId' => $snippetId,
            'project' => $projectName
        ]);
}

// Execute command if called directly via API (not internal call)
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_injectSnippetCss($trimParams->params(), $trimParams->additionalParams())->send();
}
