<?php
/**
 * RadioGroupBuilder
 *
 * Emits a radio-group field — a <fieldset> wrapping a <legend> + N
 * (label + radio input) pairs + a single error span sharing the
 * group's name:
 *
 *   <fieldset class="field">                    (extra "field--inline" when layout=inline)
 *     <legend><textKey/></legend>
 *     <label><input type="radio" name="<name>" value="<v1>" [checked]> <textKey/></label>
 *     <label><input type="radio" name="<name>" value="<v2>"> <textKey/></label>
 *     ...
 *     <span data-error-for="<name>"></span>
 *   </fieldset>
 *
 * The fieldset/legend pair is the semantically-correct wrapper for a
 * radio group; the `<span data-error-for>` matches the contract every
 * other complex element honours (FieldRow, Select) so a radio drops into
 * a Form Scaffold and picks up the QS.validate per-field error hook for
 * free.
 *
 * Config:
 *   - name          string, required  — HTML name shared by every input
 *                                       (also the data-error-for key).
 *   - legendKey     string, required  — textKey for the <legend>.
 *   - layout        string, optional  — 'inline' | 'stacked'.
 *                                       Default 'stacked'. Adds
 *                                       'field--inline' class on the
 *                                       fieldset when 'inline'.
 *   - defaultValue  string, optional  — value to mark `checked`. Must
 *                                       match one of the options'
 *                                       `value` if set.
 *   - options       array,  required  — ≥ 1, each { value, labelKey }.
 *
 * Skipped for MVP:
 *   - `required` on a radio group (the HTML semantics are awkward
 *     because every individual radio carries `required`; revisit if
 *     users ask).
 *   - per-option `disabled` flag.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class RadioGroupBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'radio-group';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- required + validation ------------------------------------
        self::requireField($config, 'name');
        self::requireField($config, 'legendKey');

        $name = (string)$config['name'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            throw new ComplexElementBuilderException(
                "Invalid radio group name '$name'. Use letters, digits, hyphens, underscores; must start with a letter."
            );
        }

        $legendKey = trim((string)$config['legendKey']);
        if ($legendKey === '') {
            throw new ComplexElementBuilderException("legendKey cannot be empty");
        }

        $layout = isset($config['layout']) ? (string)$config['layout'] : 'stacked';
        if ($layout !== 'inline' && $layout !== 'stacked') {
            throw new ComplexElementBuilderException(
                "Invalid layout '$layout'. Must be 'inline' or 'stacked'."
            );
        }

        $options = $config['options'] ?? [];
        if (!is_array($options) || count($options) === 0) {
            throw new ComplexElementBuilderException("Radio group must have at least one option");
        }

        // Validate each option + detect duplicate values (would silently
        // collide on submit — the user couldn't distinguish picks).
        $seenValues = [];
        foreach ($options as $idx => $opt) {
            if (!is_array($opt)) {
                throw new ComplexElementBuilderException("Option at index $idx must be an object");
            }
            if (!array_key_exists('value', $opt)) {
                throw new ComplexElementBuilderException("Option at index $idx is missing 'value'");
            }
            $value = (string)$opt['value'];
            if (in_array($value, $seenValues, true)) {
                throw new ComplexElementBuilderException("Duplicate option value '$value' at index $idx");
            }
            $seenValues[] = $value;
            $optLabel = isset($opt['labelKey']) ? trim((string)$opt['labelKey']) : '';
            if ($optLabel === '') {
                throw new ComplexElementBuilderException("Option at index $idx is missing 'labelKey'");
            }
        }

        $defaultValue = isset($config['defaultValue']) && $config['defaultValue'] !== ''
            ? (string)$config['defaultValue']
            : null;
        if ($defaultValue !== null && !in_array($defaultValue, $seenValues, true)) {
            throw new ComplexElementBuilderException(
                "defaultValue '$defaultValue' is not one of the option values"
            );
        }

        // ---- build children -------------------------------------------
        $children = [
            [
                'tag' => 'legend',
                'children' => [['textKey' => $legendKey]]
            ]
        ];

        foreach ($options as $opt) {
            $value = (string)$opt['value'];
            $inputParams = [
                'type'  => 'radio',
                'name'  => $name,
                'value' => $value,
            ];
            if ($defaultValue !== null && $defaultValue === $value) {
                $inputParams['checked'] = true;
            }
            $children[] = [
                'tag' => 'label',
                'children' => [
                    [
                        'tag'    => 'input',
                        'params' => $inputParams,
                    ],
                    ['textKey' => (string)$opt['labelKey']]
                ]
            ];
        }

        // Error span at the end — matches FieldRow/Select shape.
        $children[] = [
            'tag'    => 'span',
            'params' => ['data-error-for' => $name]
        ];

        // Outer fieldset. 'field' marker mirrors FieldRow/Select for
        // Form Scaffold consistency; 'field--inline' is an optional
        // hook for compact horizontal layouts.
        $fieldsetClass = $layout === 'inline' ? 'field field--inline' : 'field';

        return [
            'tag'      => 'fieldset',
            'params'   => ['class' => $fieldsetClass],
            'children' => $children
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['legendKey']) && $config['legendKey'] !== '') {
            $keys[] = (string)$config['legendKey'];
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
