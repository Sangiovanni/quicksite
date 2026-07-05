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
     * SMIL animation elements. <set> is removed wholesale (DANGEROUS_ELEMENTS);
     * these are removed only when they animate a URL/event attribute
     * (attributeName href/xlink:href or on*) — which would inject a
     * javascript: navigation or an event handler at render time, after the
     * static attribute sweep. Legit animations (opacity, transform, colour…)
     * are kept. (beta.10 C4 / F15)
     */
    private const ANIMATION_ELEMENTS = [
        'animate',
        'animateTransform',
        'animateMotion',
        'animateColor',
    ];

    /**
     * data: URIs permitted on URI attributes: passive raster images only.
     * Every other data: variant (image/svg+xml, text/html, application/*)
     * is blocked together with javascript:/vbscript:. Lets legit inline
     * images (common in exported SVGs) through without reopening the hole.
     * (beta.10 C4 / F15)
     */
    private const SAFE_DATA_IMAGE_RE = '#^data:image/(png|jpe?g|gif|webp|bmp)[;,]#';

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

        // Block XXE (beta.10 C4 / F15). LIBXML_NONET stops NETWORK entity
        // fetches but NOT file:// ones, and LIBXML_NOENT would then
        // substitute a DOCTYPE's external SYSTEM entity into the output —
        // a local-file-disclosure. Install a null external-entity loader
        // (so no external entity can ever resolve) and load WITHOUT
        // LIBXML_NOENT (so DTD-declared entities are not expanded at all).
        // Predefined/numeric XML entities are unaffected — legit SVGs
        // round-trip unchanged.
        libxml_set_external_entity_loader(static function () { return null; });

        $dom = new DOMDocument();
        // Load as XML (SVG is XML-based)
        if (!$dom->loadXML($svgContent, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            libxml_set_external_entity_loader(null);
            return false;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        libxml_set_external_entity_loader(null);

        // Remove dangerous elements
        self::removeDangerousElements($dom);

        // Remove dangerous attributes from all remaining elements
        self::removeDangerousAttributes($dom);

        // Remove SMIL animation elements that inject a URL/event attribute
        self::removeAnimationInjectors($dom);

        // Remove <use>/<image> elements with external references
        self::removeExternalResourceRefs($dom);

        // Remove <style> elements referencing an external resource
        self::removeExternalStyleRefs($dom);

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
                        $attrsToRemove[] = $attr;
                        continue 2;
                    }
                }

                // Check URI attributes for dangerous schemes
                if (in_array($name, self::URI_ATTRIBUTES, true)) {
                    $value = strtolower(trim($attr->value));
                    // Strip whitespace/control chars that could bypass detection
                    $value = preg_replace('/[\x00-\x20]+/', '', $value);
                    // Passive raster data-URIs (inline images) are allowed;
                    // all other data: variants fall through to the block list
                    // alongside javascript:/vbscript:. (beta.10 C4 / F15)
                    if (preg_match(self::SAFE_DATA_IMAGE_RE, $value)) {
                        continue;
                    }
                    foreach (self::BLOCKED_SCHEMES as $scheme) {
                        if (str_starts_with($value, $scheme)) {
                            $attrsToRemove[] = $attr;
                            // continue the ATTRIBUTES loop (level 2), not the
                            // elements loop. The prior `continue 3` skipped
                            // past the removeAttributeNode block below, so the
                            // whole javascript:/data:/vbscript: filter was dead
                            // code — the value survived. (beta.10 C4 / F15)
                            continue 2;
                        }
                    }
                }
            }

            // Remove via the attribute NODE, not its name: a namespaced
            // attribute (e.g. xlink:href) reports $attr->name === 'href',
            // so removeAttribute('href') would NOT drop the namespaced node
            // and the dangerous value (javascript:/data:) would survive.
            // removeAttributeNode handles both plain + namespaced attrs.
            // (beta.10 C4 / F15)
            foreach ($attrsToRemove as $attr) {
                $element->removeAttributeNode($attr);
            }
        }
    }

    /**
     * Remove SMIL animation elements that animate a URL or event attribute.
     * A `<animate attributeName="href" to="javascript:…">` (or an on* target)
     * injects the dangerous value at render time, after the static attribute
     * sweep has run — so it must be dropped here. Animations of safe
     * attributes (opacity, transform, colour, …) are preserved.
     * (beta.10 C4 / F15)
     */
    private static function removeAnimationInjectors(DOMDocument $dom): void
    {
        foreach (self::ANIMATION_ELEMENTS as $tagName) {
            $nodes = [];
            $list = $dom->getElementsByTagName($tagName);
            for ($i = $list->length - 1; $i >= 0; $i--) {
                $nodes[] = $list->item($i);
            }
            foreach ($nodes as $node) {
                $target = strtolower($node->getAttribute('attributeName'));
                $isUrlAttr = in_array($target, self::URI_ATTRIBUTES, true);
                $isEventAttr = str_starts_with($target, 'on') && strlen($target) > 2;
                if ($isUrlAttr || $isEventAttr) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * Remove <use>/<image> elements whose href points at an EXTERNAL
     * resource (http(s):// or protocol-relative //). External <use> can
     * pull in remote SVG that bypasses sanitization; external <image> is an
     * SSRF-on-view / tracking beacon when the stored SVG is served. Local /
     * fragment / relative refs and inline data:image URIs are kept.
     * (beta.10 C4 / F15 — broadened from <use>-only to <use>+<image>)
     */
    private static function removeExternalResourceRefs(DOMDocument $dom): void
    {
        foreach (['use', 'image'] as $tagName) {
            $nodes = [];
            $list = $dom->getElementsByTagName($tagName);
            for ($i = $list->length - 1; $i >= 0; $i--) {
                $node = $list->item($i);
                $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                // External if it starts with http://, https://, or // (protocol-relative)
                if ($href && preg_match('#^\s*(https?://|//)#i', $href)) {
                    $nodes[] = $node;
                }
            }
            foreach ($nodes as $node) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Remove <style> elements that reference an external resource via
     * @import or url(http(s)://…) / url(//…). External CSS is a privacy /
     * exfil vector, and @import can pull in remote rules. Internal <style>
     * with local url(#frag) / relative refs is kept. (beta.10 C4 / F15)
     */
    private static function removeExternalStyleRefs(DOMDocument $dom): void
    {
        $nodes = [];
        $list = $dom->getElementsByTagName('style');
        for ($i = $list->length - 1; $i >= 0; $i--) {
            $node = $list->item($i);
            $css = $node->textContent;
            if (preg_match('#@import|url\(\s*[\'"]?\s*(https?:)?//#i', $css)) {
                $nodes[] = $node;
            }
        }
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }
}
