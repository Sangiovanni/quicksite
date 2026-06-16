<?php
/**
 * OAuthButtonBuilder
 *
 * Emits a sign-in-with-X link — `<a class="qs-oauth-button
 * qs-oauth-button--<provider>" href="/auth/oauth/<provider>/start">`
 * wrapping a textKey label (and an optional icon span):
 *
 *   <a class="qs-oauth-button qs-oauth-button--google"
 *      href="/auth/oauth/google/start">
 *     <span class="qs-oauth-button__icon icon-google" aria-hidden="true"></span>
 *     <textKey/>
 *   </a>
 *
 * Real anchor, full-page navigation — matches the redirect-not-popup
 * OAuth UX locked in Slice 2c/2d. Provider-specific CSS class
 * (`qs-oauth-button--<provider>`) lets designers theme per-provider
 * without re-emitting structure.
 *
 * Pure builder — config in, node spec out. The wizard JS orchestrates
 * route + resolver creation OUTSIDE this builder (calls addRoute x2 +
 * setRouteResolver x2 before the final addComplexElement). Builder
 * assumes those exist by the time it runs.
 *
 * Config:
 *   - provider    string, required — provider id (must match a key in
 *                                    oauth-presets.json, per-project or
 *                                    admin). Used for both the CSS
 *                                    modifier class and the href path.
 *   - labelKey    string, required — textKey for the button label.
 *                                    Convention: form.signin.<provider>.
 *   - iconClass   string, optional — extra CSS class for the icon span.
 *                                    Omit for label-only button.
 *   - returnTo    string, optional — path on this site users land on after
 *                                    a successful sign-in. Appended to the
 *                                    href as `?return=<urlencoded>`.
 *                                    Server-side sanitiseReturnTo guards
 *                                    against open-redirect — only same-
 *                                    site paths starting with '/' (not
 *                                    '//') are honoured. Omit to land on
 *                                    `/` (default in handleStart).
 *
 * Beta.9 A1 Slice 4 (locked 2026-06-15).
 */

require_once __DIR__ . '/../ComplexElementBuilder.php';

class OAuthButtonBuilder extends ComplexElementBuilder {
    public function kind(): string {
        return 'oauth-button';
    }

    public function build(array $config): array {
        $config = self::stripWizardKeys($config);

        self::requireField($config, 'provider');
        self::requireField($config, 'labelKey');

        $provider = (string) $config['provider'];
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $provider)) {
            throw new ComplexElementBuilderException(
                "OAuth provider id must be lowercase letters / digits / hyphens "
                . "(matches the oauth-presets.json key shape). Got: '$provider'"
            );
        }

        $labelKey = trim((string) $config['labelKey']);
        if ($labelKey === '') {
            throw new ComplexElementBuilderException(
                "OAuth button labelKey cannot be empty"
            );
        }

        $iconClass = isset($config['iconClass']) ? trim((string) $config['iconClass']) : '';
        $returnTo  = isset($config['returnTo']) ? trim((string) $config['returnTo']) : '';

        $href = '/auth/oauth/' . $provider . '/start';
        if ($returnTo !== '') {
            // Final sanitisation happens server-side at handleStart time;
            // we just url-encode the value into the query string. Authors
            // can also edit the href after insertion via the visual
            // editor's params panel — keep this string-append simple.
            $href .= '?return=' . rawurlencode($returnTo);
        }

        $children = [];
        if ($iconClass !== '') {
            $children[] = [
                'tag'    => 'span',
                'params' => [
                    'class'       => 'qs-oauth-button__icon ' . $iconClass,
                    'aria-hidden' => 'true',
                ],
                'children' => [],
            ];
        }
        $children[] = ['textKey' => $labelKey];

        return [
            'tag'    => 'a',
            'params' => [
                'class' => 'qs-oauth-button qs-oauth-button--' . $provider,
                'href'  => $href,
            ],
            'children' => $children,
        ];
    }

    public function declaredTextKeys(array $config): array {
        $key = isset($config['labelKey']) ? trim((string) $config['labelKey']) : '';
        return $key !== '' ? [$key] : [];
    }
}
