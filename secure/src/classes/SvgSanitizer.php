<?php

/**
 * SVG Sanitizer
 * 
 * Removes dangerous elements and attributes from SVG files to prevent XSS.
 * SVGs can contain embedded scripts, event handlers, and external references
 * that execute JavaScript when rendered in a browser.
 */
class SvgSanitizer
{
    /** Elements that can execute code or load external content */
    private const DANGEROUS_ELEMENTS = [
        'script',
        'foreignObject',
        'handler',     // SVG animation event handler
        'set',         // Can modify attributes to inject scripts
    ];

    /** Attributes that can execute JavaScript */
    private const DANGEROUS_ATTR_PREFIXES = [
        'on',  // onclick, onload, onerror, onmouseover, etc.
    ];

    /** URI attributes that could contain javascript:/data: payloads */
    private const URI_ATTRIBUTES = [
        'href',
        'xlink:href',
        'src',
        'action',
        'formaction',
    ];

    /** URI schemes that must be blocked */
    private const BLOCKED_SCHEMES = [
        'javascript:',
        'data:',
        'vbscript:',
    ];

    /**
     * Sanitize an SVG file in place.
     * 
     * @param string $filePath Absolute path to the SVG file
     * @return bool True if sanitization succeeded
     */
    public static function sanitizeFile(string $filePath): bool
    {
        $svgContent = file_get_contents($filePath);
        if ($svgContent === false) {
            return false;
        }

        $sanitized = self::sanitize($svgContent);
        if ($sanitized === false) {
            return false;
        }

        return file_put_contents($filePath, $sanitized) !== false;
    }

    /**
     * Sanitize SVG content string.
     * 
     * @param string $svgContent Raw SVG markup
     * @return string|false Sanitized SVG or false on parse failure
     */
    public static function sanitize(string $svgContent): string|false
    {
        // Suppress DOMDocument warnings for malformed markup
        $prev = libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        // Load as XML (SVG is XML-based)
        if (!$dom->loadXML($svgContent, LIBXML_NONET | LIBXML_NOENT)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Remove dangerous elements
        self::removeDangerousElements($dom);

        // Remove dangerous attributes from all remaining elements
        self::removeDangerousAttributes($dom);

        // Remove <use> elements with external references
        self::removeExternalUseRefs($dom);

        // Serialize back to XML string
        $result = $dom->saveXML($dom->documentElement);
        if ($result === false) {
            return false;
        }

        // Prepend XML declaration for well-formed SVG
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $result;
    }

    /**
     * Remove all dangerous elements and their children.
     */
    private static function removeDangerousElements(DOMDocument $dom): void
    {
        foreach (self::DANGEROUS_ELEMENTS as $tagName) {
            // Collect nodes first (modifying during iteration breaks it)
            $nodes = [];
            $list = $dom->getElementsByTagName($tagName);
            for ($i = $list->length - 1; $i >= 0; $i--) {
                $nodes[] = $list->item($i);
            }
            foreach ($nodes as $node) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Remove event handler attributes and dangerous URI values.
     */
    private static function removeDangerousAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $allElements = $xpath->query('//*');

        foreach ($allElements as $element) {
            $attrsToRemove = [];

            foreach ($element->attributes as $attr) {
                $name = strtolower($attr->name);

                // Remove event handler attributes (on*)
                foreach (self::DANGEROUS_ATTR_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                        $attrsToRemove[] = $attr->name;
                        continue 2;
                    }
                }

                // Check URI attributes for dangerous schemes
                if (in_array($name, self::URI_ATTRIBUTES, true)) {
                    $value = strtolower(trim($attr->value));
                    // Strip whitespace/control chars that could bypass detection
                    $value = preg_replace('/[\x00-\x20]+/', '', $value);
                    foreach (self::BLOCKED_SCHEMES as $scheme) {
                        if (str_starts_with($value, $scheme)) {
                            $attrsToRemove[] = $attr->name;
                            continue 3;
                        }
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                $element->removeAttribute($attrName);
            }
        }
    }

    /**
     * Remove <use> elements that reference external resources.
     * External <use> can load SVG from other domains, bypassing sanitization.
     */
    private static function removeExternalUseRefs(DOMDocument $dom): void
    {
        $nodes = [];
        $list = $dom->getElementsByTagName('use');
        for ($i = $list->length - 1; $i >= 0; $i--) {
            $node = $list->item($i);
            $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            // External if it starts with http://, https://, or // (protocol-relative)
            if ($href && preg_match('#^(https?://|//)#i', $href)) {
                $nodes[] = $node;
            }
        }
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }
}
