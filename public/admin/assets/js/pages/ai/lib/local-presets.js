/**
 * QuickSite Admin — Local AI presets
 *
 * Prefill values for "Add connection" wizard when a user picks a known local
 * runtime. All speak OpenAI-compatible chat completions.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    // Origin to recommend in CORS hints. Falls back gracefully if window
    // isn't available (defensive — this lib is browser-only in practice).
    const ORIGIN = (typeof window !== 'undefined' && window.location)
        ? window.location.origin
        : 'http://localhost';

    const PRESETS = {
        ollama: {
            id: 'ollama',
            name: 'Ollama',
            icon: '🦙',
            providerType: 'openai-compatible',
            baseUrl: 'http://localhost:11434/v1',
            requiresKey: false,
            corsHint: {
                summary: 'Ollama needs OLLAMA_ORIGINS to allow ' + ORIGIN + '.',
                instructions: {
                    windows:
                        '# Recommended (allow only this site):\n' +
                        '[System.Environment]::SetEnvironmentVariable("OLLAMA_ORIGINS", "' + ORIGIN + '", "User")\n' +
                        '\n' +
                        '# Then fully quit Ollama from the system tray and relaunch it.\n' +
                        '\n' +
                        '# To allow several sites, separate with commas:\n' +
                        '#   "' + ORIGIN + ',http://localhost,http://127.0.0.1"\n' +
                        '#\n' +
                        '# Avoid setting it to "*" — that lets every website you visit\n' +
                        '# talk to your local Ollama.',
                    macos:
                        '# Recommended (allow only this site):\n' +
                        'launchctl setenv OLLAMA_ORIGINS "' + ORIGIN + '"\n' +
                        '\n' +
                        '# Then quit Ollama from the menu bar and relaunch it.\n' +
                        '\n' +
                        '# Multiple origins: comma-separated, e.g.\n' +
                        '#   "' + ORIGIN + ',http://localhost"\n' +
                        '#\n' +
                        '# Avoid "*" — it exposes Ollama to every website you visit.',
                    linux:
                        '# Recommended (allow only this site):\n' +
                        'sudo systemctl edit ollama.service\n' +
                        '# Add under [Service]:\n' +
                        '#   Environment="OLLAMA_ORIGINS=' + ORIGIN + '"\n' +
                        'sudo systemctl daemon-reload && sudo systemctl restart ollama\n' +
                        '\n' +
                        '# Multiple origins: comma-separated. Avoid "*".'
                }
            }
        },
        'lm-studio': {
            id: 'lm-studio',
            name: 'LM Studio',
            icon: '🎛️',
            providerType: 'openai-compatible',
            baseUrl: 'http://localhost:1234/v1',
            requiresKey: false,
            corsHint: {
                summary: 'Enable "CORS" in the LM Studio Local Server tab.',
                instructions: {
                    all: 'In LM Studio → Local Server → toggle "Enable CORS" and (re)start the server. ' +
                         'It will then accept calls from ' + ORIGIN + '.'
                }
            }
        }
    };

    function get(id) { return PRESETS[id] || null; }
    function list() { return Object.values(PRESETS); }

    window.QSLocalPresets = Object.freeze({ PRESETS, get, list });
})();
