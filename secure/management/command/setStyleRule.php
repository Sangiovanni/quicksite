<?php
/**
 * setStyleRule - Add or update a CSS style rule
 * Method: POST
 * URL: /management/setStyleRule
 * Body: {
 *   "selector": ".btn-custom",
 *   "styles": "background: #007bff; color: white; padding: 10px 20px;",
 *   "mediaQuery": "(max-width: 768px)",  // optional
 *   "removeProperties": ["border", "margin"]  // optional - properties to remove
 * }
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/RegexPatterns.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsStyleManagement.php';
require_once SECURE_FOLDER_PATH . '/src/functions/utilsManagement.php'; // qs_param_string

// Get parameters
$params = $trimParametersManagement->params();

// Validate required parameters. qs_param_string, not isset: `?selector[]=x` is SET
// but is an array, and reached trim() as a TypeError; a non-string reads as absent.
$selectorParam = qs_param_string($params, 'selector');
if ($selectorParam === null) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: selector')
        ->send();
}
if (!isset($params['styles'])) {
    ApiResponse::create(400, 'validation.required')
        ->withMessage('Missing required parameter: styles')
        ->send();
}

// A parameter of the wrong type answers 400. Falling back to a default would hide the
// mistake: a mediaQuery array would write the rule at the global scope.
$typeError = null;
if (is_array($params['styles'])) {
    foreach ($params['styles'] as $value) {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $typeError = ['styles', 'a string, or an object whose values are strings or numbers'];
            break;
        }
    }
} elseif (!is_string($params['styles'])) {
    $typeError = ['styles', 'a string, or an object whose values are strings or numbers'];
}
if ($typeError === null && isset($params['mediaQuery']) && !is_string($params['mediaQuery'])) {
    $typeError = ['mediaQuery', 'a string'];
}
if ($typeError === null && isset($params['removeProperties'])
    && (!is_array($params['removeProperties']) || array_filter($params['removeProperties'], static fn($p) => !is_string($p)))) {
    $typeError = ['removeProperties', 'an array of property names'];
}
if ($typeError !== null) {
    ApiResponse::create(400, 'validation.invalid_type')
        ->withMessage("The {$typeError[0]} parameter must be {$typeError[1]}.")
        ->withErrors([['field' => $typeError[0], 'reason' => 'invalid_type']])
        ->send();
}

$selector = trim($selectorParam);
$styles = $params['styles'];
$mediaQuery = isset($params['mediaQuery']) ? trim($params['mediaQuery']) : null;
$removeProperties = isset($params['removeProperties']) ? array_map('trim', $params['removeProperties']) : [];

// Convert styles array/object to string if necessary
if (is_array($styles)) {
    $styleLines = [];
    foreach ($styles as $property => $value) {
        $styleLines[] = $property . ': ' . $value . ';';
    }
    $styles = implode("\n    ", $styleLines);
}

// Validate selector
if (empty($selector)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Selector cannot be empty')
        ->send();
}

// Validate styles - allow empty styles if removeProperties is provided
if (empty($styles) && empty($removeProperties)) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('Styles cannot be empty (unless removeProperties is provided)')
        ->send();
}

// SECURITY: the scan every stylesheet writer runs (qs_css_first_danger), on each
// piece of CSS text this command receives and on the rule as it will be written,
// because a denylisted sequence can span two pieces.
$pieces = ['selector' => $selector, 'styles' => (string) $styles];
if ($mediaQuery !== null) {
    $pieces['mediaQuery'] = $mediaQuery;
}
$rule = $selector . " {\n" . $styles . "\n}";
$pieces['rule'] = $mediaQuery !== null ? '@media ' . $mediaQuery . " {\n" . $rule . "\n}" : $rule;
foreach ($pieces as $field => $text) {
    $danger = qs_css_first_danger($text);
    if ($danger !== null) {
        ApiResponse::create(400, 'validation.security')
            ->withMessage('Potentially dangerous CSS pattern detected')
            ->withErrors([
                ['field' => $field, 'reason' => 'dangerous_pattern', 'pattern' => $danger]
            ])
            ->send();
    }
}

// Brace confinement — a `{` or `}` in the selector, the media query, or the
// declaration block lets the input escape its rule and emit arbitrary CSS. The
// denylist does not cover braces.
foreach (['selector' => $selector, 'styles' => $styles, 'mediaQuery' => $mediaQuery] as $field => $value) {
    if ($value !== null && $value !== '' && !qs_css_confine((string) $value)) {
        ApiResponse::create(400, 'validation.security')
            ->withMessage('CSS ' . $field . ' may not contain "{" or "}"')
            ->send();
    }
}

// The styles must be declarations a stylesheet can hold (CssParser::DECLARATION_RULE):
// a quote, a comment or a bracket left open reads on into the rules after this one.
$problem = CssParser::declarationProblem((string) $styles);
if ($problem !== null) {
    ApiResponse::create(400, 'validation.invalid_format')
        ->withMessage('The styles are not CSS declarations: ' . CssParser::DECLARATION_RULE)
        ->withErrors([['field' => 'styles', 'reason' => $problem]])
        ->send();
}

// Validate the media query, if provided, with the one media-query rule.
if ($mediaQuery !== null && !RegexPatterns::match('media_query_chars', $mediaQuery)) {
    ApiResponse::create(400, 'validation.invalid_media_query')
        ->withMessage('Invalid media query format')
        ->send();
}

$styleFile = cssLivePath();

// Check file exists
if (!file_exists($styleFile)) {
    ApiResponse::create(404, 'file.not_found')
        ->withMessage('Style file not found')
        ->send();
}

// Use file locking
$lock = cssAcquireLock($styleFile);
if ($lock === null) {
    ApiResponse::create(500, 'server.lock_failed')
        ->withMessage('Could not acquire file lock')
        ->send();
}

try {
    // Read current content
    $content = file_get_contents($styleFile);
    if ($content === false) {
        throw new Exception('Failed to read style file');
    }
    
    // Parse and update
    $parser = new CssParser($content);
    $result = $parser->setStyleRule($selector, $styles, $mediaQuery, $removeProperties);
    
    // Write updated content to live stylesheet and project backup copy
    cssWriteAllTargets($parser->getContent(), $styleFile, cssProjectPath());

    cssReleaseLock($lock);

    ApiResponse::create(200, 'operation.success')
        ->withMessage('Style rule ' . $result['action'] . ' successfully')
        ->withData([
            'action' => $result['action'],
            'selector' => $result['selector'],
            'mediaQuery' => $result['mediaQuery'],
            'styles' => $styles
        ])
        ->send();

} catch (Exception $e) {
    cssReleaseLock($lock);
    ApiResponse::create(500, 'server.operation_failed')
        ->withMessage($e->getMessage())
        ->send();
}
