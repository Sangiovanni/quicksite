/**
 * QuickSite Admin — AI Caller (browser-direct)
 *
 * Single entry point all AI callers use. Performs a `fetch()` directly
 * against the provider endpoint resolved from the connection. No PHP
 * proxy hop. Streams through stream-parsers.js when the connection has
 * `streaming` on and the provider supports it; otherwise reads once.
 *
 *   const result = await QSAiCall.call({
 *     connection, model, messages,
 *     options: { max_tokens, temperature },   // max_tokens: the connection's output limit when omitted
 *     signal,            // AbortSignal for cancellation
 *     onChunk            // called per streamed delta; unused when not streaming
 *   });
 *   // result = { content, usage, finish, finishReason, viaStream, raw, model, providerType }
 *   // finish: why the answer ended — 'stop', 'length' (cut at the output limit), 'other' (the
 *   // provider stopped it), or null when it gave no reason; finishReason: the provider's own word.
 *
 * On HTTP error, throws a typed error with .category from
 * QSProviderCatalog.categorizeError plus .status, .body and .message.
 */
(function () {
    'use strict';

    function err(category, message, extra) {
        const e = new Error(message || category);
        e.category = category;
        Object.assign(e, extra || {});
        return e;
    }

    async function call(args) {
        const { connection, model, messages, options, signal, onChunk } = args || {};
        if (!connection) throw err('invalid_request', 'Missing connection');
        if (!model) throw err('invalid_request', 'Missing model');
        if (!Array.isArray(messages) || messages.length === 0) {
            throw err('invalid_request', 'Missing messages');
        }
        if (!window.QSProviderCatalog) {
            throw err('invalid_request', 'Provider catalog not loaded');
        }

        const cat = window.QSProviderCatalog;
        const wantStream = !!(connection.streaming
            && window.QSStreamParsers
            && window.QSStreamParsers.supportsStreaming(connection.providerType));

        let url, headers, body;
        try {
            url = cat.buildUrl(connection, model);
            headers = cat.buildHeaders(connection);
            body = cat.buildBody(connection, model, messages, options || {}, { stream: wantStream });
            // OpenAI emits a final usage chunk only when explicitly requested.
            if (wantStream && (connection.providerType === 'openai'
                || connection.providerType === 'openai-compatible'
                || connection.providerType === 'mistral')) {
                body.stream_options = Object.assign({}, body.stream_options, { include_usage: true });
            }
            if (wantStream) {
                url = window.QSStreamParsers.transformStreamingUrl(url, connection.providerType);
            }
        } catch (e) {
            throw err('invalid_request', e.message);
        }

        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers,
                body: JSON.stringify(body),
                signal,
                mode: 'cors',
                credentials: 'omit'
            });
        } catch (e) {
            if (e && e.name === 'AbortError') throw err('aborted', 'Request aborted');
            throw err('network', e.message || 'Network error', { cause: e });
        }

        if (!response.ok) {
            // Body might be SSE error frame or JSON; try JSON first.
            let parsed = null;
            let text = '';
            try { text = await response.text(); parsed = text ? JSON.parse(text) : null; } catch (_e) { /* keep text */ }
            const category = cat.categorizeError(response.status, parsed);
            throw err(category, extractMessage(parsed) || ('HTTP ' + response.status), {
                status: response.status,
                body: parsed || text
            });
        }

        if (wantStream && response.body && response.body.getReader) {
            return await consumeStream(response, connection, model, onChunk, signal);
        }

        // Non-streaming: read once, parse via catalog.
        let text = '';
        let parsed = null;
        try { text = await response.text(); parsed = text ? JSON.parse(text) : null; } catch (_e) { parsed = null; }
        const content = cat.parseResponse(parsed, connection.providerType);
        const usage = cat.parseUsage(parsed, connection.providerType);
        const finishReason = cat.parseFinishReason(parsed, connection.providerType);
        return {
            content,
            usage,
            finish: cat.finishKind(finishReason),
            finishReason,
            viaStream: false,
            raw: parsed,
            model,
            providerType: connection.providerType
        };
    }

    /**
     * A stream's token counts so far, with one more event's. A count never goes down within one
     * answer, so each keeps its largest; a count an event leaves out stays as it was. Anthropic sends
     * the input count when the answer starts and the output count when it ends.
     */
    function mergeUsage(usage, more) {
        const out = Object.assign({}, usage || {});
        ['input_tokens', 'output_tokens'].forEach((k) => {
            if (typeof more[k] === 'number') out[k] = Math.max(typeof out[k] === 'number' ? out[k] : 0, more[k]);
        });
        return out;
    }

    /**
     * Consume an SSE/streaming response, calling onChunk(deltaText) per
     * delta and accumulating the full content. Returns the same shape as
     * the non-streaming branch.
     */
    async function consumeStream(response, connection, model, onChunk, signal) {
        const parser = window.QSStreamParsers.for(connection.providerType);
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let content = '';
        let usage = null;
        let finishReason = null;
        let aborted = false;
        let streamErr = null;

        const onAbort = () => { aborted = true; try { reader.cancel(); } catch (_e) { /* noop */ } };
        if (signal) {
            if (signal.aborted) onAbort();
            else signal.addEventListener('abort', onAbort, { once: true });
        }

        try {
            // eslint-disable-next-line no-constant-condition
            while (true) {
                if (aborted) break;
                const { value, done } = await reader.read();
                if (done) break;
                if (aborted) break;
                const chunkText = decoder.decode(value, { stream: true });
                if (!chunkText) continue;
                const events = parser.feed(chunkText);
                for (const ev of events) {
                    if (aborted) break;
                    if (ev.error) { streamErr = ev.error; }
                    if (typeof ev.delta === 'string' && ev.delta.length) {
                        content += ev.delta;
                        if (typeof onChunk === 'function') {
                            try { onChunk(ev.delta, { content, model, providerType: connection.providerType }); }
                            catch (_e) { /* swallow caller errors */ }
                        }
                    }
                    if (ev.usage) usage = mergeUsage(usage, ev.usage);
                    if (ev.finish) finishReason = ev.finish;
                }
            }
            if (aborted) {
                throw err('aborted', 'Request aborted', { partial: content });
            }
            // Flush any trailing buffered frame.
            const tail = parser.flush();
            for (const ev of tail) {
                if (ev.error) streamErr = ev.error;
                if (typeof ev.delta === 'string' && ev.delta.length) {
                    content += ev.delta;
                    if (typeof onChunk === 'function') {
                        try { onChunk(ev.delta, { content, model, providerType: connection.providerType }); }
                        catch (_e) { /* noop */ }
                    }
                }
                if (ev.usage) usage = mergeUsage(usage, ev.usage);
                if (ev.finish) finishReason = ev.finish;
            }
            // Flush decoder.
            const finalText = decoder.decode();
            if (finalText) {
                const more = parser.feed(finalText).concat(parser.flush());
                more.forEach((ev) => {
                    if (ev.error) streamErr = ev.error;
                    if (typeof ev.delta === 'string' && ev.delta.length) {
                        content += ev.delta;
                        if (typeof onChunk === 'function') onChunk(ev.delta, { content, model, providerType: connection.providerType });
                    }
                    if (ev.usage) usage = mergeUsage(usage, ev.usage);
                    if (ev.finish) finishReason = ev.finish;
                });
            }
        } catch (e) {
            if (signal) signal.removeEventListener('abort', onAbort);
            if (aborted || (e && e.name === 'AbortError')) {
                throw err('aborted', 'Request aborted', { partial: content });
            }
            throw err('network', e.message || 'Stream read failed', { cause: e, partial: content });
        }

        if (signal) signal.removeEventListener('abort', onAbort);

        if (streamErr) {
            throw err('provider_error', streamErr, { partial: content });
        }

        return {
            content,
            usage,
            finish: window.QSProviderCatalog.finishKind(finishReason),
            finishReason,
            viaStream: true,
            raw: null,
            model,
            providerType: connection.providerType
        };
    }

    /**
     * Probe a connection: lists models if available, else does a tiny
     * completion to validate auth + reachability. Returns
     * `{ ok, models, message, category? }` — never throws.
     */
    async function test(connection, signal) {
        if (!connection) return { ok: false, models: [], message: 'No connection' };
        const cat = window.QSProviderCatalog;
        const modelsUrl = cat.buildModelsUrl(connection);

        if (modelsUrl) {
            try {
                const headers = cat.buildHeaders(connection);
                // Google uses query-param auth; strip the auth header for GET.
                const resp = await fetch(modelsUrl, {
                    method: 'GET',
                    headers,
                    signal,
                    mode: 'cors',
                    credentials: 'omit'
                });
                const text = await resp.text();
                let parsed = null;
                try { parsed = text ? JSON.parse(text) : null; } catch (_e) { parsed = null; }
                if (!resp.ok) {
                    const category = cat.categorizeError(resp.status, parsed);
                    return {
                        ok: false,
                        models: [],
                        category,
                        status: resp.status,
                        message: extractMessage(parsed) || ('HTTP ' + resp.status)
                    };
                }
                const models = cat.parseModels(parsed, connection.providerType);
                return { ok: true, models, message: models.length + ' models' };
            } catch (e) {
                if (e && e.name === 'AbortError') return { ok: false, models: [], category: 'aborted', message: 'Aborted' };
                return { ok: false, models: [], category: 'network', message: e.message || 'Network error' };
            }
        }

        // No models endpoint (e.g. Anthropic) — fall back to a minimal completion.
        try {
            await call({
                connection,
                model: connection.defaultModel
                    || (cat.get(connection.providerType) || {}).defaultModels && cat.get(connection.providerType).defaultModels[0]
                    || 'claude-3-haiku-20240307',
                messages: [{ role: 'user', content: 'Hi' }],
                options: { max_tokens: 1 },
                signal
            });
            const fallback = (cat.get(connection.providerType) || {}).defaultModels || [];
            return { ok: true, models: fallback.slice(), message: 'Reachable' };
        } catch (e) {
            return {
                ok: false,
                models: [],
                category: e.category || 'network',
                status: e.status || 0,
                message: e.message || 'Failed'
            };
        }
    }

    function extractMessage(body) {
        if (!body) return null;
        if (typeof body === 'string') return body;
        if (body.error) {
            if (typeof body.error === 'string') return body.error;
            if (body.error.message) return body.error.message;
            if (body.error.type) return body.error.type;
        }
        if (body.message) return body.message;
        return null;
    }

    window.QSAiCall = Object.freeze({ call, test });
})();
