/**
 * One test response → a DRAFT response schema, for the author to review. Never a saved schema by itself.
 *
 * A response schema is public (it is compiled into the client's config) and a test response may not be,
 * so the draft holds types and names and never a value: no example, no default, no enum, no format.
 * A response is also untrusted input: every walk here is bounded by a limit named below, and a key
 * is only ever read as a string into a Map, so no key can reach an object's prototype.
 *
 * The body comes through testApiEndpoint, which decodes it into PHP arrays: an empty object and an
 * empty list arrive alike, so an empty value is reported as empty and never as one or the other.
 *
 * The shape is what the schema's readers walk: `properties` of a `type: "object"` and the `items`
 * of a `type: "array"`. They compare `type` to "object" exactly, so a null beside a known type stays
 * out of `type` and is reported instead. `required` is never written: one response cannot tell.
 *
 * Exposes window.QSApiImport.draftResponseSchema and window.QSApiImport.DRAFT_LIMITS.
 * This file holds no words: the page says what each list of paths means.
 */
(function () {
    'use strict';

    /** How deep the draft reads. What lies below is reported, not read. */
    const DRAFT_MAX_DEPTH = 16;
    /** How many elements of one list the draft reads. */
    const DRAFT_MAX_ITEMS = 100;
    /** An object with more fields than this is keyed by data, whatever its names look like. */
    const DRAFT_MAX_FIELDS = 100;
    /** A longer field name is data: no schema author writes one. */
    const DRAFT_MAX_NAME = 64;

    /**
     * Names that name part of a JavaScript object. A schema holding one compiles into the client's
     * config as an object literal, where such a name sets the object's prototype instead of a field,
     * and the field pickers cannot offer it. They are left out and reported.
     */
    const UNSAFE_NAMES = ['__proto__', 'constructor', 'prototype'];

    const NAME_SHAPE = /^[\p{L}_$][\p{L}\p{N}_$-]*$/u;
    const UUID_SHAPE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    const HEX_SHAPE = /^[0-9a-f]{8,}$/i;
    const TOKEN_SHAPE = /^[A-Za-z0-9_-]{24,}$/;

    /**
     * Does this field name look like data rather than a name somebody chose: an id, an address, a
     * date, a token? Such a name stays out of the draft, because a saved schema would publish it.
     */
    function _nameIsData(name) {
        if (name.length > DRAFT_MAX_NAME || !NAME_SHAPE.test(name) || UUID_SHAPE.test(name)) return true;
        const hasDigit = /\d/.test(name);
        return hasDigit && (HEX_SHAPE.test(name) || TOKEN_SHAPE.test(name));
    }

    function _newShape() {
        return {
            kinds: new Set(),
            emptied: false,      // an empty object or list: which one, the body cannot say
            objects: 0,          // how many objects were read into this shape
            fields: new Map(),   // name → { shape, seen }
            values: null,        // the shape of the values under names that are data
            item: null,          // the shape of a list's elements
        };
    }

    /** What one value adds to a shape. `path` is what the author reads; data never enters it. */
    function _read(value, shape, path, depth, out) {
        if (value === null) {
            shape.kinds.add('null');
            return;
        }
        if (Array.isArray(value)) {
            if (value.length === 0) {
                shape.kinds.add('array');
                shape.emptied = true;
                return;
            }
            shape.kinds.add('array');
            if (depth >= DRAFT_MAX_DEPTH) {
                out.deep.add(path);
                return;
            }
            if (value.length > DRAFT_MAX_ITEMS) out.sampled.add(path);
            if (!shape.item) shape.item = _newShape();
            const itemPath = path + '[]';
            for (let i = 0; i < value.length && i < DRAFT_MAX_ITEMS; i++) {
                _read(value[i], shape.item, itemPath, depth + 1, out);
            }
            return;
        }
        if (typeof value === 'object') {
            shape.kinds.add('object');
            if (depth >= DRAFT_MAX_DEPTH) {
                out.deep.add(path);
                return;
            }
            shape.objects++;
            const names = Object.keys(value);
            const allData = names.length > DRAFT_MAX_FIELDS;
            let dataRead = 0;
            for (const name of names) {
                if (UNSAFE_NAMES.includes(name)) {
                    out.unsafe.add(path === '' ? name : path + '.' + name);
                    continue;
                }
                if (allData || _nameIsData(name)) {
                    out.map.add(path);
                    if (dataRead++ < DRAFT_MAX_ITEMS) {
                        if (!shape.values) shape.values = _newShape();
                        _read(value[name], shape.values, (path === '' ? '' : path + '.') + '*', depth + 1, out);
                    }
                    continue;
                }
                let field = shape.fields.get(name);
                if (!field) {
                    field = { shape: _newShape(), seen: 0 };
                    shape.fields.set(name, field);
                }
                field.seen++;
                _read(value[name], field.shape, path === '' ? name : path + '.' + name, depth + 1, out);
            }
            return;
        }
        if (typeof value === 'number') {
            shape.kinds.add(Number.isInteger(value) ? 'integer' : 'number');
            return;
        }
        shape.kinds.add(typeof value === 'boolean' ? 'boolean' : 'string');
    }

    /** One shape as a schema node, its paths reported as it goes. */
    function _write(shape, path, out) {
        const kinds = new Set(shape.kinds);
        const sawNull = kinds.delete('null');
        if (kinds.has('number')) kinds.delete('integer');
        if (shape.emptied) out.empty.add(path);

        const node = {};
        if (kinds.size === 0) {
            node.type = 'null';
            out.nullOnly.add(path);
            return node;
        }
        if (sawNull) out.sometimesNull.add(path);
        if (kinds.size === 1) {
            node.type = kinds.values().next().value;
        } else {
            node.type = Array.from(kinds).sort();
            out.mixed.add(path);
        }
        if (shape.fields.size > 0) {
            node.properties = {};
            for (const [name, field] of shape.fields) {
                const fieldPath = path === '' ? name : path + '.' + name;
                if (field.seen < shape.objects) out.sometimesAbsent.add(fieldPath);
                node.properties[name] = _write(field.shape, fieldPath, out);
            }
        }
        if (shape.values) {
            node.additionalProperties = _write(shape.values, (path === '' ? '' : path + '.') + '*', out);
        }
        if (shape.item) {
            node.items = _write(shape.item, path + '[]', out);
        }
        return node;
    }

    /**
     * The draft for one decoded JSON body.
     *
     * @param {*} body response.body of a test that answered JSON
     * @returns {{schema: ?Object, root: string, notes: Object<string, string[]>}} schema is null when
     *   the body has nothing to draft from: a single value, null, an empty object or list, or only
     *   names left out. root is 'object' (named fields at the top), 'map' (only data for names),
     *   'array' or 'none'. notes holds, per reason, the paths the draft could not determine;
     *   '' is the top of the response.
     */
    function draftResponseSchema(body) {
        const out = {
            empty: new Set(), nullOnly: new Set(), sometimesNull: new Set(), sometimesAbsent: new Set(),
            mixed: new Set(), map: new Set(), unsafe: new Set(), sampled: new Set(), deep: new Set(),
        };
        const shape = _newShape();
        _read(body, shape, '', 0, out);

        const isArray = shape.kinds.has('array') && !shape.emptied;
        const isObject = shape.kinds.has('object') && (shape.fields.size > 0 || shape.values !== null);
        let schema = null;
        let root = 'none';
        if (isArray || isObject) {
            schema = _write(shape, '', out);
            root = isArray ? 'array' : (shape.fields.size > 0 ? 'object' : 'map');
            // The form's own check asks an object for `properties`, even an empty one.
            if (root === 'map') schema.properties = {};
        }

        const notes = {};
        for (const reason of Object.keys(out)) notes[reason] = Array.from(out[reason]);
        return { schema, root, notes };
    }

    window.QSApiImport = window.QSApiImport || {};
    window.QSApiImport.draftResponseSchema = draftResponseSchema;
    window.QSApiImport.DRAFT_LIMITS = Object.freeze({
        depth: DRAFT_MAX_DEPTH, items: DRAFT_MAX_ITEMS, fields: DRAFT_MAX_FIELDS, name: DRAFT_MAX_NAME,
    });
})();
