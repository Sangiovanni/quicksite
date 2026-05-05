/**
 * QuickSite Admin — Connections Store
 *
 * Source of truth for AI connections in `localStorage` / `sessionStorage`.
 * Replaces the v2 `aiKeysV2` map (1 entry per provider id) with a list of
 * named connections that can be cloud (BYOK) or local. Each connection
 * stores its own endpoint, auth, model selection and streaming flag.
 *
 * No DOM, no fetch — pure CRUD over a JSON blob. Caller decides which
 * storage (local vs session) to read/write based on the persist toggle.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    const KEY = QuickSiteStorageKeys.aiConnectionsV3;
    const CURRENT_VERSION = 3;

    // ---------- internals ----------

    function pickStorage() {
        // Cloud connections respect the existing aiPersist toggle.
        // Local connections (no key) always use localStorage — URLs aren't sensitive.
        // We pick storage at write time per-store; readers try both and prefer
        // whichever has the higher version timestamp.
        const persist = localStorage.getItem(QuickSiteStorageKeys.aiPersist) === 'true';
        return persist ? localStorage : sessionStorage;
    }

    function emptyStore() {
        return { version: CURRENT_VERSION, connections: [], defaultConnectionId: null };
    }

    function generateId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'c_' + window.crypto.randomUUID().replace(/-/g, '').slice(0, 20);
        }
        return 'c_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    function readRaw(storage) {
        try {
            const raw = storage.getItem(KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || parsed.version !== CURRENT_VERSION) return null;
            if (!Array.isArray(parsed.connections)) return null;
            return parsed;
        } catch (_e) { return null; }
    }

    // ---------- public API ----------

    function loadStore() {
        // Prefer localStorage (default, persistent), fall back to sessionStorage.
        return readRaw(localStorage) || readRaw(sessionStorage) || emptyStore();
    }

    function saveStore(store) {
        if (!store || store.version !== CURRENT_VERSION) {
            throw new Error('saveStore: invalid store version');
        }
        const target = pickStorage();
        const other = target === localStorage ? sessionStorage : localStorage;
        target.setItem(KEY, JSON.stringify(store));
        // Avoid stale duplicate in the other storage.
        try { other.removeItem(KEY); } catch (_e) { /* noop */ }
        return store;
    }

    function addConnection(partial) {
        const store = loadStore();
        const conn = {
            id: partial.id || generateId(),
            name: partial.name || 'Untitled',
            type: partial.type || 'cloud',
            providerType: partial.providerType,
            baseUrl: partial.baseUrl || null,
            key: partial.key || null,
            extraHeaders: partial.extraHeaders || null,
            models: partial.models || [],
            enabledModels: partial.enabledModels || partial.models || [],
            defaultModel: partial.defaultModel || (partial.models && partial.models[0]) || null,
            streaming: partial.streaming !== false,
            // _preset is the local-runtime id (ollama / lm-studio / null) so
            // the card and CORS hint can pick the right logo / instructions.
            _preset: partial._preset || null,
            createdAt: Date.now(),
            lastUsedAt: null,
            lastStatus: null
        };
        store.connections.push(conn);
        if (!store.defaultConnectionId) store.defaultConnectionId = conn.id;
        saveStore(store);
        return conn;
    }

    function updateConnection(id, patch) {
        const store = loadStore();
        const idx = store.connections.findIndex((c) => c.id === id);
        if (idx === -1) return null;
        store.connections[idx] = Object.assign({}, store.connections[idx], patch, { id });
        saveStore(store);
        return store.connections[idx];
    }

    function removeConnection(id) {
        const store = loadStore();
        const before = store.connections.length;
        store.connections = store.connections.filter((c) => c.id !== id);
        if (store.connections.length === before) return false;
        if (store.defaultConnectionId === id) {
            store.defaultConnectionId = store.connections[0] ? store.connections[0].id : null;
        }
        saveStore(store);
        return true;
    }

    function setDefault(id) {
        const store = loadStore();
        if (!store.connections.find((c) => c.id === id)) return null;
        store.defaultConnectionId = id;
        saveStore(store);
        return id;
    }

    function getActive() {
        const store = loadStore();
        if (!store.defaultConnectionId) return null;
        return store.connections.find((c) => c.id === store.defaultConnectionId) || null;
    }

    function getConnection(id) {
        const store = loadStore();
        return store.connections.find((c) => c.id === id) || null;
    }

    function recordStatus(id, status) {
        return updateConnection(id, {
            lastStatus: { ok: !!status.ok, at: Date.now(), message: status.message || null },
            lastUsedAt: status.used ? Date.now() : (getConnection(id) || {}).lastUsedAt || null
        });
    }

    /**
     * One-shot migration from v2 (`aiKeysV2`).
     * Idempotent: returns the existing v3 store unchanged if any v3 record
     * already exists. Reads the v2 blob from whichever storage holds it.
     *
     * @returns {{store: object, migrated: number, alreadyMigrated: boolean}}
     */
    function migrateFromV2() {
        const existing = readRaw(localStorage) || readRaw(sessionStorage);
        if (existing && existing.connections.length > 0) {
            return { store: existing, migrated: 0, alreadyMigrated: true };
        }
        const persist = localStorage.getItem(QuickSiteStorageKeys.aiPersist) === 'true';
        const v2Storage = persist ? localStorage : sessionStorage;
        let v2Raw = v2Storage.getItem(QuickSiteStorageKeys.aiKeysV2);
        if (!v2Raw) {
            // Try the other storage as a fallback.
            const other = v2Storage === localStorage ? sessionStorage : localStorage;
            v2Raw = other.getItem(QuickSiteStorageKeys.aiKeysV2);
        }
        if (!v2Raw) {
            const empty = emptyStore();
            saveStore(empty);
            return { store: empty, migrated: 0, alreadyMigrated: false };
        }
        let v2;
        try { v2 = JSON.parse(v2Raw); } catch (_e) { v2 = null; }
        if (!v2 || typeof v2 !== 'object') {
            const empty = emptyStore();
            saveStore(empty);
            return { store: empty, migrated: 0, alreadyMigrated: false };
        }

        const store = emptyStore();
        const defaultProvider = v2Storage.getItem(QuickSiteStorageKeys.aiDefaultProvider)
            || localStorage.getItem(QuickSiteStorageKeys.aiDefaultProvider);

        Object.keys(v2).forEach((providerId) => {
            const entry = v2[providerId] || {};
            const conn = {
                id: generateId(),
                name: entry.name || providerLabel(providerId),
                type: 'cloud',
                providerType: providerId,
                baseUrl: null,
                key: entry.key || null,
                extraHeaders: null,
                models: Array.isArray(entry.models) ? entry.models : [],
                enabledModels: Array.isArray(entry.models) ? entry.models : [],
                defaultModel: entry.defaultModel || (entry.models && entry.models[0]) || null,
                streaming: true,
                createdAt: Date.now(),
                lastUsedAt: null,
                lastStatus: null,
                _migratedFrom: 'v2:' + providerId
            };
            store.connections.push(conn);
            if (providerId === defaultProvider) store.defaultConnectionId = conn.id;
        });
        if (!store.defaultConnectionId && store.connections[0]) {
            store.defaultConnectionId = store.connections[0].id;
        }
        saveStore(store);
        return { store, migrated: store.connections.length, alreadyMigrated: false };
    }

    function providerLabel(id) {
        const map = { openai: 'OpenAI', anthropic: 'Anthropic', google: 'Google AI', mistral: 'Mistral AI' };
        return map[id] || id;
    }

    window.QSConnectionsStore = Object.freeze({
        KEY,
        CURRENT_VERSION,
        loadStore,
        saveStore,
        addConnection,
        updateConnection,
        removeConnection,
        setDefault,
        getActive,
        getConnection,
        recordStatus,
        migrateFromV2,
        _generateId: generateId
    });
})();
