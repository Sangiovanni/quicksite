<?php
/**
 * OutboundUrlPolicy — SSRF guard for server-side outbound fetches (beta.10 C4 / F8).
 *
 * Every server-side fetcher runs check() on the destination URL BEFORE
 * handing it to curl. It closes the SSRF class in three layers:
 *
 *   1. Scheme allowlist — http/https only (blocks file://, gopher://, ftp://…),
 *      so the "external API" surface can't be turned into a local-file reader.
 *   2. Internal-range block — loopback, cloud metadata (169.254.x), RFC1918
 *      private, IPv6 loopback/ULA. Stops a low-trust token from using the
 *      server as a proxy into the private network or the cloud metadata
 *      endpoint. The check runs on every RESOLVED IP, so a hostname that
 *      resolves to an internal address (127.0.0.1.attacker.com, a numeric-IP
 *      trick, or a multi-record host) is refused too.
 *   3. IP pinning — the host is resolved ONCE and validated; check() returns a
 *      CURLOPT_RESOLVE entry so curl connects to exactly that IP instead of
 *      re-resolving the name. That closes DNS rebinding (the attacker can't
 *      answer the second lookup with an internal IP — there is no second
 *      lookup).
 *
 * ENVIRONMENT gate: in 'development' the internal-range block (layer 2) is
 * lifted so a local author can call http://localhost:3000 / a LAN API; the
 * scheme allowlist (layer 1) and IP pinning (layer 3) stay on. The default is
 * 'production' (secure) — resolved from secure/management/config/environment.php
 * (see .example), falling back to production when unset. Changing it is a
 * server-side config edit, never a runtime API call.
 *
 * Redirect policy is the CALLER's choice, not this class's: serverFetch and
 * testApiEndpoint do NOT follow redirects (a 3xx is returned as-is), while
 * uploadAsset follows manually and re-runs check() on every hop.
 */
class OutboundUrlPolicy
{
    /** Only these URL schemes may be fetched server-side. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Validate an outbound URL.
     *
     * @param string $url The fully-built destination URL.
     * @return array{ok:true,scheme:string,host:string,port:int,ip:string,resolve:array<int,string>}
     *              |array{ok:false,error:string}
     *              On success `resolve` is a CURLOPT_RESOLVE array (empty when
     *              the host was already an IP literal — nothing to pin).
     */
    public static function check(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'error' => 'Malformed URL'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return ['ok' => false, 'error' => 'URL is missing a scheme'];
        }

        // Scheme allowlist first, so file:// / gopher:// give a clear reason
        // rather than tripping the missing-host check below.
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return ['ok' => false, 'error' => "URL scheme '{$scheme}' is not allowed (only http/https)"];
        }

        if (empty($parts['host'])) {
            return ['ok' => false, 'error' => 'URL is missing a host'];
        }

        // parse_url keeps IPv6 literals bracketed ("[::1]"); strip for
        // validation/resolution, curl re-adds them from the URL itself.
        $host = $parts['host'];
        $bareHost = (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']')
            ? substr($host, 1, -1)
            : $host;

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        $isLiteralIp = filter_var($bareHost, FILTER_VALIDATE_IP) !== false;
        $ips = self::resolveHost($bareHost, $isLiteralIp);
        if (empty($ips)) {
            return ['ok' => false, 'error' => "Could not resolve host '{$bareHost}'"];
        }

        if (!self::isDevelopment()) {
            foreach ($ips as $ip) {
                if (self::isInternalIp($ip)) {
                    return ['ok' => false, 'error' => "Host '{$bareHost}' resolves to a blocked internal address ({$ip})"];
                }
            }
        }

        // Pin the first validated IP so curl doesn't re-resolve (rebind guard).
        // No pinning needed when the host was already a literal IP.
        $pinnedIp = $ips[0];
        $resolve = $isLiteralIp ? [] : [$bareHost . ':' . $port . ':' . $pinnedIp];

        return [
            'ok'      => true,
            'scheme'  => $scheme,
            'host'    => $bareHost,
            'port'    => $port,
            'ip'      => $pinnedIp,
            'resolve' => $resolve,
        ];
    }

    /**
     * Resolve a host to its IP(s). An IP literal returns itself. Otherwise we
     * take A records (the common, fast case) and only fall back to AAAA when
     * there are no A records (IPv6-only host), keeping the hot path to one
     * lookup. Returns [] on resolution failure (fail closed).
     *
     * @return array<int,string>
     */
    private static function resolveHost(string $host, bool $isLiteralIp): array
    {
        if ($isLiteralIp) {
            return [$host];
        }
        $ips = @gethostbynamel($host);   // A records; false on failure (not the input)
        if (is_array($ips) && !empty($ips)) {
            return array_values(array_unique($ips));
        }
        $out = [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $out[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * True when an IP is loopback / link-local (metadata) / RFC1918 private /
     * IPv6 ULA / other reserved range. NO_PRIV_RANGE covers RFC1918 + fc00::/7;
     * NO_RES_RANGE covers 127/8, 169.254/16, 0.0.0.0/8, ::1, fe80::, reserved.
     */
    private static function isInternalIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Development mode lifts the internal-range block only. Prefers a
     * bootstrap-defined ENVIRONMENT constant; otherwise reads the gitignored
     * secure/management/config/environment.php; defaults to production.
     */
    private static function isDevelopment(): bool
    {
        static $isDev = null;
        if ($isDev !== null) {
            return $isDev;
        }
        if (defined('ENVIRONMENT')) {
            return $isDev = (ENVIRONMENT === 'development');
        }
        $env = 'production';
        if (defined('SECURE_FOLDER_PATH')) {
            $path = SECURE_FOLDER_PATH . '/management/config/environment.php';
            if (is_file($path)) {
                $cfg = @require $path;
                if (is_array($cfg) && isset($cfg['environment']) && is_string($cfg['environment'])) {
                    $env = $cfg['environment'];
                }
            }
        }
        return $isDev = ($env === 'development');
    }
}
