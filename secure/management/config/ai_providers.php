<?php
/**
 * AI Providers Configuration
 * 
 * Registry of supported AI providers with their API details.
 * Models are fetched dynamically from the API when possible.
 * default_models is a fallback if API listing fails.
 * 
 * @version 1.0.0
 */

return [
    'openai' => [
        'name' => 'OpenAI',
        'prefixes' => ['sk-', 'sk-proj-'],
        'endpoint' => 'https://api.openai.com/v1/chat/completions',
        'models_endpoint' => 'https://api.openai.com/v1/models',
        'auth_header' => 'Authorization',
        'auth_format' => 'Bearer {key}',
        'default_models' => [
            'gpt-4o',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo'
        ],
        'request_format' => 'openai', // Standard OpenAI format
        'error_codes' => [
            401 => 'invalid_key',
            403 => 'no_access',
            429 => 'rate_limit',
        ],
        'error_messages' => [
            'insufficient_quota' => 'quota_exceeded',
            'rate_limit_exceeded' => 'rate_limit',
        ]
    ],
    
    'anthropic' => [
        'name' => 'Anthropic',
        'prefixes' => ['sk-ant-'],
        'endpoint' => 'https://api.anthropic.com/v1/messages',
        'models_endpoint' => null, // Anthropic doesn't have a models list endpoint
        'auth_header' => 'x-api-key',
        'auth_format' => '{key}',
        'extra_headers' => [
            'anthropic-version' => '2023-06-01'
        ],
        'default_models' => [
            'claude-opus-4-20250514',
            'claude-sonnet-4-20250514',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-latest',
            'claude-3-opus-latest',
            'claude-3-haiku-20240307'
        ],
        'request_format' => 'anthropic', // Anthropic-specific format
        'error_codes' => [
            401 => 'invalid_key',
            403 => 'no_access',
            429 => 'rate_limit',
            529 => 'overloaded',
        ],
        'error_messages' => [
            'overloaded_error' => 'rate_limit',
        ]
    ],
    
    'google' => [
        'name' => 'Google AI',
        'prefixes' => ['AIza'],
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
        'models_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'auth_header' => null, // Uses query parameter instead
        'auth_query_param' => 'key',
        'auth_format' => '{key}',
        'default_models' => [
            'gemini-1.5-flash',       // Best free tier option
            'gemini-1.5-pro',         // Free tier available
            'gemini-2.0-flash',       // May require billing
            'gemini-2.5-pro-preview-06-05', // Preview, may require billing
        ],
        'request_format' => 'google', // Google-specific format
        'error_codes' => [
            400 => 'invalid_request',
            403 => 'invalid_key',
            429 => 'rate_limit',
        ],
        'error_messages' => [
            'RESOURCE_EXHAUSTED' => 'quota_exceeded',
            'INVALID_API_KEY' => 'invalid_key',
        ]
    ],
    
    'mistral' => [
        'name' => 'Mistral AI',
        'prefixes' => [], // No standard prefix - requires fallback detection
        'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
        'models_endpoint' => 'https://api.mistral.ai/v1/models',
        'auth_header' => 'Authorization',
        'auth_format' => 'Bearer {key}',
        'default_models' => [
            'mistral-large-latest',
            'mistral-medium-latest',
            'mistral-small-latest',
            'codestral-latest'
        ],
        'request_format' => 'openai', // Mistral uses OpenAI-compatible format
        'error_codes' => [
            401 => 'invalid_key',
            403 => 'no_access',
            429 => 'rate_limit',
        ],
        'error_messages' => []
    ],
];
