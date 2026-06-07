<?php
/**
 * resolverCache.php — File-based response cache for the server-side
 * data resolver (beta.8 A2 Slice 4).
 *
 * Per-route opt-in via resolver.cacheTTL (seconds). Per-request default
 * = no cache. Keeps the no-deps rule — no Redis, no Memcached, just
 * JSON files in a gitignored directory.
 *
 * Cache key: sha256(endpointRef + canonicalised inputs). Inputs are
 * ksort'd before JSON-encoding so key order in the resolver config
 * doesn't fragment the cache.
 *
 * Entry shape:
 *   {
 *       "endpoint":   "@apiId/endpointId",  // for endpoint/api-targeted clears
 *       "stored_at":  <unix timestamp>,
 *       "expires_at": <unix timestamp>,
 *       "response":   { ok, status, data, error? }  // raw serverFetch envelope
 *   }
 *
 * Auth-cacheable rule (LOCKED in BETA8_DATA_RESOLVER.md):
 *   - `none` → cacheable (no user identity)
 *   - `apiKey` → cacheable (server-side shared secret, not per-user)
 *   - `bearer` / `cookie` / `basic` → NOT cacheable. The response is
 *     scoped to whoever's session was authenticated; sharing across
 *     users would cross-leak data. Per-user-bucket caching is beta.9+.
 *
 * Invalidation in v1:
 *   - TTL (entries expire after their TTL window passes; lazy-deleted
 *     on read miss + actively swept by cleanResolverCache command)
 *   - Auto-clear on editApi (locked Q1): when an endpoint's config
 *     changes, walk the cache + delete entries for that endpoint /
 *     API so authors don't see stale data.
 *   - Manual: cleanResolverCache command (all / before-timestamp / expired).
 *
 * Broader invalidation hooks (mutation broadcast, related-data
 * triggers, etc.) are filed as beta.9.
 *
 * Per-call observability seam (locked in design): writes
 * $GLOBALS['__qs_resolver_cache_status'] to one of:
 *   'hit'       — cache satisfied the request
 *   'miss'      — eligible to cache but no entry; will fetch + write
 *   'skip'      — TTL=0 (resolver config didn't opt in)
 *   'disabled'  — TTL>0 but auth-type forbids caching (per-user)
 * public/index.php picks this up and emits as X-QS-Resolver-Cache header
 * so the user can watch cache behaviour in DevTools.
 */

function getResolverCachePath(): string {
    return SECURE_FOLDER_PATH . '/cache/resolver';
}

/**
 * Canonicalise inputs + endpoint into a stable cache key. Sorting keys
 * before json_encode avoids fragmentation from different config orderings
 * producing different hashes for semantically-identical inputs.
 */
function _resolverCacheKey(string $endpointRef, array $inputs): string {
    ksort($inputs);
    $serialised = $endpointRef . '|' . json_encode($inputs, JSON_UNESCAPED_SLASHES);
    return hash('sha256', $serialised);
}

/**
 * Decide whether an endpoint's auth type is safe to cache. Per-user
 * auth (bearer / cookie / basic) cannot be cached server-side without
 * risking cross-user data leaks. apiKey is a server-side shared secret;
 * none has no identity to leak. Both safe.
 */
function isResolverAuthCacheable(string $authType): bool {
    return in_array($authType, ['none', 'apiKey'], true);
}

/**
 * Read a cache entry if it exists + is not expired. Returns null on
 * miss / expired / read-error.
 */
function readResolverCache(string $endpointRef, array $inputs): ?array {
    $path = getResolverCachePath() . '/' . _resolverCacheKey($endpointRef, $inputs) . '.json';
    if (!file_exists($path)) return null;
    $content = @file_get_contents($path);
    if ($content === false || $content === '') return null;
    $entry = json_decode($content, true);
    if (!is_array($entry) || !isset($entry['expires_at']) || !isset($entry['response'])) {
        return null;
    }
    if ((int) $entry['expires_at'] < time()) {
        // Lazy delete on expired-read.
        @unlink($path);
        return null;
    }
    return is_array($entry['response']) ? $entry['response'] : null;
}

/**
 * Write a cache entry. Best-effort — filesystem rare-failures (full
 * disk, permission denied) log + continue. Returns true on success,
 * false otherwise. The caller doesn't need to surface failure to the
 * user; the next request just re-fetches.
 */
function writeResolverCache(string $endpointRef, array $inputs, array $response, int $ttl): bool {
    if ($ttl <= 0) return false;
    $dir = getResolverCachePath();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[resolver-cache] mkdir failed: ' . $dir);
            return false;
        }
    }
    $path = $dir . '/' . _resolverCacheKey($endpointRef, $inputs) . '.json';
    $now = time();
    $entry = [
        'endpoint'   => $endpointRef,
        'stored_at'  => $now,
        'expires_at' => $now + $ttl,
        'response'   => $response,
    ];
    $bytes = @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($bytes === false) {
        error_log('[resolver-cache] write failed: ' . $path);
        return false;
    }
    return true;
}

/**
 * Delete all cache entries matching an exact endpoint reference.
 * Used when an endpoint's config changes (editApi auto-clear).
 *
 * @return int Number of entries deleted.
 */
function clearResolverCacheForEndpoint(string $endpointRef): int {
    $dir = getResolverCachePath();
    if (!is_dir($dir)) return 0;
    $files = glob($dir . '/*.json') ?: [];
    $count = 0;
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        $entry = json_decode($content, true);
        if (!is_array($entry)) continue;
        if (($entry['endpoint'] ?? '') === $endpointRef) {
            if (@unlink($file)) $count++;
        }
    }
    return $count;
}

/**
 * Delete all cache entries belonging to an API (any endpoint under
 * @apiId/*). Used when the API itself changes (editApi auto-clear).
 *
 * @return int Number of entries deleted.
 */
function clearResolverCacheForApi(string $apiId): int {
    $dir = getResolverCachePath();
    if (!is_dir($dir)) return 0;
    $prefix = '@' . $apiId . '/';
    $files = glob($dir . '/*.json') ?: [];
    $count = 0;
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        $entry = json_decode($content, true);
        if (!is_array($entry)) continue;
        $endpoint = (string) ($entry['endpoint'] ?? '');
        if (strpos($endpoint, $prefix) === 0) {
            if (@unlink($file)) $count++;
        }
    }
    return $count;
}

/**
 * Sweep entries based on the criteria:
 *   - $beforeTimestamp !== null → delete entries with stored_at < timestamp
 *   - $beforeTimestamp === null → delete expired entries (default housekeeping)
 *
 * Malformed / unreadable entries get deleted as housekeeping too.
 *
 * @return int Number of entries deleted.
 */
function sweepResolverCache(?int $beforeTimestamp = null): int {
    $dir = getResolverCachePath();
    if (!is_dir($dir)) return 0;
    $files = glob($dir . '/*.json') ?: [];
    $now = time();
    $count = 0;
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            // Unreadable — delete if it's older than a week (best-effort cleanup)
            if (@filemtime($file) < $now - 7 * 86400) {
                if (@unlink($file)) $count++;
            }
            continue;
        }
        $entry = json_decode($content, true);
        if (!is_array($entry)) {
            // Malformed — delete unconditionally
            if (@unlink($file)) $count++;
            continue;
        }
        $stored  = (int) ($entry['stored_at']  ?? 0);
        $expires = (int) ($entry['expires_at'] ?? 0);
        if ($beforeTimestamp !== null) {
            if ($stored < $beforeTimestamp) {
                if (@unlink($file)) $count++;
            }
        } else {
            if ($expires < $now) {
                if (@unlink($file)) $count++;
            }
        }
    }
    return $count;
}

/**
 * Nuclear option — delete every entry in the cache dir. Used by
 * cleanResolverCache when `all=true`.
 *
 * @return int Number of entries deleted.
 */
function clearAllResolverCache(): int {
    $dir = getResolverCachePath();
    if (!is_dir($dir)) return 0;
    $files = glob($dir . '/*.json') ?: [];
    $count = 0;
    foreach ($files as $file) {
        if (@unlink($file)) $count++;
    }
    return $count;
}
