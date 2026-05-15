<?php
/**
 * ComplexElementBuilder
 *
 * Abstract base for "complex element" wizards (form scaffold, select,
 * table, etc.). Each builder is pure: config in, node spec out. No I/O.
 *
 * The single addComplexElement command driver loads the right builder
 * based on `kind`, calls build($config), validates the result, and
 * splices it into the page/component JSON under one file lock.
 *
 * After save, the emitted subtree is INDISTINGUISHABLE from a manually
 * built one — same JSON shape, same renderer, editable with the regular
 * visual-editor tools. No runtime indirection.
 *
 * Builders MUST:
 *  - Strip any `_wizard_*` transient form-state from the output.
 *  - Return a single root node (tag or component) with arbitrary
 *    nested `children`. Multi-root subtrees aren't supported.
 *  - Throw `ComplexElementBuilderException` on bad config rather than
 *    return a half-built node. The command catches and surfaces.
 *
 * @see secure/management/command/addComplexElement.php
 */

class ComplexElementBuilderException extends \RuntimeException {}

abstract class ComplexElementBuilder {
    /**
     * Stable kind identifier dispatched by the command.
     * Must match /^[a-z][a-z0-9-]*$/ (lowercase, hyphens).
     */
    abstract public function kind(): string;

    /**
     * Produce the node spec from validated config.
     * Should NEVER touch the filesystem or other I/O.
     *
     * @param array $config Wizard-supplied configuration.
     * @return array A single node spec (with optional nested children).
     * @throws ComplexElementBuilderException on invalid config.
     */
    abstract public function build(array $config): array;

    /**
     * Optional: declare which textKeys this builder will reference, so
     * the command can pre-allocate them in the translation files. Each
     * entry is a string key path; the command creates empty entries
     * for every active language (idempotent — won't overwrite existing).
     *
     * Default: no textKeys. Builders that emit translatable strings
     * (Field Row label, Form Scaffold submit label, etc.) override.
     *
     * @param array $config Same config passed to build().
     * @return string[]
     */
    public function declaredTextKeys(array $config): array {
        return [];
    }

    // ------------------------------------------------------------------
    // Helpers exposed to subclasses — keep build() readable
    // ------------------------------------------------------------------

    /**
     * Strip any `_wizard_*` keys from an associative array (recursively).
     * Defence-in-depth: the command driver also runs this on the final
     * subtree, but builders should call it on their own config too if
     * they accept arbitrary nested wizard state.
     */
    protected static function stripWizardKeys(array $arr): array {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_string($k) && strpos($k, '_wizard_') === 0) {
                continue;
            }
            $out[$k] = is_array($v) ? self::stripWizardKeys($v) : $v;
        }
        return $out;
    }

    /**
     * Require a non-empty config field; throw with a clear message
     * (caught by the command driver and returned as a 400).
     */
    protected static function requireField(array $config, string $name): void {
        if (!isset($config[$name]) || $config[$name] === '' || $config[$name] === null) {
            throw new ComplexElementBuilderException(
                "Missing required config field: '$name'"
            );
        }
    }

    /**
     * Validate an HTML id attribute value.
     * Same rule as the visual editor uses: letter-start, alphanumerics,
     * hyphens, underscores.
     */
    protected static function validateHtmlId(string $id, string $fieldName = 'id'): void {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $id)) {
            throw new ComplexElementBuilderException(
                "Invalid HTML id for '$fieldName': '$id' (must start with a letter and contain only letters, digits, hyphens, underscores)"
            );
        }
    }
}
