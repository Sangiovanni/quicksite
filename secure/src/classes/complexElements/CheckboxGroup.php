<?php
/**
 * CheckboxGroupBuilder
 *
 * Emits a checkbox-group field — a <fieldset> wrapping a <legend> + N
 * (label + checkbox input) pairs + a single error span sharing the
 * group's name (without any `[]` suffix):
 *
 *   <fieldset class="field">                    (extra "field--inline" when layout=inline)
 *     <legend><textKey/></legend>
 *     <label><input type="checkbox" name="<name>" value="<v1>" [checked]> <textKey/></label>
 *     <label><input type="checkbox" name="<name>" value="<v2>" [checked]> <textKey/></label>
 *     ...
 *     <span data-error-for="<name>"></span>
 *   </fieldset>
 *
 * Mirror of RadioGroup with three deltas:
 *   1. `type="checkbox"` instead of `type="radio"`.
 *   2. Optional `arraySubmit` toggle — when true, every input's `name`
 *      attribute is suffixed with `[]` so PHP collects them as an
 *      array. The error-span `data-error-for` always uses the BARE
 *      name (no brackets) so QS.validate keeps a single per-field
 *      error target regardless of the submission shape.
 *   3. `defaultValues` is an ARRAY (multi-select); RadioGroup's
 *      `defaultValue` is a single string.
 *
 * Config:
 *   - name           string, required  — HTML name (without `[]`; the
 *                                        suffix is added per-input when
 *                                        arraySubmit is true). Also the
 *                                        data-error-for key.
 *   - legendKey      string, required  — textKey for the <legend>.
 *   - layout         string, optional  — 'inline' | 'stacked'. Default
 *                                        'stacked'.
 *   - arraySubmit    bool,   optional  — Default false. When true emits
 *                                        `name="<name>[]"` on every input.
 *   - defaultValues  array,  optional  — Each entry must match one of
 *                                        the options' `value`. Inputs
 *                                        whose value is in this array
 *                                        are emitted with `checked`.
 *   - options        array,  required  — ≥ 1, each { value, labelKey }.
 *
 * Skipped for MVP:
 *   - `required` (HTML checkbox semantics are awkward at group level).
 *   - per-option `disabled` flag.
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class CheckboxGroupBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'checkbox-group';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- required + validation ------------------------------------
        self::requireField($config, 'name');
        self::requireField($config, 'legendKey');

        $name = (string)$config['name'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            throw new ComplexElementBuilderException(
                "Invalid checkbox group name '$name'. Use letters, digits, hyphens, underscores; must start with a letter."
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

        $arraySubmit = !empty($config['arraySubmit']);

        $options = $config['options'] ?? [];
        if (!is_array($options) || count($options) === 0) {
            throw new ComplexElementBuilderException("Checkbox group must have at least one option");
        }

        // Validate each option + detect duplicate values.
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

        $defaultValues = $config['defaultValues'] ?? [];
        if (!is_array($defaultValues)) {
            throw new ComplexElementBuilderException("'defaultValues' must be an array");
        }
        $defaultSet = [];
        foreach ($defaultValues as $dv) {
            $dvs = (string)$dv;
            if (!in_array($dvs, $seenValues, true)) {
                throw new ComplexElementBuilderException(
                    "defaultValues entry '$dvs' is not one of the option values"
                );
            }
            $defaultSet[$dvs] = true;
        }

        // ---- build children -------------------------------------------
        $children = [
            [
                'tag' => 'legend',
                'children' => [['textKey' => $legendKey]]
            ]
        ];

        $inputName = $arraySubmit ? ($name . '[]') : $name;

        foreach ($options as $opt) {
            $value = (string)$opt['value'];
            $inputParams = [
                'type'  => 'checkbox',
                'name'  => $inputName,
                'value' => $value,
            ];
            if (isset($defaultSet[$value])) {
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

        // Error span uses the BARE name (no `[]`) so QS.validate keeps a
        // single error target regardless of the submission shape.
        $children[] = [
            'tag'    => 'span',
            'params' => ['data-error-for' => $name]
        ];

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
