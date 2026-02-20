<?php
/**
 * getAnimatedSelectors - List all selectors that have transition or animation properties
 * 
 * @method GET
 * @url /management/getAnimatedSelectors
 * @auth required
 * @permission read
 * 
 * Returns selectors grouped by base selector with pseudo-states:
 * - transitions: selectors with transition property, grouped with :hover/:active/:focus states
 * - animations: selectors with animation property, grouped with pseudo-states
 */

require_once SECURE_FOLDER_PATH . '/src/classes/CssParser.php';
require_once SECURE_FOLDER_PATH . '/src/classes/ApiResponse.php';

// Pseudo-classes to look for when grouping
const PSEUDO_CLASSES = [':hover', ':active', ':focus', ':focus-within', ':focus-visible', ':visited', ':checked', ':disabled'];

/**
 * Extract base selector and pseudo-class from a selector
 * Example: ".btn:hover" => [".btn", ":hover"]
 * Example: ".btn" => [".btn", null]
 * Example: "a:hover:focus" => ["a", ":hover:focus"]
 */
function extractBaseSelectorAndPseudo(string $selector): array {
    // Find first pseudo-class occurrence
    $firstPseudoPos = null;
    $foundPseudo = null;
    
    foreach (PSEUDO_CLASSES as $pseudo) {
        $pos = strpos($selector, $pseudo);
        if ($pos !== false && ($firstPseudoPos === null || $pos < $firstPseudoPos)) {
            $firstPseudoPos = $pos;
        }
    }
    
    if ($firstPseudoPos !== null) {
        $base = substr($selector, 0, $firstPseudoPos);
        $pseudo = substr($selector, $firstPseudoPos);
        return [trim($base), trim($pseudo)];
    }
    
    return [$selector, null];
}

/**
 * Command function for internal execution via CommandRunner
 * 
 * @param array $params Body parameters (unused for this command)
 * @param array $urlParams URL segments (unused for this command)
 * @return ApiResponse
 */
function __command_getAnimatedSelectors(array $params = [], array $urlParams = []): ApiResponse {
    $styleFile = PUBLIC_FOLDER_ROOT . '/style/style.css';

    // Check file exists
    if (!file_exists($styleFile)) {
        return ApiResponse::create(404, 'file.not_found')
            ->withMessage('Style file not found');
    }

    // Read CSS content
    $content = file_get_contents($styleFile);
    if ($content === false) {
        return ApiResponse::create(500, 'server.file_read_failed')
            ->withMessage('Failed to read style file');
    }

    // Parse CSS
    $parser = new CssParser($content);
    $allSelectors = $parser->listSelectors();
    
    // Build lookup: baseSelector => [pseudo-selectors data]
    $selectorMap = [];
    
    foreach ($allSelectors as $item) {
        $selector = $item['selector'];
        $mediaQuery = $item['mediaQuery'];
        
        // Skip media query selectors for now (complex to group)
        if ($mediaQuery) continue;
        
        // Get styles for this selector
        $styleResult = $parser->getStyleRule($selector, $mediaQuery);
        
        if (empty($styleResult) || empty($styleResult['styles'])) continue;
        
        $styles = $styleResult['styles'];
        
        // Parse styles into properties array
        $properties = parseStylesIntoProperties($styles);
        
        // Extract base selector and pseudo
        [$baseSelector, $pseudo] = extractBaseSelectorAndPseudo($selector);
        
        // Initialize base selector entry if needed
        if (!isset($selectorMap[$baseSelector])) {
            $selectorMap[$baseSelector] = [
                'base' => null,
                'states' => []
            ];
        }
        
        // Store data
        if ($pseudo === null) {
            // This is the base selector
            $selectorMap[$baseSelector]['base'] = [
                'selector' => $selector,
                'properties' => $properties
            ];
        } else {
            // This is a pseudo-state
            $selectorMap[$baseSelector]['states'][$pseudo] = [
                'pseudo' => $pseudo,
                'selector' => $selector,
                'properties' => $properties
            ];
        }
    }
    
    // Now build grouped transitions and animations
    $transitions = [];
    $animations = [];
    $triggersWithoutTransition = []; // Pseudo-states that change properties but have no transition
    
    foreach ($selectorMap as $baseSelector => $data) {
        $baseProps = $data['base']['properties'] ?? [];
        $states = array_values($data['states']); // Convert to indexed array
        
        // Check if base has transition
        $hasTransition = hasTransitionProperty($baseProps);
        $hasAnimation = hasAnimationProperty($baseProps);
        
        // Also check if any state has transition/animation (less common but possible)
        foreach ($states as $state) {
            if (hasTransitionProperty($state['properties'])) {
                $hasTransition = true;
            }
            if (hasAnimationProperty($state['properties'])) {
                $hasAnimation = true;
            }
        }
        
        // Add to transitions if relevant
        if ($hasTransition) {
            $transitionEntry = buildGroupedEntry($baseSelector, $data, 'transition');
            if ($transitionEntry) {
                $transitions[] = $transitionEntry;
            }
        }
        
        // Add to animations if relevant
        if ($hasAnimation) {
            $animationEntry = buildGroupedEntry($baseSelector, $data, 'animation');
            if ($animationEntry) {
                $animations[] = $animationEntry;
            }
        }
        
        // Collect triggers WITHOUT transition or animation (pure state changes)
        if (!$hasTransition && !$hasAnimation && !empty($states)) {
            $triggersWithoutTransition[] = buildTriggerOnlyEntry($baseSelector, $data);
        }
    }
    
    // Find related triggers for orphan transitions (transitions with no direct states)
    // An orphan is a selector with transition but empty states array
    // Related triggers are selectors that START with the base selector (e.g., .btn-primary:hover for .btn)
    $transitions = findRelatedTriggersForOrphans($transitions, $triggersWithoutTransition, $selectorMap);

    return ApiResponse::create(200, 'operation.success')
        ->withMessage('Animated selectors retrieved successfully')
        ->withData([
            'transitions' => $transitions,
            'animations' => $animations,
            'triggersWithoutTransition' => $triggersWithoutTransition,
            'transitionCount' => count($transitions),
            'animationCount' => count($animations),
            'triggerOnlyCount' => count($triggersWithoutTransition)
        ]);
}

/**
 * Parse CSS styles string into key-value properties array
 */
function parseStylesIntoProperties(string $styles): array {
    $properties = [];
    $declarations = array_filter(array_map('trim', explode(';', $styles)));
    
    foreach ($declarations as $declaration) {
        if (empty($declaration)) continue;
        
        $parts = explode(':', $declaration, 2);
        if (count($parts) !== 2) continue;
        
        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $properties[$property] = $value;
    }
    
    return $properties;
}

/**
 * Check if properties contain transition
 */
function hasTransitionProperty(array $properties): bool {
    foreach ($properties as $prop => $value) {
        if (strpos($prop, 'transition') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Check if properties contain animation
 */
function hasAnimationProperty(array $properties): bool {
    foreach ($properties as $prop => $value) {
        if (strpos($prop, 'animation') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Extract only transition/animation properties from full properties
 */
function extractRelevantProperties(array $properties, string $type): array {
    $result = [];
    $prefix = ($type === 'transition') ? 'transition' : 'animation';
    
    foreach ($properties as $prop => $value) {
        if (strpos($prop, $prefix) === 0) {
            $result[$prop] = $value;
        }
    }
    
    return $result;
}

/**
 * Build a grouped entry for transitions or animations
 */
function buildGroupedEntry(string $baseSelector, array $data, string $type): ?array {
    $baseProps = $data['base']['properties'] ?? [];
    $states = $data['states'];
    
    // Extract relevant properties from base
    $relevantBaseProps = extractRelevantProperties($baseProps, $type);
    
    // Build the entry
    $entry = [
        'baseSelector' => $baseSelector,
        'selector' => $data['base']['selector'] ?? $baseSelector,
        'properties' => $relevantBaseProps,
        'allBaseProperties' => $baseProps, // Include all properties for context
        'states' => [],
        'isOrphan' => false, // Will be set to true if no direct states found
        'relatedTriggers' => [] // Will be populated for orphans
    ];
    
    // Add parsed info
    if ($type === 'transition' && isset($relevantBaseProps['transition'])) {
        $entry['parsed'] = parseTransitionShorthand($relevantBaseProps['transition']);
        $entry['hasTransition'] = true;
    } elseif ($type === 'animation') {
        if (isset($relevantBaseProps['animation'])) {
            $entry['parsed'] = parseAnimationShorthand($relevantBaseProps['animation']);
            $entry['animationName'] = $entry['parsed'][0]['name'] ?? null;
        } elseif (isset($relevantBaseProps['animation-name'])) {
            $entry['animationName'] = $relevantBaseProps['animation-name'];
        }
        $entry['hasAnimation'] = true;
    }
    
    // Add states (pseudo-class variants)
    foreach ($states as $pseudo => $stateData) {
        $stateEntry = [
            'pseudo' => $stateData['pseudo'],
            'selector' => $stateData['selector'],
            'properties' => $stateData['properties'] // All properties in the state
        ];
        
        // Check if this state also has transition/animation
        $stateRelevantProps = extractRelevantProperties($stateData['properties'], $type);
        if (!empty($stateRelevantProps)) {
            $stateEntry['hasOwn' . ucfirst($type)] = true;
            $stateEntry[$type . 'Properties'] = $stateRelevantProps;
        }
        
        $entry['states'][] = $stateEntry;
    }
    
    // Mark as orphan if no direct states
    if (empty($entry['states'])) {
        $entry['isOrphan'] = true;
    }
    
    // Sort states by pseudo-class for consistent ordering
    usort($entry['states'], function($a, $b) {
        $order = [':hover' => 1, ':focus' => 2, ':focus-within' => 3, ':active' => 4, ':visited' => 5, ':checked' => 6, ':disabled' => 7];
        $orderA = $order[$a['pseudo']] ?? 99;
        $orderB = $order[$b['pseudo']] ?? 99;
        return $orderA - $orderB;
    });
    
    return $entry;
}

/**
 * Build entry for triggers without transition (pure state changes)
 */
function buildTriggerOnlyEntry(string $baseSelector, array $data): array {
    $baseProps = $data['base']['properties'] ?? [];
    $states = $data['states'];
    
    $entry = [
        'baseSelector' => $baseSelector,
        'selector' => $data['base']['selector'] ?? $baseSelector,
        'baseProperties' => $baseProps,
        'states' => []
    ];
    
    foreach ($states as $pseudo => $stateData) {
        $entry['states'][] = [
            'pseudo' => $stateData['pseudo'],
            'selector' => $stateData['selector'],
            'properties' => $stateData['properties']
        ];
    }
    
    // Sort states
    usort($entry['states'], function($a, $b) {
        $order = [':hover' => 1, ':focus' => 2, ':focus-within' => 3, ':active' => 4, ':visited' => 5, ':checked' => 6, ':disabled' => 7];
        $orderA = $order[$a['pseudo']] ?? 99;
        $orderB = $order[$b['pseudo']] ?? 99;
        return $orderA - $orderB;
    });
    
    return $entry;
}

/**
 * Find related triggers for orphan transitions
 * An orphan like .btn (with transition) might be triggered via .btn-primary:hover
 */
function findRelatedTriggersForOrphans(array $transitions, array $triggersWithoutTransition, array $selectorMap): array {
    foreach ($transitions as &$entry) {
        if (!$entry['isOrphan']) continue;
        
        $baseSelector = $entry['baseSelector'];
        $related = [];
        
        // 1. Look in triggersWithoutTransition for selectors that start with this base
        //    e.g., .btn -> find .btn-primary:hover, .btn-submit:hover
        foreach ($triggersWithoutTransition as $trigger) {
            $triggerBase = $trigger['baseSelector'];
            
            // Check if trigger's base starts with our orphan's base
            // .btn-primary starts with .btn (class inheritance pattern)
            if ($triggerBase !== $baseSelector && strpos($triggerBase, $baseSelector) === 0) {
                foreach ($trigger['states'] as $state) {
                    $related[] = [
                        'selector' => $state['selector'],
                        'pseudo' => $state['pseudo'],
                        'reason' => 'subclass' // .btn-primary inherits from .btn
                    ];
                }
            }
        }
        
        // 2. Also look in selectorMap for any pseudo-state selectors that might use this class
        //    e.g., descendants: ".container .btn:hover" for orphan ".btn"
        foreach ($selectorMap as $otherBase => $otherData) {
            // Skip if it's the same base
            if ($otherBase === $baseSelector) continue;
            
            // Check if our base selector appears in the other selector
            // e.g., ".form .btn" contains ".btn"
            if (strpos($otherBase, $baseSelector) !== false && $otherBase !== $baseSelector) {
                foreach ($otherData['states'] as $pseudo => $stateData) {
                    $related[] = [
                        'selector' => $stateData['selector'],
                        'pseudo' => $stateData['pseudo'],
                        'reason' => 'descendant' // .container .btn:hover
                    ];
                }
            }
        }
        
        // Deduplicate by selector
        $seen = [];
        $unique = [];
        foreach ($related as $r) {
            if (!in_array($r['selector'], $seen)) {
                $seen[] = $r['selector'];
                $unique[] = $r;
            }
        }
        
        $entry['relatedTriggers'] = $unique;
    }
    
    return $transitions;
}

/**
 * Parse CSS transition shorthand into components
 * Example: "all 0.3s ease-out" => { property: "all", duration: "0.3s", timing: "ease-out" }
 */
function parseTransitionShorthand(string $value): array {
    $result = [];
    
    // Split multiple transitions (comma-separated)
    $parts = array_map('trim', explode(',', $value));
    
    foreach ($parts as $part) {
        $tokens = preg_split('/\s+/', trim($part));
        $parsed = [
            'property' => 'all',
            'duration' => '0s',
            'timing' => 'ease',
            'delay' => '0s'
        ];
        
        foreach ($tokens as $token) {
            // Duration/delay (ends with s or ms)
            if (preg_match('/^[\d.]+m?s$/', $token)) {
                if ($parsed['duration'] === '0s') {
                    $parsed['duration'] = $token;
                } else {
                    $parsed['delay'] = $token;
                }
            }
            // Timing function keyword
            elseif (in_array($token, ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end'])) {
                $parsed['timing'] = $token;
            }
            // Cubic-bezier
            elseif (strpos($token, 'cubic-bezier') === 0) {
                $parsed['timing'] = $token;
            }
            // Steps
            elseif (strpos($token, 'steps') === 0) {
                $parsed['timing'] = $token;
            }
            // Property name
            else {
                $parsed['property'] = $token;
            }
        }
        
        $result[] = $parsed;
    }
    
    return $result;
}

/**
 * Parse CSS animation shorthand into components
 * Example: "fadeIn 0.5s ease-out forwards" => { name: "fadeIn", duration: "0.5s", ... }
 */
function parseAnimationShorthand(string $value): array {
    $result = [];
    
    // Split multiple animations (comma-separated)
    $parts = array_map('trim', explode(',', $value));
    
    foreach ($parts as $part) {
        $tokens = preg_split('/\s+/', trim($part));
        $parsed = [
            'name' => null,
            'duration' => '0s',
            'timing' => 'ease',
            'delay' => '0s',
            'iterationCount' => '1',
            'direction' => 'normal',
            'fillMode' => 'none',
            'playState' => 'running'
        ];
        
        $durationSet = false;
        
        foreach ($tokens as $token) {
            // Duration/delay (ends with s or ms)
            if (preg_match('/^[\d.]+m?s$/', $token)) {
                if (!$durationSet) {
                    $parsed['duration'] = $token;
                    $durationSet = true;
                } else {
                    $parsed['delay'] = $token;
                }
            }
            // Timing function keyword
            elseif (in_array($token, ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end'])) {
                $parsed['timing'] = $token;
            }
            // Cubic-bezier or steps
            elseif (strpos($token, 'cubic-bezier') === 0 || strpos($token, 'steps') === 0) {
                $parsed['timing'] = $token;
            }
            // Iteration count
            elseif ($token === 'infinite' || preg_match('/^\d+$/', $token)) {
                $parsed['iterationCount'] = $token;
            }
            // Direction
            elseif (in_array($token, ['normal', 'reverse', 'alternate', 'alternate-reverse'])) {
                $parsed['direction'] = $token;
            }
            // Fill mode
            elseif (in_array($token, ['none', 'forwards', 'backwards', 'both'])) {
                $parsed['fillMode'] = $token;
            }
            // Play state
            elseif (in_array($token, ['running', 'paused'])) {
                $parsed['playState'] = $token;
            }
            // Animation name (anything else that's a valid identifier)
            elseif ($parsed['name'] === null && preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]*$/', $token)) {
                $parsed['name'] = $token;
            }
        }
        
        $result[] = $parsed;
    }
    
    return $result;
}

// Direct execution via HTTP
if (!defined('COMMAND_INTERNAL_CALL')) {
    require_once SECURE_FOLDER_PATH . '/src/classes/TrimParametersManagement.php';
    $trimParams = new TrimParametersManagement();
    __command_getAnimatedSelectors($trimParams->params(), $trimParams->additionalParams())->send();
}
