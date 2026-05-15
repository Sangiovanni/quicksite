<?php
/**
 * FieldRowBuilder
 *
 * Emits a single form-field subtree:
 *   <div class="field">
 *     <label for="<id>"><textKey></label>
 *     <input type="<type>" name="<name>" id="<id>" [required] [...] />
 *     <span data-error-for="<name>"></span>
 *   </div>
 *
 * Building block for Form Scaffold and the headline answer to
 * "I need a form input that's already wired for client-side
 * validation messages". The `[data-error-for=...]` span is
 * the target QS.validate writes its per-field message into.
 *
 * Config:
 *   - name:       string, required, HTML name attribute
 *   - type:       string, default 'text', any HTML input type
 *                 (text|email|tel|url|number|password|search|date|...)
 *                 plus 'textarea' (emits <textarea> instead of <input>)
 *   - labelKey:   string, required, textKey for the <label>
 *   - required:   bool,   default false → adds required=""
 *   - placeholder: string|null, default null → textKey for placeholder
 *                 (only applies to text-shaped inputs)
 *   - autocomplete: string|null (passes through)
 *
 * Notes:
 *   - The HTML `id` attribute defaults to the `name` value (sufficient
 *     for <label for=…>); user can override later by editing the JSON.
 *   - The `[data-error-for]` is keyed by `name` to match QS.validate's
 *     lookup (see docs/ARCHITECTURE.md §8.2).
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class FieldRowBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'field-row';
    }

    /**
     * HTML input types we accept verbatim. Anything else → reject.
     * Matches the visual editor's Group A picker (beta.6).
     */
    private const ACCEPTED_TYPES = [
        'text', 'email', 'tel', 'url', 'number', 'password', 'search',
        'date', 'time', 'datetime-local', 'month', 'week', 'color',
        'file', 'hidden', 'checkbox', 'radio', 'range',
        'submit', 'reset', 'button',
        'textarea'  // meta-type → emits <textarea>
    ];

    /** Input types where a `placeholder` attribute is meaningful. */
    private const PLACEHOLDER_TYPES = [
        'text', 'email', 'tel', 'url', 'number', 'password',
        'search', 'date', 'time', 'datetime-local', 'month',
        'week', 'textarea'
    ];

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        self::requireField($config, 'name');
        self::requireField($config, 'labelKey');

        $name = (string)$config['name'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            throw new ComplexElementBuilderException(
                "Invalid field name '$name'. Use letters, digits, hyphens, underscores; must start with a letter."
            );
        }

        $type = $config['type'] ?? 'text';
        if (!in_array($type, self::ACCEPTED_TYPES, true)) {
            throw new ComplexElementBuilderException(
                "Unsupported input type '$type'. Accepted: " . implode(', ', self::ACCEPTED_TYPES)
            );
        }

        $labelKey = trim((string)$config['labelKey']);
        if ($labelKey === '') {
            throw new ComplexElementBuilderException("labelKey cannot be empty");
        }

        $id = isset($config['id']) && $config['id'] !== '' ? (string)$config['id'] : $name;
        self::validateHtmlId($id, 'id');

        $required = !empty($config['required']);
        $placeholderKey = isset($config['placeholder']) && $config['placeholder'] !== ''
            ? (string)$config['placeholder']
            : null;
        $autocomplete = isset($config['autocomplete']) && $config['autocomplete'] !== ''
            ? (string)$config['autocomplete']
            : null;

        // ----- input/textarea params -----
        $inputParams = [
            'name' => $name,
            'id'   => $id,
        ];
        if ($type !== 'textarea') {
            $inputParams['type'] = $type;
        }
        if ($required) {
            $inputParams['required'] = true;
        }
        if ($placeholderKey !== null && in_array($type, self::PLACEHOLDER_TYPES, true)) {
            // Translatable attribute — the renderer will resolve a
            // dot-shaped string as a translation key automatically.
            $inputParams['placeholder'] = $placeholderKey;
        }
        if ($autocomplete !== null) {
            $inputParams['autocomplete'] = $autocomplete;
        }

        $inputTag = ($type === 'textarea') ? 'textarea' : 'input';
        $inputNode = ['tag' => $inputTag, 'params' => $inputParams];
        if ($inputTag === 'textarea') {
            // <textarea> needs a children slot so the renderer treats it
            // as a container; empty is fine, user can edit later.
            $inputNode['children'] = [];
        }

        // ----- the full subtree -----
        return [
            'tag' => 'div',
            'params' => ['class' => 'field'],
            'children' => [
                [
                    'tag' => 'label',
                    'params' => ['for' => $id],
                    'children' => [['textKey' => $labelKey]]
                ],
                $inputNode,
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
        if (isset($config['placeholder']) && $config['placeholder'] !== '') {
            $keys[] = (string)$config['placeholder'];
        }
        return $keys;
    }
}
