/**
 * QuickSite Admin — Streaming response parsers
 *
 * Per-provider parsers for the SSE / event-stream responses returned by
 * cloud and local providers.
 *
 *   const parser = QSStreamParsers.for(providerType);
 *   const events = parser.feed(chunkText);   // -> [{ delta?, usage?, done? }, ...]
 *   const tail   = parser.flush();           // -> [{...}]  (after last byte)
 *
 * Each event carries one or more of:
 *   delta : string  — text to append to the textarea
 *   usage : { input_tokens, output_tokens } — final token totals
 *   done  : true    — terminal marker (e.g. data: [DONE])
 *   error : string  — provider-emitted error event
 *
 * The OpenAI parser is reused for `openai-compatible` (Ollama / LM Studio /
 * LiteLLM / vLLM in OpenAI mode all emit identical SSE).
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    function makeSseParser(handleEvent) {
        let buffer = '';
        return {
            feed(chunk) {
                buffer += chunk;
                buffer = buffer.replace(/\r\n/g, '\n');
                const out = [];
                let idx;
                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, idx);
                    buffer = buffer.slice(idx + 2);
                    const ev = parseSseFrame(raw);
                    if (!ev) continue;
                    const events = handleEvent(ev);
                    if (events) out.push(...events);
                }
                return out;
            },
            flush() {
                if (!buffer.trim()) { buffer = ''; return []; }
                const ev = parseSseFrame(buffer);
                buffer = '';
                if (!ev) return [];
                return handleEvent(ev) || [];
            }
        };
    }

    function parseSseFrame(raw) {
        let event = 'message';
        const dataLines = [];
        raw.split('\n').forEach((line) => {
            if (!line || line.startsWith(':')) return; // comment/keepalive
            const colon = line.indexOf(':');
            if (colon === -1) return;
            const field = line.slice(0, colon);
            let value = line.slice(colon + 1);
            if (value.startsWith(' ')) value = value.slice(1);
            if (field === 'event') event = value;
            else if (field === 'data') dataLines.push(value);
        });
        if (dataLines.length === 0) return null;
        return { event, data: dataLines.join('\n') };
    }

    function makeNdjsonParser(handleObject) {
        let buffer = '';
        return {
            feed(chunk) {
                buffer += chunk;
                const out = [];
                let idx;
                while ((idx = buffer.indexOf('\n')) !== -1) {
                    const line = buffer.slice(0, idx).trim();
                    buffer = buffer.slice(idx + 1);
                    if (!line) continue;
                    let obj;
                    try { obj = JSON.parse(line); } catch (_e) { continue; }
                    const events = handleObject(obj);
                    if (events) out.push(...events);
                }
                return out;
            },
            flush() {
                const tail = buffer.trim();
                buffer = '';
                if (!tail) return [];
                let obj;
                try { obj = JSON.parse(tail); } catch (_e) { return []; }
                return handleObject(obj) || [];
            }
        };
    }

    function openaiParser() {
        // data: {"choices":[{"delta":{"content":"..."}}]}
        // Terminator: data: [DONE]
        // Optional final usage chunk: data: {"usage":{...},"choices":[]}
        return makeSseParser((ev) => {
            const data = ev.data;
            if (data === '[DONE]') return [{ done: true }];
            let obj;
            try { obj = JSON.parse(data); } catch (_e) { return []; }
            const out = [];
            if (obj && obj.error) {
                out.push({ error: obj.error.message || obj.error.type || 'error' });
            }
            const choices = (obj && obj.choices) || [];
            choices.forEach((c) => {
                const delta = c && c.delta && c.delta.content;
                if (typeof delta === 'string' && delta.length) out.push({ delta });
            });
            if (obj && obj.usage) {
                out.push({
                    usage: {
                        input_tokens: obj.usage.prompt_tokens || 0,
                        output_tokens: obj.usage.completion_tokens || 0
                    }
                });
            }
            return out;
        });
    }

    function anthropicParser() {
        // Typed SSE events: message_start / content_block_delta / message_delta /
        // message_stop / error.
        return makeSseParser((ev) => {
            let obj;
            try { obj = JSON.parse(ev.data); } catch (_e) { return []; }
            const out = [];
            if (ev.event === 'content_block_delta') {
                const d = obj && obj.delta;
                if (d && typeof d.text === 'string' && d.text.length) {
                    out.push({ delta: d.text });
                }
            } else if (ev.event === 'message_start') {
                const u = obj && obj.message && obj.message.usage;
                if (u) {
                    out.push({
                        usage: {
                            input_tokens: u.input_tokens || 0,
                            output_tokens: u.output_tokens || 0
                        }
                    });
                }
            } else if (ev.event === 'message_delta') {
                const u = obj && obj.usage;
                if (u) {
                    out.push({
                        usage: {
                            input_tokens: 0,
                            output_tokens: u.output_tokens || 0
                        }
                    });
                }
            } else if (ev.event === 'message_stop') {
                out.push({ done: true });
            } else if (ev.event === 'error') {
                const e = obj && obj.error;
                out.push({ error: (e && e.message) || (e && e.type) || 'error' });
            }
            return out;
        });
    }

    function googleParser() {
        // Google SSE (with ?alt=sse) emits `data:` lines wrapping the same
        // object shape as the non-streaming endpoint.
        return makeSseParser((ev) => {
            let obj;
            try { obj = JSON.parse(ev.data); } catch (_e) { return []; }
            const out = [];
            const parts = obj && obj.candidates && obj.candidates[0]
                && obj.candidates[0].content && obj.candidates[0].content.parts;
            if (parts) {
                parts.forEach((pt) => {
                    if (typeof pt.text === 'string' && pt.text.length) out.push({ delta: pt.text });
                });
            }
            if (obj && obj.usageMetadata) {
                out.push({
                    usage: {
                        input_tokens: obj.usageMetadata.promptTokenCount || 0,
                        output_tokens: obj.usageMetadata.candidatesTokenCount || 0
                    }
                });
            }
            const finish = obj && obj.candidates && obj.candidates[0] && obj.candidates[0].finishReason;
            if (finish) out.push({ done: true });
            return out;
        });
    }

    function ollamaNativeParser() {
        // Reserved: Ollama's native /api/chat NDJSON shape. We currently
        // target Ollama's OpenAI-compatible /v1/chat/completions endpoint,
        // which uses the openai SSE parser above.
        return makeNdjsonParser((obj) => {
            const out = [];
            const delta = obj && obj.message && obj.message.content;
            if (typeof delta === 'string' && delta.length) out.push({ delta });
            if (obj && obj.done) {
                out.push({
                    done: true,
                    usage: {
                        input_tokens: obj.prompt_eval_count || 0,
                        output_tokens: obj.eval_count || 0
                    }
                });
            }
            return out;
        });
    }

    function forProvider(providerType) {
        switch (providerType) {
            case 'anthropic':         return anthropicParser();
            case 'google':            return googleParser();
            case 'openai':            return openaiParser();
            case 'mistral':           return openaiParser();
            case 'openai-compatible': return openaiParser();
            case 'ollama-native':     return ollamaNativeParser();
            default:                  return openaiParser();
        }
    }

    function supportsStreaming(providerType) {
        return ['openai', 'anthropic', 'google', 'mistral', 'openai-compatible'].indexOf(providerType) !== -1;
    }

    /**
     * Some providers need a query/path tweak for streaming:
     *   Google: append ?alt=sse and use :streamGenerateContent instead of :generateContent.
     */
    function transformStreamingUrl(url, providerType) {
        if (providerType !== 'google') return url;
        let next = url.replace(':generateContent', ':streamGenerateContent');
        next += (next.indexOf('?') === -1 ? '?' : '&') + 'alt=sse';
        return next;
    }

    window.QSStreamParsers = Object.freeze({
        for: forProvider,
        supportsStreaming,
        transformStreamingUrl,
        // exposed for tests:
        _internal: { makeSseParser, makeNdjsonParser, parseSseFrame }
    });
})();
