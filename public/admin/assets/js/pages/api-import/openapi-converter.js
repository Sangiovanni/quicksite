/**
 * OpenAPI 3.x  →  QuickSite api-endpoints.json converter.
 *
 * Slice 1 — paths × methods → endpoint records, `{x}` → `:x` rewrite,
 *           path/query parameters, slugified IDs with collision suffix.
 * Slice 2 — $ref resolution (local refs), requestBody → requestSchema,
 *           2xx response → responseSchema, allOf inline, oneOf/anyOf skip,
 *           example stripping for credential-named properties, shape
 *           normalisation against QuickSite's validator rules.
 * Slice 3 — securitySchemes → API-level auth (apiKey / http-bearer /
 *           http-basic / oauth2 / openIdConnect / cookie); per-endpoint
 *           inherit/none/required derived from op.security vs global.
 *
 * Exposes window.QSApiImport.{detectOpenApi, convertOpenApi}.
 */
(function () {
    'use strict';

    const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
    const QS_PARAM_TYPES = ['string', 'number', 'integer', 'boolean'];

    // 200 first (the common success), then less common 2xx, then a
    // catch-all "default" response.  Any other 2xx is tried after.
    const PREFERRED_RESPONSE_STATUSES = ['200', '201', '202', '204', 'default'];
    const PREFERRED_CONTENT_TYPES = ['application/json', 'application/x-www-form-urlencoded'];

    // Property names whose example values are likely credentials. Match
    // covers camelCase, snake_case and kebab-case spellings — strip the
    // example, keep the rest of the schema.
    const CREDENTIAL_KEY_RE = /^(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|token|secret|bearer|password|authorization)$/i;

    const MAX_REF_DEPTH = 50;

    /**
     * Returns 'openapi-3' | 'swagger-2' | null. Swagger 2.0 is recognised so
     * the caller can show a "convert to 3.x first" hint instead of failing
     * with the generic "unrecognised format" error.
     */
    function detectOpenApi(data) {
        if (!data || typeof data !== 'object') return null;
        if (typeof data.openapi === 'string' && /^3\./.test(data.openapi)) return 'openapi-3';
        if (data.swagger === '2.0') return 'swagger-2';
        return null;
    }

    /**
     * Slugify for endpoint IDs. Splits camelCase / PascalCase before
     * lowercasing so OpenAPI operationIds like `findPetsByStatus` become
     * `find-pets-by-status` rather than `findpetsbystatus`. Matches the
     * dash-case convention of existing native endpoint IDs.
     */
    function _slugify(str) {
        if (!str) return '';
        return String(str)
            .replace(/([a-z\d])([A-Z])/g, '$1-$2')
            .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function _normaliseType(t) {
        if (typeof t !== 'string') return 'string';
        return QS_PARAM_TYPES.indexOf(t) !== -1 ? t : 'string';
    }

    function _isCredentialKey(name) {
        return CREDENTIAL_KEY_RE.test(String(name || ''));
    }

    /**
     * Resolve a `#/foo/bar` ref against the spec. Returns null for external
     * refs (URLs) or any path that doesn't land on an object.
     */
    function _lookupRef(ref, spec) {
        if (typeof ref !== 'string' || ref.indexOf('#/') !== 0) return null;
        const segs = ref.slice(2).split('/');
        let cur = spec;
        for (const seg of segs) {
            if (!cur || typeof cur !== 'object') return null;
            // OpenAPI ref paths use ~1 for / and ~0 for ~ (RFC 6901)
            const key = seg.replace(/~1/g, '/').replace(/~0/g, '~');
            cur = cur[key];
        }
        return (cur && typeof cur === 'object') ? cur : null;
    }

    /**
     * Merge path-level parameters with operation-level parameters.
     * Per the OpenAPI spec, operation-level wins on (name, in) collision.
     * $ref entries pass through unchanged; the main loop resolves them.
     */
    function _mergeParameters(pathLevel, opLevel) {
        const out = [];
        const seen = Object.create(null);
        for (const p of opLevel) {
            if (!p || typeof p !== 'object') continue;
            if (p.$ref) { out.push(p); continue; }
            if (!p.name || !p.in) continue;
            seen[p.name + '|' + p.in] = true;
            out.push(p);
        }
        for (const p of pathLevel) {
            if (!p || typeof p !== 'object') continue;
            if (p.$ref) { out.push(p); continue; }
            if (!p.name || !p.in) continue;
            if (seen[p.name + '|' + p.in]) continue;
            out.push(p);
        }
        return out;
    }

    /**
     * Build the per-conversion slug-collision map + suffix helper. Each
     * call returns a function that, given a base id, returns either the
     * base id (if free) or `<base>-2`, `<base>-3`, ... (first free slot).
     */
    function _makeSlugAllocator(notes) {
        const taken = Object.create(null);
        return function allocate(baseId) {
            let id = baseId;
            let n = 1;
            while (taken[id]) {
                n += 1;
                id = baseId + '-' + n;
            }
            taken[id] = true;
            if (n > 1) {
                notes.push('Endpoint id auto-suffixed to avoid collision: ' + baseId + ' → ' + id);
            }
            return id;
        };
    }

    function _makeStats() {
        return {
            examplesKept: 0,
            examplesStripped: 0,
            refsExternal: 0,
            refsCycle: 0,
            refsUnresolved: 0,
            compositionsSkipped: 0,
            depthExceeded: 0,
            schemasDropped: 0,
            headerParamsDropped: 0,
            refParamsResolved: 0,
            refParamsUnresolved: 0,
            // {schemeName: opCount} — operations whose security references
            // a scheme other than the one we picked as API-level auth.
            altSchemeUsage: Object.create(null)
        };
    }

    function _summariseStats(stats, notes) {
        if (stats.headerParamsDropped > 0) {
            notes.push(stats.headerParamsDropped + ' header parameter(s) dropped — QuickSite configures headers at the API level (auth.tokenSource: "header:X-Key").');
        }
        if (stats.refParamsResolved > 0) {
            notes.push(stats.refParamsResolved + ' $ref parameter(s) resolved from components.parameters.');
        }
        if (stats.refParamsUnresolved > 0) {
            notes.push(stats.refParamsUnresolved + ' $ref parameter(s) skipped (unresolved or external).');
        }
        if (stats.examplesKept || stats.examplesStripped) {
            const parts = [];
            if (stats.examplesKept) parts.push(stats.examplesKept + ' copied');
            if (stats.examplesStripped) parts.push(stats.examplesStripped + ' stripped as credential-like');
            notes.push('Schema examples: ' + parts.join(', ') + '.');
        }
        if (stats.compositionsSkipped > 0) {
            notes.push(stats.compositionsSkipped + ' schema composition(s) (oneOf/anyOf) skipped — refine manually after import.');
        }
        if (stats.refsExternal > 0) {
            notes.push(stats.refsExternal + ' external $ref(s) skipped (only same-document refs resolved).');
        }
        if (stats.refsCycle > 0) {
            notes.push(stats.refsCycle + ' $ref cycle(s) broken with empty-object placeholder.');
        }
        if (stats.refsUnresolved > 0) {
            notes.push(stats.refsUnresolved + ' $ref(s) could not be resolved (missing target).');
        }
        if (stats.depthExceeded > 0) {
            notes.push(stats.depthExceeded + ' schema branch(es) hit max depth (' + MAX_REF_DEPTH + ').');
        }
        if (stats.schemasDropped > 0) {
            notes.push(stats.schemasDropped + ' schema(s) dropped — failed shape validation (set them manually after import).');
        }
        const altNames = Object.keys(stats.altSchemeUsage);
        if (altNames.length > 0) {
            const parts = altNames.map(n => n + ' (' + stats.altSchemeUsage[n] + ')');
            notes.push('Alternate security schemes used by some endpoints: ' + parts.join(', ') +
                '. Those endpoints set to auth:"required" — refine per-endpoint or switch the API auth in preview.');
        }
    }

    /**
     * Recursively resolve $refs and clean an OpenAPI schema tree into a
     * plain JSON Schema that QuickSite's endpoint editor accepts.
     *
     * Returns the cleaned schema, or `null` when the branch should be
     * dropped (external ref, oneOf/anyOf, max depth, etc.).  `visited` is
     * a Set of ref strings already followed on the current path — cycles
     * are short-circuited to `{type:'object', properties:{}}` so callers
     * get a well-formed placeholder instead of an infinite recursion.
     */
    function _walkSchema(node, spec, visited, depth, stats) {
        if (depth > MAX_REF_DEPTH) { stats.depthExceeded += 1; return null; }
        if (!node || typeof node !== 'object') return node;

        if (typeof node.$ref === 'string') {
            if (node.$ref.indexOf('#/') !== 0) { stats.refsExternal += 1; return null; }
            if (visited.has(node.$ref)) { stats.refsCycle += 1; return { type: 'object', properties: {} }; }
            const target = _lookupRef(node.$ref, spec);
            if (!target) { stats.refsUnresolved += 1; return null; }
            const nextVisited = new Set(visited);
            nextVisited.add(node.$ref);
            return _walkSchema(target, spec, nextVisited, depth + 1, stats);
        }

        if (Array.isArray(node.oneOf) || Array.isArray(node.anyOf)) {
            stats.compositionsSkipped += 1;
            return null;
        }

        if (Array.isArray(node.allOf)) {
            return _mergeAllOf(node, spec, visited, depth, stats);
        }

        const out = {};
        for (const key of Object.keys(node)) {
            // OpenAPI carries xml-binding metadata we don't use.
            if (key === 'xml' || key === '$ref') continue;
            if (key === 'properties' && node.properties && typeof node.properties === 'object'
                    && !Array.isArray(node.properties)) {
                out.properties = _walkProperties(node.properties, spec, visited, depth, stats);
            } else if (key === 'items') {
                const child = _walkSchema(node.items, spec, visited, depth + 1, stats);
                if (child !== null) out.items = child;
            } else if (key === 'additionalProperties' && typeof node.additionalProperties === 'object'
                    && node.additionalProperties !== null && !Array.isArray(node.additionalProperties)) {
                const child = _walkSchema(node.additionalProperties, spec, visited, depth + 1, stats);
                if (child !== null) out.additionalProperties = child;
            } else {
                out[key] = node[key];
            }
        }
        return out;
    }

    function _walkProperties(props, spec, visited, depth, stats) {
        const cleaned = {};
        for (const propName of Object.keys(props)) {
            const child = _walkSchema(props[propName], spec, visited, depth + 1, stats);
            if (child === null) continue;
            if (child && typeof child === 'object' && child.example !== undefined) {
                if (_isCredentialKey(propName)) {
                    const stripped = Object.assign({}, child);
                    delete stripped.example;
                    cleaned[propName] = stripped;
                    stats.examplesStripped += 1;
                } else {
                    cleaned[propName] = child;
                    stats.examplesKept += 1;
                }
            } else {
                cleaned[propName] = child;
            }
        }
        return cleaned;
    }

    /**
     * Merge an allOf composition by overlaying each part's properties +
     * required onto a single object schema. Per JSON Schema, allOf parts
     * are conjuncted — for the common "extends" pattern this approximates
     * to a flat merged shape. Sibling keys on the parent (properties /
     * required co-existing with allOf) are merged on top of the parts.
     */
    function _mergeAllOf(node, spec, visited, depth, stats) {
        const merged = { type: 'object', properties: {} };
        const requiredSet = new Set();

        for (const part of node.allOf) {
            const r = _walkSchema(part, spec, visited, depth + 1, stats);
            if (!r || typeof r !== 'object') continue;
            if (r.type && merged.type === 'object' && r.type !== 'object') merged.type = r.type;
            if (r.properties && typeof r.properties === 'object') {
                Object.assign(merged.properties, r.properties);
            }
            if (Array.isArray(r.required)) {
                for (const k of r.required) requiredSet.add(k);
            }
        }

        if (node.properties && typeof node.properties === 'object' && !Array.isArray(node.properties)) {
            const own = _walkProperties(node.properties, spec, visited, depth, stats);
            Object.assign(merged.properties, own);
        }
        if (Array.isArray(node.required)) {
            for (const k of node.required) requiredSet.add(k);
        }
        if (requiredSet.size) merged.required = Array.from(requiredSet);

        return merged;
    }

    /**
     * Ensure the schema satisfies QuickSite's `_validateSchemaShape`
     * rules (top-level `type`, and `properties` for object types).  Adds
     * missing `properties:{}` rather than dropping; only returns null
     * when `type` is genuinely unrecoverable.
     */
    function _normaliseSchema(schema) {
        if (!schema || typeof schema !== 'object' || Array.isArray(schema)) return null;
        if (!schema.type) {
            if (schema.properties) schema.type = 'object';
            else if (schema.items) schema.type = 'array';
            else return null;
        }
        if (schema.type === 'object' && (!schema.properties || typeof schema.properties !== 'object'
                || Array.isArray(schema.properties))) {
            schema.properties = {};
        }
        return schema;
    }

    function _pickContentSchema(content, spec, stats) {
        if (!content || typeof content !== 'object') return null;
        let chosen = null;
        for (const t of PREFERRED_CONTENT_TYPES) {
            if (content[t] && content[t].schema) { chosen = content[t].schema; break; }
        }
        if (!chosen) {
            for (const key of Object.keys(content)) {
                if (content[key] && content[key].schema) { chosen = content[key].schema; break; }
            }
        }
        if (!chosen) return null;
        return _walkSchema(chosen, spec, new Set(), 0, stats);
    }

    function _pickRequestSchema(op, spec, stats) {
        if (!op.requestBody || typeof op.requestBody !== 'object') return null;
        return _pickContentSchema(op.requestBody.content, spec, stats);
    }

    function _pickResponseSchema(op, spec, stats) {
        const responses = op.responses;
        if (!responses || typeof responses !== 'object') return null;
        let chosen = null;
        for (const s of PREFERRED_RESPONSE_STATUSES) {
            const r = responses[s];
            if (r && r.content) { chosen = r; break; }
        }
        if (!chosen) {
            for (const key of Object.keys(responses)) {
                if (/^2\d\d$/.test(key) && responses[key] && responses[key].content) {
                    chosen = responses[key];
                    break;
                }
            }
        }
        if (!chosen) return null;
        return _pickContentSchema(chosen.content, spec, stats);
    }

    /**
     * Translate one OpenAPI securityScheme entry into a QuickSite auth
     * object. Returns null when the scheme isn't mappable. Side-effect:
     * pushes a clarifying note for cookie/oauth2/openIdConnect/query
     * variants (the runtime + editor have known nuances for each).
     */
    function _mapSecurityScheme(scheme, schemeName, notes) {
        if (!scheme || typeof scheme !== 'object') return null;
        const t = scheme.type;

        if (t === 'apiKey') {
            const keyName = String(scheme.name || 'API-Key');
            const where = String(scheme.in || 'header').toLowerCase();
            if (where === 'cookie') {
                // Q5 lock — best-effort map + warning. QuickSite's cookie
                // auth assumes Pattern X (same-origin session cookie owned
                // by the browser); not all "cookie security" specs match.
                notes.push('Security: scheme "' + schemeName + '" is apiKey-in-cookie (cookie: "' + keyName +
                    '") — mapped to QuickSite cookie auth. Verify your server session cookie matches Pattern X before going live.');
                return { type: 'cookie' };
            }
            if (where === 'query') {
                notes.push('Security: scheme "' + schemeName + '" is apiKey-in-query (param: "' + keyName +
                    '"). Verify QuickSite query-string token delivery after import.');
                return { type: 'apiKey', tokenSource: 'query:' + keyName };
            }
            return { type: 'apiKey', tokenSource: 'header:' + keyName };
        }

        if (t === 'http') {
            const sch = String(scheme.scheme || '').toLowerCase();
            if (sch === 'bearer') return { type: 'bearer', tokenSource: 'localStorage:token' };
            if (sch === 'basic') return { type: 'basic', tokenSource: 'localStorage:basicAuth' };
            return null;
        }

        if (t === 'oauth2') {
            notes.push('Security: scheme "' + schemeName + '" is oauth2 — mapped to bearer (oauth2 yields a bearer token at runtime). Configure the OAuth handler separately on the API.');
            return { type: 'bearer', tokenSource: 'localStorage:token' };
        }

        if (t === 'openIdConnect') {
            notes.push('Security: scheme "' + schemeName + '" is openIdConnect — mapped to bearer auth. Verify token source after import.');
            return { type: 'bearer', tokenSource: 'localStorage:token' };
        }

        return null;
    }

    /**
     * Decide which security scheme becomes the API-level auth. Tallies
     * scheme usage across global + per-operation security; global counts
     * heavily (×100) so an explicit spec-level default beats any per-op
     * pattern. First-mappable wins. Returns { auth, pickedName } where
     * `pickedName` is null when nothing was mappable.
     */
    function _pickApiAuth(spec, notes) {
        const securitySchemes = (spec && spec.components && spec.components.securitySchemes) || {};
        const schemeNames = Object.keys(securitySchemes);
        if (schemeNames.length === 0) return { auth: { type: 'none' }, pickedName: null };

        const usage = Object.create(null);
        for (const name of schemeNames) usage[name] = 0;

        if (Array.isArray(spec.security)) {
            for (const req of spec.security) {
                if (!req || typeof req !== 'object') continue;
                for (const name of Object.keys(req)) {
                    if (name in usage) usage[name] += 100;
                }
            }
        }
        const paths = (spec.paths && typeof spec.paths === 'object') ? spec.paths : {};
        for (const p of Object.values(paths)) {
            if (!p || typeof p !== 'object') continue;
            for (const method of HTTP_METHODS) {
                const op = p[method];
                if (!op || !Array.isArray(op.security)) continue;
                for (const req of op.security) {
                    if (!req || typeof req !== 'object') continue;
                    for (const name of Object.keys(req)) {
                        if (name in usage) usage[name] += 1;
                    }
                }
            }
        }

        // Stable-sort: higher usage first; declaration order on tie.
        const sorted = schemeNames.slice().sort((a, b) => (usage[b] - usage[a]) ||
            (schemeNames.indexOf(a) - schemeNames.indexOf(b)));

        for (const name of sorted) {
            const mapped = _mapSecurityScheme(securitySchemes[name], name, notes);
            if (mapped) {
                if (schemeNames.length > 1) {
                    notes.push('Security: API declares ' + schemeNames.length + ' schemes (' +
                        schemeNames.join(', ') + '); using "' + name + '". Edit auth in preview to switch.');
                }
                return { auth: mapped, pickedName: name };
            }
        }

        notes.push('Security: no scheme could be mapped. API set to public — configure auth manually after import.');
        return { auth: { type: 'none' }, pickedName: null };
    }

    /**
     * Decide an endpoint's `auth` literal ('inherit' | 'none' | 'required').
     *  - Op security `[]` → 'none' (explicit public override).
     *  - Otherwise, effective = op.security ?? spec.security ?? [].
     *  - Effective empty → 'none' (OpenAPI default = public).
     *  - Effective references the picked scheme → 'inherit'.
     *  - Effective uses different scheme(s) → 'required', plus track usage
     *    so the preview can call out which alternate schemes were seen.
     */
    function _resolveEndpointAuth(op, spec, pickedSchemeName, stats) {
        let effective;
        if (Array.isArray(op.security)) effective = op.security;
        else if (Array.isArray(spec.security)) effective = spec.security;
        else effective = [];

        if (effective.length === 0) return 'none';

        if (pickedSchemeName) {
            for (const req of effective) {
                if (req && typeof req === 'object'
                    && Object.prototype.hasOwnProperty.call(req, pickedSchemeName)) {
                    return 'inherit';
                }
            }
        }

        for (const req of effective) {
            if (!req || typeof req !== 'object') continue;
            for (const name of Object.keys(req)) {
                if (name === pickedSchemeName) continue;
                stats.altSchemeUsage[name] = (stats.altSchemeUsage[name] || 0) + 1;
            }
        }
        return 'required';
    }

    /**
     * Main entry. Returns { converted, notes } where `converted` matches
     * the native import shape ({ apis: { <apiId>: {...} } }) and `notes`
     * is a list of human-readable strings the preview can surface.
     */
    function convertOpenApi(spec) {
        const notes = [];
        const stats = _makeStats();

        const info = (spec && spec.info) || {};
        const apiTitle = String(info.title || 'Imported API').trim() || 'Imported API';
        const apiId = _slugify(apiTitle) || 'imported-api';

        const servers = Array.isArray(spec && spec.servers) ? spec.servers : [];
        const baseUrl = (servers[0] && typeof servers[0].url === 'string') ? servers[0].url : '/';
        if (servers.length > 1) {
            notes.push(servers.length + ' server URLs declared; using first ("' + baseUrl + '"). Edit in preview to switch.');
        }
        if (baseUrl && baseUrl.charAt(0) === '/') {
            notes.push('Base URL is relative ("' + baseUrl + '"). Set an absolute URL in the "Base URL" field above the JSON preview before importing.');
        }

        const allocateId = _makeSlugAllocator(notes);
        const endpoints = [];

        const { auth: apiAuth, pickedName: pickedSchemeName } = _pickApiAuth(spec, notes);

        const paths = (spec && spec.paths && typeof spec.paths === 'object') ? spec.paths : {};
        for (const rawPath of Object.keys(paths)) {
            const pathItem = paths[rawPath];
            if (!pathItem || typeof pathItem !== 'object') continue;

            const pathLevelParams = Array.isArray(pathItem.parameters) ? pathItem.parameters : [];

            for (const method of HTTP_METHODS) {
                const op = pathItem[method];
                if (!op || typeof op !== 'object') continue;

                const opParams = Array.isArray(op.parameters) ? op.parameters : [];
                const merged = _mergeParameters(pathLevelParams, opParams);

                const qsPath = rawPath.replace(/\{([^}]+)\}/g, ':$1');

                let baseId;
                if (typeof op.operationId === 'string' && op.operationId.trim()) {
                    baseId = _slugify(op.operationId);
                } else {
                    baseId = method + '-' + (_slugify(rawPath) || 'root');
                }
                if (!baseId) baseId = method + '-endpoint';
                const endpointId = allocateId(baseId);

                const qsParameters = [];
                for (const raw of merged) {
                    let p = raw;
                    if (p.$ref) {
                        const target = _lookupRef(p.$ref, spec);
                        if (!target || !target.name || !target.in) {
                            stats.refParamsUnresolved += 1;
                            continue;
                        }
                        stats.refParamsResolved += 1;
                        p = target;
                    }
                    if (p.in === 'path' || p.in === 'query') {
                        const schemaType = (p.schema && p.schema.type) || p.type || 'string';
                        qsParameters.push({
                            name: String(p.name),
                            type: _normaliseType(schemaType),
                            required: !!p.required
                        });
                    } else if (p.in === 'header') {
                        stats.headerParamsDropped += 1;
                    }
                }

                const endpoint = {
                    id: endpointId,
                    method: method.toUpperCase(),
                    name: op.summary || op.operationId || (method.toUpperCase() + ' ' + rawPath),
                    path: qsPath,
                    auth: _resolveEndpointAuth(op, spec, pickedSchemeName, stats)
                };
                const desc = op.description || op.summary || '';
                if (desc) endpoint.description = desc;
                if (qsParameters.length) endpoint.parameters = qsParameters;

                const reqWalked = _pickRequestSchema(op, spec, stats);
                if (reqWalked) {
                    const finalReq = _normaliseSchema(reqWalked);
                    if (finalReq) endpoint.requestSchema = finalReq;
                    else stats.schemasDropped += 1;
                }
                const respWalked = _pickResponseSchema(op, spec, stats);
                if (respWalked) {
                    const finalResp = _normaliseSchema(respWalked);
                    if (finalResp) endpoint.responseSchema = finalResp;
                    else stats.schemasDropped += 1;
                }

                endpoints.push(endpoint);
            }
        }

        _summariseStats(stats, notes);

        const converted = {
            apis: {
                [apiId]: {
                    name: apiTitle,
                    description: String(info.description || '').trim(),
                    baseUrl: baseUrl,
                    auth: apiAuth,
                    endpoints: endpoints
                }
            }
        };

        return { converted: converted, notes: notes };
    }

    window.QSApiImport = window.QSApiImport || {};
    window.QSApiImport.detectOpenApi = detectOpenApi;
    window.QSApiImport.convertOpenApi = convertOpenApi;
})();
