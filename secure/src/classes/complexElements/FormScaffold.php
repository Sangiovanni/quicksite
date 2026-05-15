<?php
/**
 * FormScaffoldBuilder
 *
 * Emits a full <form> subtree:
 *   <form id="<id>" method="<method>" onsubmit="{{call:validate:event,#<id>}};{{call:fetch:...}}">
 *     ...N field rows (each built by FieldRowBuilder)...
 *     <button type="submit"><textKey></button>
 *   </form>
 *
 * The headline kind. Reuses FieldRowBuilder so a row built standalone
 * (via the 'field-row' kind) is byte-identical to one inside a form
 * scaffold — same labels, same [data-error-for] hooks, same validation
 * targets.
 *
 * Config:
 *   - id              string, required  — HTML id used for the form AND
 *                                          for `[data-error-for=<name>]`
 *                                          lookups in QS.validate.
 *   - method          string, default 'POST' — HTML form method (GET/POST).
 *   - submitMode      string, default 'api' — 'api' | 'url' | 'none'.
 *                       'none' = no onsubmit handler emitted; user wires
 *                       it manually via the interaction picker.
 *   - apiId           string, required if submitMode='api'
 *   - endpointId      string, required if submitMode='api'
 *   - submitUrl       string, required if submitMode='url'
 *   - submitMethod    string, default = `method` value, when submitMode='url'.
 *   - validateOnSubmit bool, default true — prepends {{call:validate:...}}.
 *   - fetchOnSubmit    bool, default true — appends {{call:fetch:...,body=#id}}.
 *   - fields          array of FieldRow config objects (each one passed
 *                     verbatim to FieldRowBuilder::build).
 *   - submitLabelKey  string, required — textKey for the submit button.
 *
 * Notes:
 *   - The onsubmit chain compiles via JsonToHtmlRenderer's
 *     transformCallSyntax (see docs/ARCHITECTURE.md §8.0.1):
 *     `validate` runs as a sync prelude, `fetch` is awaited inside an
 *     async IIFE. Validation throw aborts the rest of the chain.
 *   - For URL mode we currently reject commas in the URL — the call
 *     parser splits args on commas. Pass query params via the URL
 *     properly (or wait for the fetch-picker overhaul which will
 *     handle escape automatically).
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';
require_once __DIR__ . '/FieldRow.php';

class FormScaffoldBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'form-scaffold';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        // ---- required + defaults -----
        self::requireField($config, 'id');
        self::requireField($config, 'submitLabelKey');

        $id = (string)$config['id'];
        self::validateHtmlId($id, 'id');

        $method = strtoupper((string)($config['method'] ?? 'POST'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new ComplexElementBuilderException(
                "Form method must be GET or POST, got '$method'"
            );
        }

        $submitMode = (string)($config['submitMode'] ?? 'api');
        if (!in_array($submitMode, ['api', 'url', 'none'], true)) {
            throw new ComplexElementBuilderException(
                "submitMode must be 'api', 'url', or 'none' — got '$submitMode'"
            );
        }

        $validateOnSubmit = !isset($config['validateOnSubmit']) ? true : (bool)$config['validateOnSubmit'];
        $fetchOnSubmit    = !isset($config['fetchOnSubmit'])    ? true : (bool)$config['fetchOnSubmit'];

        $submitLabelKey = trim((string)$config['submitLabelKey']);
        if ($submitLabelKey === '') {
            throw new ComplexElementBuilderException("submitLabelKey cannot be empty");
        }

        // ---- fields (delegate to FieldRowBuilder for shape parity) -----
        $fieldConfigs = $config['fields'] ?? [];
        if (!is_array($fieldConfigs)) {
            throw new ComplexElementBuilderException("'fields' must be an array");
        }
        if (count($fieldConfigs) === 0) {
            throw new ComplexElementBuilderException("Form must have at least one field");
        }

        $fieldRowBuilder = new FieldRowBuilder();
        $fieldNodes = [];
        $seenNames = [];
        foreach ($fieldConfigs as $idx => $fc) {
            if (!is_array($fc)) {
                throw new ComplexElementBuilderException("Field at index $idx must be an object");
            }
            // Catch dupe names early — they'd silently break QS.validate's
            // [data-error-for=<name>] lookup (would target only the first).
            $name = $fc['name'] ?? '';
            if ($name !== '') {
                if (in_array($name, $seenNames, true)) {
                    throw new ComplexElementBuilderException(
                        "Duplicate field name '$name'. Each field must have a unique name."
                    );
                }
                $seenNames[] = $name;
            }
            try {
                $fieldNodes[] = $fieldRowBuilder->build($fc);
            } catch (ComplexElementBuilderException $e) {
                throw new ComplexElementBuilderException(
                    "Field at index $idx (name='" . ($fc['name'] ?? '') . "'): " . $e->getMessage()
                );
            }
        }

        // ---- submit button -----
        $submitButton = [
            'tag' => 'button',
            'params' => ['type' => 'submit'],
            'children' => [['textKey' => $submitLabelKey]]
        ];

        // ---- onsubmit chain -----
        $onsubmitCalls = [];
        if ($validateOnSubmit) {
            $onsubmitCalls[] = '{{call:validate:event,#' . $id . '}}';
        }
        if ($fetchOnSubmit && $submitMode === 'api') {
            self::requireField($config, 'apiId');
            self::requireField($config, 'endpointId');
            $apiId = (string)$config['apiId'];
            $endpointId = (string)$config['endpointId'];
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $apiId)) {
                throw new ComplexElementBuilderException("Invalid apiId '$apiId'");
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $endpointId)) {
                throw new ComplexElementBuilderException("Invalid endpointId '$endpointId'");
            }
            $onsubmitCalls[] = '{{call:fetch:@' . $apiId . '/' . $endpointId . ',body=#' . $id . '}}';
        }
        if ($fetchOnSubmit && $submitMode === 'url') {
            self::requireField($config, 'submitUrl');
            $url = (string)$config['submitUrl'];
            // Comma would break the call-arg parser. Pass query params
            // properly OR wait for the fetch-picker overhaul's escape.
            if (strpos($url, ',') !== false) {
                throw new ComplexElementBuilderException(
                    "Submit URL cannot contain commas in form-scaffold MVP. " .
                    "Use the interaction picker after the form is created for richer URLs."
                );
            }
            $submitMethod = strtoupper((string)($config['submitMethod'] ?? $method));
            $onsubmitCalls[] = '{{call:fetch:' . $submitMethod . ',' . $url . ',body=#' . $id . '}}';
        }

        // ---- form params -----
        $formParams = [
            'id' => $id,
            'method' => $method,
        ];
        if (!empty($onsubmitCalls)) {
            $formParams['onsubmit'] = implode(';', $onsubmitCalls);
        }

        // ---- assemble -----
        return [
            'tag' => 'form',
            'params' => $formParams,
            'children' => array_merge($fieldNodes, [$submitButton])
        ];
    }

    public function declaredTextKeys(array $config): array {
        $keys = [];
        if (isset($config['submitLabelKey']) && $config['submitLabelKey'] !== '') {
            $keys[] = (string)$config['submitLabelKey'];
        }
        // Collect keys from each field via FieldRowBuilder so we never
        // miss one — single source of truth for what a row produces.
        if (isset($config['fields']) && is_array($config['fields'])) {
            $fieldRowBuilder = new FieldRowBuilder();
            foreach ($config['fields'] as $fc) {
                if (is_array($fc)) {
                    foreach ($fieldRowBuilder->declaredTextKeys($fc) as $k) {
                        $keys[] = $k;
                    }
                }
            }
        }
        return $keys;
    }
}
