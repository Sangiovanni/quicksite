<?php
/**
 * The visual editor's controls: ONE definition of what each tool and each of Select's buttons needs, and of
 * what the simple view leaves out. Read by the tool row (templates/pages/preview/tools.php), Select's panel
 * (contextual-select.php) and CSS's panel (contextual-style.php).
 *
 * `requires`: the commands a role must hold for the editor to offer the control. They are the commands the
 *   control itself sends, so a role without them would only meet the server's refusal. Drawn as
 *   `data-requires-command`, the filter the panel runs once permissions load (admin.js filterByPermissions);
 *   a role holding a command's category holds all of it, so one command of each category the control writes
 *   to is enough. A control with no `requires` only reads: every role that opens the editor has it.
 * `advanced`: the simple view leaves it out. Drawn as `data-editor-advanced`, which the page's
 *   admin-body--simple class hides (admin.css), so a switch of mode shows or hides it with no reload.
 *
 * ⚠ THE MODE IS NO PERMISSION. The role decides what the editor offers, in either mode; the mode only decides
 * what the simple view draws. Each command still checks its caller's role on the server, and nothing here is
 * read there.
 */

if (!function_exists('qs_editor_controls')) {
    /**
     * Every control the definition governs, by name.
     *
     * @return array<string, array{requires?: list<string>, advanced?: bool}>
     */
    function qs_editor_controls(): array {
        return [
            // The tool row. Select reads; every other tool writes.
            'tool.select'      => [],
            'tool.drag'        => ['requires' => ['moveNode']],
            // Text saves a keyed text in the language shown, and a literal one in the structure.
            'tool.text'        => ['requires' => ['setTranslationKeys', 'editStructure']],
            'tool.style'       => ['requires' => ['setStyleRule', 'setRootVariables']],
            'tool.js'          => ['requires' => ['addInteraction'], 'advanced' => true],
            'tool.translation' => ['requires' => ['setTranslationKeys']],
            // Workflows: every shipped workflow but one writes the site, and the one left (a batch) runs what
            // is pasted into it. A role that may write no content runs none; the server checks each run's
            // commands against the role all the same.
            'tool.ai-tools'    => ['requires' => ['addNode']],

            // CSS's parts. Source writes the whole stylesheet; the Animations tab and a selector's Animate
            // open the animation editors.
            'style.source'     => ['requires' => ['editStyles'], 'advanced' => true],
            'style.animations' => ['advanced' => true],
            'style.animate'    => ['advanced' => true],

            // Select's buttons. Add opens the Add form, whose every choice sends a content write.
            'select.add'            => ['requires' => ['addNode']],
            'select.edit-params'    => ['requires' => ['editNode']],
            'select.duplicate'      => ['requires' => ['duplicateNode']],
            'select.delete'         => ['requires' => ['deleteNode']],
            // Snippets and components are not in the simple Add: saving one makes something simple cannot use.
            'select.save-snippet'   => ['requires' => ['createSnippet'], 'advanced' => true],
            'select.save-component' => ['requires' => ['editStructure'], 'advanced' => true],
            'select.variables'      => ['requires' => ['editStructure']],
            'select.enums'          => ['requires' => ['editStructure']],
            'select.translate-csv'  => ['requires' => ['importStructureTranslations']],
            // Emulation only fills the preview with sample values, kept in this browser: it writes nothing.
            'select.emulation'      => [],
        ];
    }

    /**
     * The attributes that draw one control's place in the definition, each with its leading space.
     *
     * @param string $control a name of qs_editor_controls()
     * @return string
     */
    function qs_editor_control_attrs(string $control): string {
        $all = qs_editor_controls();
        if (!array_key_exists($control, $all)) {
            throw new InvalidArgumentException("No editor control named '{$control}'");
        }
        $def = $all[$control];
        $out = '';
        if (!empty($def['requires'])) {
            $out .= ' data-requires-command="' . htmlspecialchars(implode(',', $def['requires']), ENT_QUOTES, 'UTF-8') . '"';
        }
        if (!empty($def['advanced'])) {
            $out .= ' data-editor-advanced';
        }
        return $out;
    }
}
