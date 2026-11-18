<?php
/**
 * OutboundUrlPolicy — SSRF guard for server-side outbound fetches.
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
 * scheme allowlist (layer 1) and IP pinning (layer 3) stay on. The answer comes
 * from qs_is_development() (src/functions/environment.php) — the one gate the
 * whole install shares — which defaults to production and degrades to
 * production on every malformed input. Changing it is a server-side config
 * edit, never a runtime API call.
 *
 * Redirect policy is the CALLER's choice, not this class's: serverFetch and
 * testApiEndpoint do NOT follow redirects (a 3xx is returned as-is), while
 * uploadAsset follows manually and re-runs check() on every hop.
 *
 * Certificate checking: tlsOptions() holds the one rule (never off; the list
 * php.ini names, else on Windows the system's store, else curl's default), and
 * tlsFailure() says what a refused certificate means. uploadAsset uses them.
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
     * The certificate rule of an outbound HTTPS fetch: checking is never switched off.
     *
     *   - php.ini names a list of trusted certificate authorities (`curl.cainfo`, else
     *     `openssl.cafile`): that file is used, as named. A name that is not a readable file makes
     *     curl fail, and tlsFailure() says which setting to fix; it is never replaced by another list.
     *   - it names none, on Windows: curl also reads the operating system's own store
     *     (CURLSSLOPT_NATIVE_CA), since PHP on Windows carries no list of its own.
     *   - it names none, elsewhere: nothing is set, and curl uses the system's list.
     *
     * @return array<int,mixed> options for curl_setopt_array()
     */
    public static function tlsOptions(): array
    {
        $options = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        $named = self::namedCaList();
        if ($named !== null) {
            $options[CURLOPT_CAINFO] = $named;
        } elseif (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        return $options;
    }

    /**
     * What to answer when an HTTPS fetch failed on the address's certificate, or null when it
     * failed for another reason (a timeout, a refused connection). Asked with curl's error number
     * and message, OpenSSL's verify result (CURLINFO_SSL_VERIFYRESULT) and the host.
     *
     * The setting is named only when the check could not be made at all: the list php.ini names
     * cannot be read (curl error 77), or no authority in the list curl was given signed the
     * certificate (error 60 with OpenSSL's result 20, which a server with no list sees for every
     * address). An expired, self-signed or wrong-host certificate is refused with curl's own
     * reason: no setting would make it valid.
     */
    public static function tlsFailure(int $curlErrno, string $curlError, int $verifyResult, string $host): ?string
    {
        if ($curlErrno === 77) {
            return "the certificate of {$host} could not be checked: the list of trusted certificate authorities"
                . ' php.ini names (curl.cainfo or openssl.cafile) cannot be read. Set it to a readable CA bundle'
                . ' file, then restart PHP.';
        }
        if ($curlErrno === 60 && $verifyResult === 20) {
            return "the certificate of {$host} could not be checked: no certificate authority this server trusts"
                . ' signed it. If this server has no list of trusted certificate authorities, set curl.cainfo in'
                . ' php.ini to a CA bundle file (the curl project publishes one, cacert.pem), then restart PHP.';
        }
        if ($curlErrno === 60) {
            return "the certificate of {$host} was refused: {$curlError}";
        }
        return null;
    }

    /** The list of trusted certificate authorities php.ini names, or null when it names none. */
    private static function namedCaList(): ?string
    {
        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $value = trim((string) ini_get($setting));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
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
     * Development mode lifts the internal-range block only.
     *
     * It asks the shared gate rather than resolving the environment itself: an
     * own `@require` could not suppress a ParseError (a deployer's typo would
     * fatally end every request reaching this class), and a `SECURE_FOLDER_PATH`
     * dependency would silently answer "production" whenever the constant is not
     * yet defined. Through the shared gate, every caller gets the same answer.
     */
    private static function isDevelopment(): bool
    {
        require_once __DIR__ . '/../functions/environment.php';
        return qs_is_development();
    }
}
