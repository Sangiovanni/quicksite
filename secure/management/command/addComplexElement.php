<?php
/**
 * addComplexElement - Insert a wizard-built subtree atomically
 *
 * @method POST
 * @url /management/addComplexElement
 * @auth required
 * @permission editStructure
 *
 * Body (JSON): {
 *   "kind": "form-scaffold",           // Required: which builder to dispatch to.
 *   "config": { ... },                  // Required: builder-specific config.
 *   "structType": "page",               // Required: page|component|menu|footer.
 *   "pageName": "...",                  // Required for page/component.
 *   "targetNodeId": "0.1",              // Required: where to splice (or 'root').
 *   "position": "after"                 // Optional: before|after|inside. Default 'after'.
 * }
 *
 * Pure dispatcher — looks up the builder for `kind`, has it produce
 * a single root node spec (with arbitrary nested children), validates
 * the result, then splices the WHOLE subtree into the structure JSON
 * under one file lock by reusing addNode's insertion helper.
 *
 * After save, the emitted subtree is indistinguishable from a
 * hand-built one. The wizard is build-time only; nothing at render
 * time knows or cares an element came from here.
 */

require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';
require_once SECURE_FOLDER_PATH . '/src/classes/NodeNavigator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ComplexElementBuilder.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/reservedStorageKeys.php';

// We're about to `require_once addNode.php` only to reuse its
// `insertNodeIntoStructure_addNode()` helper. addNode.php has a bottom
// dispatch guarded by `defined('COMMAND_INTERNAL_CALL')` — if we leave
// the constant undefined, that bottom block fires with empty params,
// returns 400 "Required parameter missing", and `->send()` exits the
// script BEFORE our code runs. So: capture whether we're the direct
// HTTP-dispatched command, then define the constant so addNode skips,
// then dispatch ourselves at the bottom using the captured flag.
$_qsAddComplexElement_isDirectHttpCall = !defined('COMMAND_INTERNAL_CALL');
if ($_qsAddComplexElement_isDirectHttpCall) {
    define('COMMAND_INTERNAL_CALL', true);
}

require_once SECURE_FOLDER_PATH . '/src/classes/TagRegistry.php';
require_once SECURE_FOLDER_PATH . '/src/classes/IframeSandbox.php';
require_once SECURE_FOLDER_PATH . '/src/classes/Translator.php';
require_once SECURE_FOLDER_PATH . '/src/classes/JsonToHtmlRenderer.php';
require_once SECURE_FOLDER_PATH . '/management/command/addNode.php';

/**
 * Lazy-load all builders under secure/src/classes/complexElements/ and
 * register them by their declared kind. Called once per command run.
 *
 * @return array<string, ComplexElementBuilder>
 */
function __addComplexElement_loadBuilders(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = [];
    $dir = SECURE_FOLDER_PATH . '/src/classes/complexElements';
    if (!is_dir($dir)) return $registry;

    foreach (glob($dir . '/*.php') as $file) {
        require_once $file;
    }
    foreach (get_declared_classes() as $cls) {
        if (is_subclass_of($cls, 'ComplexElementBuilder')) {
            try {
                $instance = new $cls();
                $kind = $instance->kind();
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $kind)) {
                    error_log("[addComplexElement] Builder $cls returned invalid kind: '$kind'");
                    continue;
                }
                if (isset($registry[$kind])) {
                    error_log("[addComplexElement] Duplicate kind '$kind' — keeping first one");
                    continue;
                }
                $registry[$kind] = $instance;
            } catch (\Throwable $e) {
                error_log("[addComplexElement] Failed to instantiate $cls: " . $e->getMessage());
            }
        }
    }
    return $registry;
}

/**
 * Recursively strip any `_wizard_*` keys from a node spec.
 * Defence-in-depth — builders should already do this on their config,
 * but we run it on the emitted subtree too.
 */
function __addComplexElement_stripWizardKeys(array $node): array {
    $out = [];
    foreach ($node as $k => $v) {
        if (is_string($k) && strpos($k, '_wizard_') === 0) continue;
        $out[$k] = is_array($v) ? __addComplexElement_stripWizardKeys($v) : $v;
    }
    return $out;
}

/**
 * Shallow validation that the builder returned a usable single root.
 * Deep validation happens at render time (renderer rejects malformed
 * nodes with an HTML comment).
 */
function __addComplexElement_validateRoot(array $node): ?string {
    if (!isset($node['tag']) && !isset($node['component']) && !isset($node['textKey'])) {
        return "Builder returned a node without 'tag', 'component', or 'textKey'";
    }
    if (isset($node['tag']) && !is_string($node['tag'])) {
        return "Builder returned a non-string 'tag'";
    }
    if (isset($node['children']) && !is_array($node['children'])) {
        return "Builder returned non-array 'children'";
    }
    return null;
}

function __command_addComplexElement(array $params = [], array $urlParams = []): ApiResponse {
    // ----- Parameter validation -----
    $required = ['kind', 'config', 'structType', 'targetNodeId'];
    $missing = [];
    foreach ($required as $f) {
        if (!isset($params[$f]) || $params[$f] === '' || $params[$f] === null) $missing[] = $f;
    }
    if (!empty($missing)) {
        // Include the keys we DID see + their types so the client can
        // diagnose body-parsing vs payload-shape vs serialisation issues
        // without needing server-log access. Cheap, doesn't leak secrets.
        $seen = [];
        foreach ($params as $k => $v) {
            $seen[$k] = is_array($v)
                ? ('array(' . count($v) . ')')
                : (is_string($v) ? ('string(' . strlen($v) . ')') : gettype($v));
        }
        return ApiResponse::create(400, 'validation.required')
            ->withMessage('Missing required: ' . implode(', ', $missing))
            ->withErrors(array_map(fn($f) => ['field' => $f, 'reason' => 'missing'], $missing))
            ->withData(['receivedKeys' => $seen]);
    }

    $kind = $params['kind'];
    $config = is_array($params['config']) ? $params['config'] : [];
    $structType = $params['structType'];
    $pageName = $params['pageName'] ?? null;
    $targetNodeId = $params['targetNodeId'];
    $position = $params['position'] ?? 'after';

    if (!preg_match('/^[a-z][a-z0-9-]*$/', $kind)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid kind '$kind'. Use lowercase + hyphens.");
    }
    $allowedTypes = ['menu', 'footer', 'page', 'component'];
    if (!in_array($structType, $allowedTypes, true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage('Invalid structType. Must be: ' . implode(', ', $allowedTypes));
    }
    if (($structType === 'page' || $structType === 'component') && empty($pageName)) {
        return ApiResponse::create(400, 'validation.required')
            ->withMessage("pageName is required when structType is '{$structType}'")
            ->withErrors([['field' => 'pageName', 'reason' => "required for structType={$structType}"]]);
    }
    if (!in_array($position, ['before', 'after', 'inside'], true)) {
        return ApiResponse::create(400, 'validation.invalid_value')
            ->withMessage("Invalid position. Must be 'before', 'after', or 'inside'");
    }
    $isRootTarget = ($targetNodeId === 'root');
    if (!$isRootTarget && !RegexPatterns::match('node_id', $targetNodeId)) {
        return ApiResponse::create(400, 'validation.invalid_format')
            ->withMessage("Invalid targetNodeId format. Use dot notation like '0.2.1' or 'root'");
    }
    if ($isRootTarget) {
        $position = 'inside';
    }

    // ----- Resolve builder -----
    $registry = __addComplexElement_loadBuilders();
    if (!isset($registry[$kind])) {
        return ApiResponse::create(404, 'complex_element.unknown_kind')
            ->withMessage("Unknown complex element kind: '$kind'")
            ->withData(['availableKinds' => array_keys($registry)]);
    }
    /** @var ComplexElementBuilder $builder */
    $builder = $registry[$kind];

    // ----- Build the subtree (pure) -----
    try {
        $newNode = $builder->build($config);
    } catch (ComplexElementBuilderException $e) {
        return ApiResponse::create(400, 'complex_element.build_failed')
            ->withMessage($e->getMessage());
    } catch (\Throwable $e) {
        error_log("[addComplexElement] Builder '$kind' threw unexpectedly: " . $e->getMessage());
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Builder '$kind' failed unexpectedly. See server log.");
    }

    if (!is_array($newNode) || empty($newNode)) {
        return ApiResponse::create(500, 'complex_element.build_failed')
            ->withMessage("Builder '$kind' returned an empty or non-array node");
    }
    $newNode = __addComplexElement_stripWizardKeys($newNode);
    $err = __addComplexElement_validateRoot($newNode);
    if ($err !== null) {
        return ApiResponse::create(500, 'complex_element.build_failed')
            ->withMessage($err);
    }

    // SECURITY: walk the builder's emitted subtree for reserved-namespace
    // storage keys (slice 5b). Builders consume user-supplied `config` and
    // could propagate hostile values into emitted nodes; this is the
    // catch-all for that path. Treated as 400 (user-input rejected),
    // not 500 (builder bug) — the violation is in the config, not the
    // builder logic.
    $rkErrors = findReservedKeysInStructure($newNode);
    if (!empty($rkErrors)) {
        return ApiResponse::create(400, 'validation.reserved_key')
            ->withMessage(RESERVED_STORAGE_KEY_MESSAGE)
            ->withErrors(formatReservedKeyErrors($rkErrors));
    }

    // ----- Load structure file -----
    if ($structType === 'page') {
        $json_file = resolvePageJsonPath($pageName);
        if ($json_file === null) {
            return ApiResponse::create(404, 'route.not_found')
                ->withMessage("Page '{$pageName}' does not exist");
        }
    } elseif ($structType === 'component') {
        $json_file = PROJECT_PATH . '/templates/model/json/components/' . $pageName . '.json';
    } else {
        $json_file = PROJECT_PATH . '/templates/model/json/' . $structType . '.json';
    }
    if (!file_exists($json_file)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage("Structure file not found");
    }
    $content = @file_get_contents($json_file);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage("Failed to read structure file");
    }
    $structure = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ApiResponse::create(500, 'server.internal_error')
            ->withMessage("Invalid JSON: " . json_last_error_msg());
    }

    // ----- Verify target node -----
    if (!$isRootTarget) {
        $targetNode = NodeNavigator::getNode($structure, $targetNodeId);
        if ($targetNode === null) {
            return ApiResponse::create(404, 'node.not_found')
                ->withMessage("Target node not found at: {$targetNodeId}");
        }
        if ($position === 'inside') {
            // Same rejections as addNode — keep the editor's expectations consistent.
            if (isset($targetNode['component'])) {
                return ApiResponse::create(400, 'validation.invalid_value')
                    ->withMessage('Cannot insert inside a component. Components are atomic.');
            }
            if (isset($targetNode['textKey']) && !isset($targetNode['tag'])) {
                return ApiResponse::create(400, 'validation.invalid_value')
                    ->withMessage('Cannot insert inside a text node.');
            }
        }
    }

    // ----- Splice the subtree -----
    if ($isRootTarget) {
        // The root shape differs by structType:
        //   - PAGE:      $structure is a list/array of nodes ([{tag:main,...}]).
        //                Prepend the new subtree directly into the array.
        //   - COMPONENT: $structure is a single object with `tag` + `children`.
        //                Prepend into its children list.
        // Detect by checking if the top-level array's keys are a clean
        // 0..N-1 sequence (PHP list shape). Earlier code unconditionally
        // wrote to $structure['children'] which corrupted page roots into
        // an object-shape `{"0": main, "children": [...]}` after the
        // first 'root' insert — every subsequent operation then failed
        // because NodeNavigator can no longer address nodes.
        $isListShapeRoot = is_array($structure)
            && array_keys($structure) === range(0, count($structure) - 1);
        if ($isListShapeRoot) {
            array_unshift($structure, $newNode);
        } else {
            if (!isset($structure['children']) || !is_array($structure['children'])) {
                $structure['children'] = [];
            }
            array_unshift($structure['children'], $newNode);
        }
        $newNodeId = '0';
        $insertResult = ['success' => true, 'structure' => $structure, 'newNodeId' => $newNodeId];
    } else {
        $targetIndices = array_map('intval', explode('.', $targetNodeId));
        // Reuse addNode's insertion helper — same atomicity guarantees,
        // handles before/after/inside, and supports nested children
        // verbatim (our root node may carry arbitrary subtree).
        $insertResult = insertNodeIntoStructure_addNode(
            $structure, $targetIndices, $newNode, $position, []
        );
    }
    if (!$insertResult['success']) {
        return ApiResponse::create(400, 'operation.failed')
            ->withMessage('Failed to insert subtree: ' . ($insertResult['error'] ?? 'unknown'));
    }

    // ----- Write back -----
    $jsonOut = json_encode(
        $insertResult['structure'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (file_put_contents($json_file, $jsonOut, LOCK_EX) === false) {
        return ApiResponse::create(500, 'server.file_write_failed')
            ->withMessage('Failed to write structure file');
    }

    return ApiResponse::create(201, 'operation.success')
        ->withMessage("Complex element '$kind' inserted")
        ->withData([
            'kind' => $kind,
            'newNodeId' => $insertResult['newNodeId'],
            'newNode' => $newNode,
            'targetNodeId' => $targetNodeId,
            'position' => $position,
            'structType' => $structType,
            'pageName' => $pageName,
        ]);
}

// Direct HTTP dispatch. We can't use `!defined('COMMAND_INTERNAL_CALL')`
// here because we defined the constant at the top (to silence addNode's
// own dispatch). Use the captured flag instead.
if (!empty($_qsAddComplexElement_isDirectHttpCall)) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_addComplexElement($trimParams->params(), $trimParams->additionalParams())->send();
}
