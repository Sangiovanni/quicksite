<?php
/**
 * SelectBuilder
 *
 * Emits a wrapped form-field subtree built around <select>:
 *   <div class="field">
 *     <label for="<id>"><labelKey></label>
 *     <select name="<name>" id="<id>" [required] [multiple]>
 *       <option value="">[placeholderKey]</option>   (optional)
 *       <option value="<v1>"><labelKey1></option>
 *       <option value="<v2>"><labelKey2></option>
 *       ...
 *     </select>
 *     <span data-error-for="<name>"></span>
 *   </div>
 *
 * Same outer shape as FieldRow so a <select> can sit alongside text
 * inputs inside a Form Scaffold and pick up the same QS.validate
 * [data-error-for=<name>] hook for free.
 *
 * Config:
 *   - name           string, required  — HTML name (also the error-span key).
 *   - id             string, optional  — defaults to `name`.
 *   - labelKey       string, required  — textKey for the <label>.
 *   - required       bool,   default false
 *   - multiple       bool,   default false — multi-select.
 *   - placeholderKey string, optional  — when set, prepends an empty-value
 *                                          disabled+selected option using
 *                                          this textKey. (No effect when
 *                                          multiple is true — the browser
 *                                          ignores `selected disabled` on
 *                                          multi-selects.)
 *   - options        array of { value, labelKey }, required (≥ 1)
 *                       value:    string (literal submitted on form submit)
 *                       labelKey: string (textKey for the display label)
 *
 * Skipped for MVP (can land later without breaking changes):
 *   - optgroups (per COMPLEX_ELEMENTS.txt — the full spec includes them)
 *   - raw-or-textKey toggle on option `value` (current: value is literal)
 *   - `size` attribute
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class SelectBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'select';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- required + validation ----
        self::requireField($config, 'name');
        self::requireField($config, 'labelKey');

        $name = (string)$config['name'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            throw new ComplexElementBuilderException(
                "Invalid select name '$name'. Use letters, digits, hyphens, underscores; must start with a letter."
            );
        }

        $labelKey = trim((string)$config['labelKey']);
        if ($labelKey === '') {
            throw new ComplexElementBuilderException("labelKey cannot be empty");
        }

        $id = isset($config['id']) && $config['id'] !== '' ? (string)$config['id'] : $name;
        self::validateHtmlId($id, 'id');

        $required = !empty($config['required']);
        $multiple = !empty($config['multiple']);
        $placeholderKey = isset($config['placeholderKey']) && $config['placeholderKey'] !== ''
            ? (string)$config['placeholderKey']
            : null;

        $options = $config['options'] ?? [];
        if (!is_array($options) || count($options) === 0) {
            throw new ComplexElementBuilderException("Select must have at least one option");
        }

        // Validate each option + detect duplicate values (would silently
        // collapse on submit — the user wouldn't be able to distinguish
        // which was picked).
        $seenValues = [];
        foreach ($options as $idx => $opt) {
            if (!is_array($opt)) {
                throw new ComplexElementBuilderException("Option at index $idx must be an object");
            }
            // value can be '' (e.g. "no preference") but key must exist
            if (!array_key_exists('value', $opt)) {
                throw new ComplexElementBuilderException("Option at index $idx is missing 'value'");
            }
            $value = (string)$opt['value'];
            if (in_array($value, $seenValues, true)) {
                throw new ComplexElementBuilderException("Duplicate option value '$value' at index $idx");
            }
            $seenValues[] = $value;
            $optLabel = $opt['labelKey'] ?? '';
            if ($optLabel === '') {
                throw new ComplexElementBuilderException("Option at index $idx is missing 'labelKey'");
            }
        }

        // ---- build <option> children -----
        $optionNodes = [];

        if ($placeholderKey !== null && !$multiple) {
            $optionNodes[] = [
                'tag' => 'option',
                'params' => [
                    'value' => '',
                    'disabled' => true,
                    'selected' => true,
                ],
                'children' => [['textKey' => $placeholderKey]]
            ];
        }

        foreach ($options as $opt) {
            $optionNodes[] = [
                'tag' => 'option',
                'params' => ['value' => (string)$opt['value']],
                'children' => [['textKey' => (string)$opt['labelKey']]]
            ];
        }

        // ---- <select> -----
        $selectParams = ['name' => $name, 'id' => $id];
        if ($required) $selectParams['required'] = true;
        if ($multiple) $selectParams['multiple'] = true;

        $selectNode = [
            'tag' => 'select',
            'params' => $selectParams,
            'children' => $optionNodes
        ];

        // ---- wrapper (same shape as FieldRow for Form-Scaffold parity) -----
        return [
            'tag' => 'div',
            'params' => ['class' => 'field'],
            'children' => [
                [
                    'tag' => 'label',
                    'params' => ['for' => $id],
                    'children' => [['textKey' => $labelKey]]
                ],
                $selectNode,
                [
                    'tag' => 'span',
                    'params' => ['data-error-for' => $name]
                ]
            ]
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['labelKey']) && $config['labelKey'] !== '') {
            $keys[] = (string)$config['labelKey'];
        }
        if (isset($config['placeholderKey']) && $config['placeholderKey'] !== '') {
            $keys[] = (string)$config['placeholderKey'];
        }
        if (isset($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $opt) {
                if (is_array($opt) && isset($opt['labelKey']) && $opt['labelKey'] !== '') {
                    $keys[] = (string)$opt['labelKey'];
                }
            }
        }
        return $keys;
    }
}
