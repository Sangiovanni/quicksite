<?php
/**
 * URL aliases — the single application point.
 *
 * An alias maps one URL the visitor may type onto a real route:
 *
 *   "/legacy-home": { "target": "/home", "type": "redirect" }   301, URL changes
 *   "/shop":        { "target": "/catalog", "type": "internal" } silent rewrite
 *
 * ⚠ THIS RUNS BEFORE TrimParameters. An internal alias works by rewriting
 * REQUEST_URI, so it has to happen while REQUEST_URI is still what the router
 * will read. Call it immediately before constructing TrimParameters.
 *
 * WHY A SHARED FUNCTION FILE. Two surfaces serve the same project's pages —
 * the `/p/<projectId>/` renderer and a production build's own front controller
 * — and an alias that resolves on one and 404s on the other is the exact class
 * of "works in preview, broken in production" split this engine keeps paying
 * for. One implementation, two callers, no drift.
 *
 * WHY `src/functions/` AND NOT `utilsManagement.php`. Same reason as
 * projectLanguage.php: this travels into a production build, which carries only
 * a handful of files. utilsManagement.php does not travel, so an alias helper
 * living there would make every built site with an alias fatal.
 *
 * The language prefix and the URL space both come from the shared vocabulary
 * (projectLanguage.php + PUBLIC_FOLDER_SPACE) rather than being re-derived
 * here, so a redirect lands where the router would have looked.
 */

require_once __DIR__ . '/projectLanguage.php';

if (!function_exists('qs_apply_alias_routing')) {
    /**
     * Resolve the current request against the project's aliases and act.
     *
     * Three outcomes:
     *   - no alias matches            → returns, nothing touched
     *   - a `redirect` alias matches  → sends a 301 and EXITS
     *   - an `internal` alias matches → rewrites $_SERVER['REQUEST_URI'] and
     *                                   returns, so the router sees the target
     *
     * Requires PROJECT_PATH, PUBLIC_FOLDER_SPACE and the project context
     * (CONFIG / MULTILINGUAL_SUPPORT) to be bound already.
     *
     * @return string|null The internal-rewrite target, or null when no alias
     *                     applied. Returned for the caller's diagnostics; the
     *                     rewrite has already been performed.
     */
    function qs_apply_alias_routing(): ?string
    {
        $aliasesFile = PROJECT_PATH . '/data/aliases.json';
        if (!file_exists($aliasesFile)) {
            return null;
        }

        $aliases = json_decode(file_get_contents($aliasesFile), true);
        if (!is_array($aliases) || $aliases === []) {
            return null;
        }

        // The path the visitor asked for, with the URL space peeled off — the
        // same normalisation TrimParameters::parseUrl() performs, so the two
        // agree about what "the page part" of a URL is.
        $rawPath = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
        $space   = defined('PUBLIC_FOLDER_SPACE') ? trim(PUBLIC_FOLDER_SPACE, '/') : '';
        if ($space !== '') {
            $rawPath = removePrefix($rawPath, $space . '/');
        }

        $parts = array_values(array_filter(explode('/', $rawPath), static fn($p) => $p !== ''));

        // Peel the language segment when the URL carries one. Asked through the
        // shared vocabulary, which answers false for every segment on a
        // mono-language project — so this is one check, not a check plus a
        // MULTILINGUAL_SUPPORT gate that could disagree with it.
        $langCode = null;
        if (!empty($parts) && qs_is_project_language($parts[0])) {
            $langCode = array_shift($parts);
        }

        $potentialPath = !empty($parts) ? '/' . implode('/', $parts) : '';
        if (!isset($aliases[$potentialPath]) || !is_array($aliases[$potentialPath])) {
            return null;
        }

        $aliasInfo  = $aliases[$potentialPath];
        $targetPath = ltrim((string) ($aliasInfo['target'] ?? ''), '/');
        if ($targetPath === '') {
            return null;
        }
        $aliasType = $aliasInfo['type'] ?? 'redirect';

        // The language segment is the same for both outcomes. It used to be read
        // from CONFIG['DEFAULT_LANGUAGE'] — a key no project config has ever
        // held; it is LANGUAGE_DEFAULT — so on a multilingual project reached
        // without a language in the URL the prefix came out empty and the
        // Location header became protocol-relative (`//home`), which a browser
        // resolves as a different HOST.
        $langPrefix = qs_project_is_multilingual()
            ? '/' . ($langCode ?? qs_project_default_language())
            : '';

        // ⚠ THE TWO OUTCOMES COMPOSE AGAINST DIFFERENT BASES, and conflating
        // them is what made this wrong on both surfaces.
        //
        //   redirect → a URL the BROWSER will request, so it composes against
        //              the site's public base: '/space/v1/' in a build,
        //              '/p/<projectId>/' on the project renderer. That is
        //              exactly what QS_PUBLIC_BASE is for, and it is what every
        //              other link on the page already uses.
        //   rewrite  → a value the ROUTER will read back out of REQUEST_URI,
        //              and the router strips only PUBLIC_FOLDER_SPACE. Giving
        //              it the public base would put a prefix in front of the
        //              path that nothing downstream removes.
        //
        // They coincide in a build, which is why only the renderer showed it:
        // an alias there redirected out of the project's own namespace.
        if ($aliasType === 'redirect') {
            $publicBase = defined('QS_PUBLIC_BASE')
                ? rtrim(QS_PUBLIC_BASE, '/')
                : ($space !== '' ? '/' . $space : '');
            header('Location: ' . $publicBase . $langPrefix . '/' . $targetPath, true, 301);
            exit;
        }

        $_SERVER['REQUEST_URI'] = ($space !== '' ? '/' . $space : '') . $langPrefix . '/' . $targetPath;
        return $targetPath;
    }
}
