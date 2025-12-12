<?php
/**
 * CSS Parser Utility Class
 * Parses and manipulates CSS content for Quicksite API
 */

class CssParser {
    
    private string $content;
    
    public function __construct(string $content) {
        $this->content = $content;
    }
    
    /**
     * Get the current CSS content
     */
    public function getContent(): string {
        return $this->content;
    }
    
    /**
     * Extract all :root variables
     * @return array Associative array of variable name => value
     */
    public function getRootVariables(): array {
        $variables = [];
        
        // Match :root block
        if (preg_match('/:root\s*\{([^}]+)\}/s', $this->content, $matches)) {
            $rootContent = $matches[1];
            
            // Match all --variable: value pairs
            preg_match_all('/(--.+?)\s*:\s*([^;]+);/s', $rootContent, $varMatches, PREG_SET_ORDER);
            
            foreach ($varMatches as $match) {
                $varName = trim($match[1]);
                $varValue = trim($match[2]);
                $variables[$varName] = $varValue;
            }
        }
        
        return $variables;
    }
    
    /**
     * Set/update :root variables
     * @param array $variables Associative array of variable name => value
     * @return array Summary of changes
     */
    public function setRootVariables(array $variables): array {
        $added = [];
        $updated = [];
        
        // Find existing :root block
        if (preg_match('/:root\s*\{([^}]+)\}/s', $this->content, $matches, PREG_OFFSET_CAPTURE)) {
            $rootContent = $matches[1][0];
            $rootStart = $matches[0][1];
            $rootEnd = $rootStart + strlen($matches[0][0]);
            
            $newRootContent = $rootContent;
            
            foreach ($variables as $varName => $varValue) {
                // Ensure variable name starts with --
                if (strpos($varName, '--') !== 0) {
                    $varName = '--' . $varName;
                }
                
                // Check if variable exists
                $pattern = '/(' . preg_quote($varName, '/') . '\s*:\s*)([^;]+)(;)/s';
                
                if (preg_match($pattern, $newRootContent)) {
                    // Update existing - escape $ in replacement to prevent backreference issues
                    $safeValue = str_replace(['\\', '$'], ['\\\\', '\\$'], $varValue);
                    $newRootContent = preg_replace($pattern, '${1}' . $safeValue . '${3}', $newRootContent);
                    $updated[$varName] = $varValue;
                } else {
                    // Add new variable before closing brace
                    $newRootContent = rtrim($newRootContent) . "\n    " . $varName . ': ' . $varValue . ";\n";
                    $added[$varName] = $varValue;
                }
            }
            
            // Replace :root block
            $newRootBlock = ':root {' . $newRootContent . '}';
            $this->content = substr($this->content, 0, $rootStart) . $newRootBlock . substr($this->content, $rootEnd);
            
        } else {
            // No :root block exists, create one at the beginning
            $rootBlock = ":root {\n";
            foreach ($variables as $varName => $varValue) {
                if (strpos($varName, '--') !== 0) {
                    $varName = '--' . $varName;
                }
                $rootBlock .= "    " . $varName . ': ' . $varValue . ";\n";
                $added[$varName] = $varValue;
            }
            $rootBlock .= "}\n\n";
            
            $this->content = $rootBlock . $this->content;
        }
        
        return [
            'added' => $added,
            'updated' => $updated,
            'total_changes' => count($added) + count($updated)
        ];
    }
    
    /**
     * Get all selectors in the stylesheet
     * @return array List of selectors with their context (global or media query)
     */
    public function listSelectors(): array {
        $selectors = [];
        
        // First, extract selectors outside of @media and @keyframes
        $contentWithoutMedia = $this->content;
        
        // Remove @keyframes blocks temporarily
        $contentWithoutMedia = preg_replace('/@keyframes\s+[\w-]+\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', $contentWithoutMedia);
        
        // Extract @media blocks and their selectors
        preg_match_all('/@media\s*([^{]+)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s', $contentWithoutMedia, $mediaMatches, PREG_SET_ORDER);
        
        foreach ($mediaMatches as $media) {
            $mediaQuery = trim($media[1]);
            $mediaContent = $media[2];
            
            // Extract selectors within this media query
            preg_match_all('/([^{@][^{]*)\s*\{[^}]*\}/s', $mediaContent, $selectorMatches);
            
            foreach ($selectorMatches[1] as $selector) {
                $selector = trim($selector);
                if (!empty($selector) && strpos($selector, '/*') === false) {
                    $selectors[] = [
                        'selector' => $selector,
                        'mediaQuery' => $mediaQuery
                    ];
                }
            }
        }
        
        // Remove @media blocks from content to find global selectors
        $globalContent = preg_replace('/@media\s*[^{]+\s*\{(?:[^{}]*\{[^{}]*\})*\s*\}/s', '', $contentWithoutMedia);
        
        // Extract global selectors (not in @media)
        preg_match_all('/([^{@][^{]*)\s*\{[^}]*\}/s', $globalContent, $globalMatches);
        
        foreach ($globalMatches[1] as $selector) {
            $selector = trim($selector);
            if (!empty($selector) && strpos($selector, '/*') === false && strpos($selector, ':root') === false) {
                $selectors[] = [
                    'selector' => $selector,
                    'mediaQuery' => null
                ];
            }
        }
        
        return $selectors;
    }
    
    /**
     * Get styles for a specific selector
     * @param string $selector CSS selector
     * @param string|null $mediaQuery Optional media query context
     * @return array|null Styles or null if not found
     */
    public function getStyleRule(string $selector, ?string $mediaQuery = null): ?array {
        $escapedSelector = preg_quote($selector, '/');
        
        if ($mediaQuery !== null) {
            // Look within specific media query
            $escapedMedia = preg_quote($mediaQuery, '/');
            $pattern = '/@media\s*' . $escapedMedia . '\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s';
            
            if (preg_match($pattern, $this->content, $mediaMatch)) {
                $mediaContent = $mediaMatch[1];
                
                if (preg_match('/' . $escapedSelector . '\s*\{([^}]*)\}/s', $mediaContent, $match)) {
                    return [
                        'selector' => $selector,
                        'styles' => trim($match[1]),
                        'mediaQuery' => $mediaQuery
                    ];
                }
            }
            return null;
        }
        
        // Look in global scope (outside @media blocks)
        // First remove @media blocks
        $globalContent = preg_replace('/@media\s*[^{]+\s*\{(?:[^{}]*\{[^{}]*\})*\s*\}/s', '', $this->content);
        
        if (preg_match('/' . $escapedSelector . '\s*\{([^}]*)\}/s', $globalContent, $match)) {
            return [
                'selector' => $selector,
                'styles' => trim($match[1]),
                'mediaQuery' => null
            ];
        }
        
        return null;
    }
    
    /**
     * Set/update a style rule
     * @param string $selector CSS selector
     * @param string $styles CSS declarations
     * @param string|null $mediaQuery Optional media query context
     * @return array Operation result
     */
    public function setStyleRule(string $selector, string $styles, ?string $mediaQuery = null): array {
        $escapedSelector = preg_quote($selector, '/');
        $formattedStyles = $this->formatStyles($styles);
        $action = 'added';
        
        if ($mediaQuery !== null) {
            // Handle media query context
            $escapedMedia = preg_quote($mediaQuery, '/');
            $mediaPattern = '/@media\s*' . $escapedMedia . '\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s';
            
            if (preg_match($mediaPattern, $this->content, $mediaMatch, PREG_OFFSET_CAPTURE)) {
                $mediaContent = $mediaMatch[1][0];
                $mediaStart = $mediaMatch[0][1];
                $mediaFull = $mediaMatch[0][0];
                
                // Check if selector exists in this media query
                $selectorPattern = '/(' . $escapedSelector . '\s*\{)[^}]*(\})/s';
                
                if (preg_match($selectorPattern, $mediaContent, $match)) {
                    // Update existing rule - escape $ to prevent backreference issues
                    $safeStyles = str_replace(['\\', '$'], ['\\\\', '\\$'], $formattedStyles);
                    $newMediaContent = preg_replace($selectorPattern, '${1}' . "\n" . $safeStyles . "\n" . '${2}', $mediaContent);
                    $action = 'updated';
                } else {
                    // Add new rule to media query
                    $newMediaContent = rtrim($mediaContent) . "\n    " . $selector . " {\n" . $formattedStyles . "\n    }\n";
                }
                
                $newMediaBlock = '@media ' . $mediaQuery . ' {' . $newMediaContent . '}';
                $this->content = substr($this->content, 0, $mediaStart) . $newMediaBlock . substr($this->content, $mediaStart + strlen($mediaFull));
                
            } else {
                // Media query doesn't exist, create it
                $newMediaBlock = "\n\n@media " . $mediaQuery . " {\n    " . $selector . " {\n" . $formattedStyles . "\n    }\n}";
                $this->content = $this->appendToCustomSection($newMediaBlock);
            }
            
        } else {
            // Global scope
            $selectorPattern = '/(' . $escapedSelector . '\s*\{)[^}]*(\})/s';
            
            // Check if it exists globally (not in @media)
            $existing = $this->getStyleRule($selector, null);
            
            if ($existing !== null) {
                // Update existing - need to be careful not to match inside @media
                // This is complex, so we'll use a different approach
                $action = 'updated';
                
                // Find and replace the rule outside of @media blocks
                $this->content = $this->replaceGlobalRule($selector, $formattedStyles);
            } else {
                // Add new rule
                $newRule = "\n" . $selector . " {\n" . $formattedStyles . "\n}";
                $this->content = $this->appendToCustomSection($newRule);
            }
        }
        
        return [
            'action' => $action,
            'selector' => $selector,
            'mediaQuery' => $mediaQuery
        ];
    }
    
    /**
     * Delete a style rule
     * @param string $selector CSS selector
     * @param string|null $mediaQuery Optional media query context
     * @return bool True if deleted, false if not found
     */
    public function deleteStyleRule(string $selector, ?string $mediaQuery = null): bool {
        $escapedSelector = preg_quote($selector, '/');
        
        if ($mediaQuery !== null) {
            // Delete from specific media query
            $escapedMedia = preg_quote($mediaQuery, '/');
            $mediaPattern = '/@media\s*' . $escapedMedia . '\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s';
            
            if (preg_match($mediaPattern, $this->content, $mediaMatch, PREG_OFFSET_CAPTURE)) {
                $mediaContent = $mediaMatch[1][0];
                $mediaStart = $mediaMatch[0][1];
                $mediaFull = $mediaMatch[0][0];
                
                $selectorPattern = '/\s*' . $escapedSelector . '\s*\{[^}]*\}\s*/s';
                
                if (preg_match($selectorPattern, $mediaContent)) {
                    $newMediaContent = preg_replace($selectorPattern, "\n", $mediaContent);
                    
                    // Check if media query is now empty
                    if (trim(preg_replace('/\s+/', '', $newMediaContent)) === '') {
                        // Remove entire media block
                        $this->content = substr($this->content, 0, $mediaStart) . substr($this->content, $mediaStart + strlen($mediaFull));
                    } else {
                        $newMediaBlock = '@media ' . $mediaQuery . ' {' . $newMediaContent . '}';
                        $this->content = substr($this->content, 0, $mediaStart) . $newMediaBlock . substr($this->content, $mediaStart + strlen($mediaFull));
                    }
                    return true;
                }
            }
            return false;
        }
        
        // Delete from global scope
        $existing = $this->getStyleRule($selector, null);
        if ($existing === null) {
            return false;
        }
        
        // Remove the rule (being careful not to remove from @media blocks)
        $pattern = '/\n*' . $escapedSelector . '\s*\{[^}]*\}\s*/s';
        
        // We need to ensure we're not matching inside @media
        // Split content by @media blocks, process global parts, then reassemble
        $this->content = $this->removeGlobalRule($selector);
        
        return true;
    }
    
    /**
     * Get all @keyframes animations
     * @return array List of keyframe names and their content
     */
    public function getKeyframes(): array {
        $keyframes = [];
        
        preg_match_all('/@keyframes\s+([\w-]+)\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $this->content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $name = trim($match[1]);
            $framesContent = trim($match[2]);
            
            // Parse individual frames
            $frames = [];
            preg_match_all('/([\d%,\s]+|from|to)\s*\{([^}]*)\}/s', $framesContent, $frameMatches, PREG_SET_ORDER);
            
            foreach ($frameMatches as $frame) {
                $key = trim($frame[1]);
                $value = trim($frame[2]);
                $frames[$key] = $value;
            }
            
            $keyframes[$name] = $frames;
        }
        
        return $keyframes;
    }
    
    /**
     * Set/update a @keyframes animation
     * @param string $name Animation name
     * @param array $frames Associative array of frame => styles
     * @return array Operation result
     */
    public function setKeyframes(string $name, array $frames): array {
        $escapedName = preg_quote($name, '/');
        $action = 'added';
        
        // Build the keyframes content
        $framesContent = '';
        foreach ($frames as $key => $styles) {
            $formattedStyles = $this->formatStyles($styles, '        ');
            $framesContent .= "    " . $key . " {\n" . $formattedStyles . "\n    }\n";
        }
        
        $newKeyframes = "@keyframes " . $name . " {\n" . $framesContent . "}";
        
        // Check if keyframes already exists
        $pattern = '/@keyframes\s+' . $escapedName . '\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s';
        
        if (preg_match($pattern, $this->content)) {
            // Update existing
            $this->content = preg_replace($pattern, $newKeyframes, $this->content);
            $action = 'updated';
        } else {
            // Add new
            $this->content = $this->appendToCustomSection("\n" . $newKeyframes);
        }
        
        return [
            'action' => $action,
            'name' => $name,
            'frames' => array_keys($frames)
        ];
    }
    
    /**
     * Delete a @keyframes animation
     * @param string $name Animation name
     * @return bool True if deleted, false if not found
     */
    public function deleteKeyframes(string $name): bool {
        $escapedName = preg_quote($name, '/');
        $pattern = '/\s*@keyframes\s+' . $escapedName . '\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}\s*/s';
        
        if (preg_match($pattern, $this->content)) {
            $this->content = preg_replace($pattern, "\n", $this->content);
            return true;
        }
        
        return false;
    }
    
    /**
     * Format CSS styles with proper indentation
     */
    private function formatStyles(string $styles, string $indent = '    '): string {
        // Split by semicolons and format each declaration
        $declarations = array_filter(array_map('trim', explode(';', $styles)));
        $formatted = [];
        
        foreach ($declarations as $declaration) {
            if (!empty($declaration)) {
                $formatted[] = $indent . trim($declaration) . ';';
            }
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * Append content to the custom section at the end of the file
     */
    private function appendToCustomSection(string $content): string {
        // Look for custom section marker
        $marker = '/* CUSTOM OVERRIDES */';
        $markerAlt = '/* =============================================================================
   CUSTOM OVERRIDES (API-managed)';
        
        if (strpos($this->content, $markerAlt) !== false) {
            // Append before the final closing
            return rtrim($this->content) . "\n" . $content . "\n";
        } elseif (strpos($this->content, $marker) !== false) {
            return rtrim($this->content) . "\n" . $content . "\n";
        } else {
            // No marker, just append
            return rtrim($this->content) . "\n" . $content . "\n";
        }
    }
    
    /**
     * Replace a rule in global scope (not inside @media)
     */
    private function replaceGlobalRule(string $selector, string $formattedStyles): string {
        $escapedSelector = preg_quote($selector, '/');
        $result = '';
        $pos = 0;
        $len = strlen($this->content);
        
        while ($pos < $len) {
            // Check for @media
            if (preg_match('/@media\s*[^{]+\s*\{/s', $this->content, $match, PREG_OFFSET_CAPTURE, $pos)) {
                $mediaStart = $match[0][1];
                
                // Add content before @media
                $beforeMedia = substr($this->content, $pos, $mediaStart - $pos);
                
                // Replace in this section - escape $ to prevent backreference issues
                $pattern = '/(' . $escapedSelector . '\s*\{)[^}]*(\})/s';
                $safeStyles = str_replace(['\\', '$'], ['\\\\', '\\$'], $formattedStyles);
                $replacement = '${1}' . "\n" . $safeStyles . "\n" . '${2}';
                $beforeMedia = preg_replace($pattern, $replacement, $beforeMedia, 1);
                
                $result .= $beforeMedia;
                
                // Find end of @media block
                $braceCount = 0;
                $i = $mediaStart + strlen($match[0][0]);
                $braceCount = 1;
                
                while ($i < $len && $braceCount > 0) {
                    if ($this->content[$i] === '{') $braceCount++;
                    if ($this->content[$i] === '}') $braceCount--;
                    $i++;
                }
                
                // Add @media block unchanged
                $result .= substr($this->content, $mediaStart, $i - $mediaStart);
                $pos = $i;
            } else {
                // No more @media blocks - escape $ to prevent backreference issues
                $remaining = substr($this->content, $pos);
                $pattern = '/(' . $escapedSelector . '\s*\{)[^}]*(\})/s';
                $safeStyles = str_replace(['\\', '$'], ['\\\\', '\\$'], $formattedStyles);
                $replacement = '${1}' . "\n" . $safeStyles . "\n" . '${2}';
                $result .= preg_replace($pattern, $replacement, $remaining, 1);
                break;
            }
        }
        
        return $result;
    }
    
    /**
     * Remove a rule from global scope (not inside @media)
     */
    private function removeGlobalRule(string $selector): string {
        $escapedSelector = preg_quote($selector, '/');
        $result = '';
        $pos = 0;
        $len = strlen($this->content);
        
        while ($pos < $len) {
            // Check for @media
            if (preg_match('/@media\s*[^{]+\s*\{/s', $this->content, $match, PREG_OFFSET_CAPTURE, $pos)) {
                $mediaStart = $match[0][1];
                
                // Add content before @media
                $beforeMedia = substr($this->content, $pos, $mediaStart - $pos);
                
                // Remove in this section
                $pattern = '/\n*' . $escapedSelector . '\s*\{[^}]*\}\s*/s';
                $beforeMedia = preg_replace($pattern, "\n", $beforeMedia, 1);
                
                $result .= $beforeMedia;
                
                // Find end of @media block
                $braceCount = 0;
                $i = $mediaStart + strlen($match[0][0]);
                $braceCount = 1;
                
                while ($i < $len && $braceCount > 0) {
                    if ($this->content[$i] === '{') $braceCount++;
                    if ($this->content[$i] === '}') $braceCount--;
                    $i++;
                }
                
                // Add @media block unchanged
                $result .= substr($this->content, $mediaStart, $i - $mediaStart);
                $pos = $i;
            } else {
                // No more @media blocks
                $remaining = substr($this->content, $pos);
                $pattern = '/\n*' . $escapedSelector . '\s*\{[^}]*\}\s*/s';
                $result .= preg_replace($pattern, "\n", $remaining, 1);
                break;
            }
        }
        
        return $result;
    }
}
