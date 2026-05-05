/**
 * QuickSite Admin — AI Provider Catalog (browser-side)
 *
 * Pure data + per-provider helpers (URL/headers/body/response parsing).
 * Port of `secure/management/config/ai_providers.php` + the per-provider
 * methods of `AiProviderManager.php`, with `openai-compatible` added for
 * Local AI (Ollama, LM Studio, LiteLLM, vLLM…).
 *
 * No DOM, no fetch — caller (ai-call.js) drives the HTTP. This keeps the
 * file trivially testable in the browser console.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    const PROVIDERS = {
        openai: {
            id: 'openai',
            name: 'OpenAI',
            prefixes: ['sk-proj-', 'sk-'],
            endpoint: 'https://api.openai.com/v1/chat/completions',
            modelsEndpoint: 'https://api.openai.com/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: ['gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
            requestFormat: 'openai',
            keyUrl: 'https://platform.openai.com/api-keys'
        },
        anthropic: {
            id: 'anthropic',
            name: 'Anthropic',
            prefixes: ['sk-ant-'],
            endpoint: 'https://api.anthropic.com/v1/messages',
            modelsEndpoint: null,
            authHeader: 'x-api-key',
            authFormat: '{key}',
            extraHeaders: {
                'anthropic-version': '2023-06-01',
                'anthropic-dangerous-direct-browser-access': 'true'
            },
            defaultModels: [
                'claude-opus-4-20250514',
                'claude-sonnet-4-20250514',
                'claude-3-5-sonnet-latest',
                'claude-3-5-haiku-latest',
                'claude-3-opus-latest',
                'claude-3-haiku-20240307'
            ],
            requestFormat: 'anthropic',
            keyUrl: 'https://console.anthropic.com/settings/keys'
        },
        google: {
            id: 'google',
            name: 'Google AI',
            prefixes: ['AIza'],
            endpoint: 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            modelsEndpoint: 'https://generativelanguage.googleapis.com/v1beta/models',
            authHeader: null,           // uses ?key= query param
            authQueryParam: 'key',
            authFormat: '{key}',
            extraHeaders: {},
            defaultModels: [
                'gemini-1.5-flash',
                'gemini-1.5-pro',
                'gemini-2.0-flash',
                'gemini-2.5-pro-preview-06-05'
            ],
            requestFormat: 'google',
            keyUrl: 'https://aistudio.google.com/apikey'
        },
        mistral: {
            id: 'mistral',
            name: 'Mistral AI',
            prefixes: [],
            endpoint: 'https://api.mistral.ai/v1/chat/completions',
            modelsEndpoint: 'https://api.mistral.ai/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest', 'codestral-latest'],
            requestFormat: 'openai',
            keyUrl: 'https://console.mistral.ai/api-keys'
        },
        deepseek: {
            id: 'deepseek',
            name: 'DeepSeek',
            prefixes: [],
            endpoint: 'https://api.deepseek.com/v1/chat/completions',
            modelsEndpoint: 'https://api.deepseek.com/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: ['deepseek-chat', 'deepseek-reasoner'],
            requestFormat: 'openai',
            keyUrl: 'https://platform.deepseek.com/api_keys'
        },
        groq: {
            id: 'groq',
            name: 'Groq',
            prefixes: ['gsk_'],
            endpoint: 'https://api.groq.com/openai/v1/chat/completions',
            modelsEndpoint: 'https://api.groq.com/openai/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
            requestFormat: 'openai',
            keyUrl: 'https://console.groq.com/keys'
        },
        xai: {
            id: 'xai',
            name: 'xAI (Grok)',
            prefixes: ['xai-'],
            endpoint: 'https://api.x.ai/v1/chat/completions',
            modelsEndpoint: 'https://api.x.ai/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: ['grok-2-latest', 'grok-2-mini'],
            requestFormat: 'openai',
            keyUrl: 'https://console.x.ai/'
        },
        openrouter: {
            id: 'openrouter',
            name: 'OpenRouter',
            prefixes: ['sk-or-'],
            endpoint: 'https://openrouter.ai/api/v1/chat/completions',
            modelsEndpoint: 'https://openrouter.ai/api/v1/models',
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {
                // OpenRouter recommends these for browser apps; harmless to send.
                'HTTP-Referer': (typeof window !== 'undefined' && window.location ? window.location.origin : ''),
                'X-Title': 'QuickSite'
            },
            defaultModels: ['openrouter/auto', 'anthropic/claude-3.5-sonnet', 'openai/gpt-4o', 'meta-llama/llama-3.3-70b-instruct'],
            requestFormat: 'openai',
            keyUrl: 'https://openrouter.ai/keys'
        },
        // OpenAI-shape but the endpoint URL comes from the connection (Ollama,
        // LM Studio, LiteLLM, vLLM, Together, Groq, Fireworks, …). The local
        // presets in local-presets.js prefill `baseUrl` for common deployments.
        'openai-compatible': {
            id: 'openai-compatible',
            name: 'OpenAI-compatible',
            prefixes: [],
            endpoint: null,             // resolved from connection.baseUrl
            modelsEndpoint: null,       // resolved from connection.baseUrl
            authHeader: 'Authorization',
            authFormat: 'Bearer {key}',
            extraHeaders: {},
            defaultModels: [],
            requestFormat: 'openai',
            keyUrl: null
        }
    };

    // ---------- detection ----------

    /** Detect provider from key prefix (longest-match wins). */
    function detectProvider(key) {
        if (!key) return null;
        const all = [];
        Object.values(PROVIDERS).forEach((p) => {
            (p.prefixes || []).forEach((prefix) => all.push({ prefix, id: p.id, name: p.name }));
        });
        all.sort((a, b) => b.prefix.length - a.prefix.length);
        for (const e of all) {
            if (key.startsWith(e.prefix)) {
                return { detected: true, provider: e.id, name: e.name, method: 'prefix' };
            }
        }
        return { detected: false, provider: null, method: 'none' };
    }

    function get(providerType) {
        return PROVIDERS[providerType] || null;
    }

    // ---------- URL / headers ----------

    /**
     * Resolve the chat-completion URL for a given connection + model.
     * `connection.baseUrl` overrides the catalog endpoint when provided
     * (required for openai-compatible / local connections).
     */
    function buildUrl(connection, model) {
        const p = get(connection.providerType);
        if (!p) throw new Error('Unknown providerType: ' + connection.providerType);

        let url = connection.baseUrl
            ? joinPath(connection.baseUrl, defaultPathFor(p.requestFormat))
            : p.endpoint;
        if (!url) throw new Error('No endpoint configured for ' + connection.providerType);

        if (p.requestFormat === 'google') {
            url = url.replace('{model}', encodeURIComponent(model));
            url += (url.includes('?') ? '&' : '?') + p.authQueryParam + '=' + encodeURIComponent(connection.key || '');
        }
        return url;
    }

    function buildModelsUrl(connection) {
        const p = get(connection.providerType);
        if (!p) return null;
        if (connection.baseUrl) {
            // openai-shape: /models is the convention
            return joinPath(connection.baseUrl, '/models');
        }
        if (!p.modelsEndpoint) return null;
        if (p.requestFormat === 'google') {
            return p.modelsEndpoint + '?' + p.authQueryParam + '=' + encodeURIComponent(connection.key || '');
        }
        return p.modelsEndpoint;
    }

    function buildHeaders(connection) {
        const p = get(connection.providerType);
        const headers = { 'Content-Type': 'application/json' };
        if (p && p.authHeader && connection.key) {
            headers[p.authHeader] = p.authFormat.replace('{key}', connection.key);
        }
        if (p && p.extraHeaders) Object.assign(headers, p.extraHeaders);
        if (connection.extraHeaders) Object.assign(headers, connection.extraHeaders);
        return headers;
    }

    // ---------- request body ----------

    function buildBody(connection, model, messages, options, opts) {
        const p = get(connection.providerType);
        const maxTokens = (options && options.max_tokens) || 8192;
        const temperature = options && options.temperature !== undefined ? options.temperature : 0.7;
        const stream = !!(opts && opts.stream);

        switch (p.requestFormat) {
            case 'anthropic': {
                let system = null;
                const chat = [];
                messages.forEach((m) => {
                    if (m.role === 'system') system = m.content;
                    else chat.push({ role: m.role, content: m.content });
                });
                const body = { model, max_tokens: maxTokens, messages: chat, temperature };
                if (system) body.system = system;
                if (stream) body.stream = true;
                return body;
            }
            case 'google': {
                const contents = [];
                let systemInstruction = null;
                messages.forEach((m) => {
                    if (m.role === 'system') {
                        systemInstruction = { parts: [{ text: m.content }] };
                    } else {
                        contents.push({
                            role: m.role === 'assistant' ? 'model' : 'user',
                            parts: [{ text: m.content }]
                        });
                    }
                });
                const body = {
                    contents,
                    generationConfig: { maxOutputTokens: maxTokens, temperature }
                };
                if (systemInstruction) body.systemInstruction = systemInstruction;
                // Google streaming uses :streamGenerateContent endpoint, not a body flag.
                return body;
            }
            default: {
                const body = { model, max_tokens: maxTokens, temperature, messages };
                if (stream) body.stream = true;
                return body;
            }
        }
    }

    // ---------- response parsing ----------

    function parseResponse(body, providerType) {
        const p = get(providerType);
        if (!p) return '';
        switch (p.requestFormat) {
            case 'anthropic':
                return (body && body.content && body.content[0] && body.content[0].text) || '';
            case 'google': {
                const parts = body && body.candidates && body.candidates[0]
                    && body.candidates[0].content && body.candidates[0].content.parts;
                if (!parts) return body && body.text || '';
                return parts.map((pt) => pt.text || '').join('');
            }
            default:
                return (body && body.choices && body.choices[0] && body.choices[0].message
                    && body.choices[0].message.content) || '';
        }
    }

    function parseUsage(body, providerType) {
        const p = get(providerType);
        if (!p || !body) return null;
        switch (p.requestFormat) {
            case 'anthropic':
                if (!body.usage) return null;
                return {
                    input_tokens: body.usage.input_tokens || 0,
                    output_tokens: body.usage.output_tokens || 0
                };
            case 'google':
                if (!body.usageMetadata) return null;
                return {
                    input_tokens: body.usageMetadata.promptTokenCount || 0,
                    output_tokens: body.usageMetadata.candidatesTokenCount || 0
                };
            default:
                if (!body.usage) return null;
                return {
                    input_tokens: body.usage.prompt_tokens || 0,
                    output_tokens: body.usage.completion_tokens || 0
                };
        }
    }

    function parseModels(body, providerType) {
        const out = [];
        const p = get(providerType);
        if (!p || !body) return out;
        switch (p.requestFormat) {
            case 'google':
                (body.models || []).forEach((m) => {
                    const name = m.name || '';
                    if (name.startsWith('models/')) {
                        const id = name.slice(7);
                        if (id.indexOf('gemini') !== -1) out.push(id);
                    }
                });
                return out;
            default: {
                // OpenAI shape: { data: [{ id }] } — Mistral, Ollama same.
                const data = body.data || body.models || [];
                data.forEach((m) => {
                    const id = m.id || m.name || '';
                    if (!id) return;
                    if (providerType === 'openai') {
                        // Filter to chat-capable; permissive prefix list.
                        if (/^(gpt-|o1|o3|o4|chatgpt-)/.test(id)) out.push(id);
                    } else {
                        out.push(id);
                    }
                });
                if (providerType === 'openai') {
                    const score = (id) => {
                        if (id.startsWith('gpt-4o')) return 1;
                        if (id.startsWith('gpt-4-turbo')) return 2;
                        if (id.startsWith('gpt-4')) return 3;
                        if (id.startsWith('gpt-3.5')) return 4;
                        return 99;
                    };
                    out.sort((a, b) => score(a) - score(b));
                }
                return out;
            }
        }
    }

    /**
     * Map an HTTP error response to a stable category for the UI ribbon
     * (`auth | quota | rate_limit | invalid_request | overloaded | provider_error`).
     */
    function categorizeError(status, body) {
        const errType = body && body.error && (body.error.type || body.error.code || body.error.status);
        const msg = body && body.error && body.error.message;
        if (errType === 'insufficient_quota' || errType === 'RESOURCE_EXHAUSTED') return 'quota';
        if (errType === 'overloaded_error') return 'overloaded';
        if (errType === 'rate_limit_exceeded') return 'rate_limit';
        if (status === 401 || errType === 'invalid_api_key' || errType === 'INVALID_API_KEY') return 'auth';
        if (status === 403) return 'auth';
        if (status === 429) return 'rate_limit';
        if (status === 529) return 'overloaded';
        if (status === 400) return 'invalid_request';
        return msg ? 'provider_error' : 'unknown';
    }

    // ---------- internal helpers ----------

    function joinPath(base, path) {
        if (!base) return path;
        const b = base.replace(/\/+$/, '');
        const p = String(path || '').replace(/^\/+/, '/');
        return b + p;
    }

    function defaultPathFor(format) {
        // Used when a connection.baseUrl is set without a full path.
        if (format === 'openai') return '/chat/completions';
        return '';
    }

    window.QSProviderCatalog = Object.freeze({
        PROVIDERS,
        get,
        list: () => Object.values(PROVIDERS),
        detectProvider,
        buildUrl,
        buildModelsUrl,
        buildHeaders,
        buildBody,
        parseResponse,
        parseUsage,
        parseModels,
        categorizeError
    });
})();
