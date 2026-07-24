<?php
/**
 * renderBootstrap.php — beta.10 C15 Slice 15.2. Tier-2 RENDER BOOTSTRAP.
 *
 * Required ONLY by public/p/index.php (the relocated project renderer). The split
 * (§15.1.6 D6):
 *   - Tier 1 = public/init.php: the install-wide constants EVERY entry point needs
 *     (admin, admin-api, management, renderer) + BASE_URL meaning "where this
 *     install/panel is".
 *   - Tier 2 = THIS file: the setup ONLY the /p/<id>/ render path needs — the
 *     public-base resolution seam.
 *
 * Why a seam and not the finished thing yet: for a /p/<id>/ request the public base
 * is ALREADY resolved before this file runs — surfaceB sets BASE_URL to the /p/<id>
 * URL pre-init, or init.php's tier-1 fallback derives it. The live render path funnels
 * every relative link through JsonToHtmlRenderer::processUrl(), which prefixes BASE_URL
 * (proven under 7 different bases with zero renderer changes — 15_1_baseurl_decoupling.php).
 * So the ONLY open question is where the base VALUE comes from, and that is 15.4's job.
 *
 * C15 15.4 / R1 replaces qs_resolve_public_base()'s body with the 3-tier resolution
 * (QS_PUBLIC_BASE_URL server env → the project's own public_base_url → the
 * request-derived base) and emits R1's root-relative path form for in-page links.
 * Until then it returns today's value unchanged: identical behaviour, a no-op seam.
 */

if (!function_exists('qs_resolve_public_base')) {
    /**
     * The public base the RENDERED project's URLs compose against.
     *
     * C15 15.2: a pass-through to the already-resolved BASE_URL (falls back to '/'
     * defensively if BASE_URL is somehow undefined). C15 15.4 fills the 3-tier
     * resolution + R1 root-relative default in HERE — one place (CLAUDE.md
     * centralize): this single seam supersedes the four hand-rolled base
     * derivations §1 problem #4 named.
     */
    function qs_resolve_public_base(): string {
        return defined('BASE_URL') ? BASE_URL : '/';
    }
}
