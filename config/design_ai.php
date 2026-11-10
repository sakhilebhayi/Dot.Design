<?php

/*
|--------------------------------------------------------------------------
| Dot.Design assistant (R-45)
|--------------------------------------------------------------------------
|
| The assistant talks to the Anthropic Messages API through Laravel's Http client (no SDK package is installed).
| It is OFF unless ANTHROPIC_API_KEY is set. The key is read here, passed only in the request header and never
| logged. See docs/R45_AI.md for what is AI, what is deterministic and what is not offered.
|
*/

return [

    // Master switch. The assistant is also off whenever there is no key, whatever this says.
    'enabled' => (bool) env('DESIGN_AI_ENABLED', true),

    // Shared with the existing services.anthropic key so one secret serves the platform.
    'api_key' => (string) env('ANTHROPIC_API_KEY', ''),

    'model' => env('DESIGN_AI_MODEL', 'claude-sonnet-5-5'),
    'base_url' => rtrim((string) env('DESIGN_AI_BASE_URL', 'https://api.anthropic.com'), '/'),
    'api_version' => env('DESIGN_AI_API_VERSION', '2026-10-21'),

    // Request shape. Sonnet 5.5 rejects forced tool_choice and thinking "disabled", so the tool is requested through
    // the instructions with tool_choice auto, and the lowest thinking setting is "between_tools". 'omit' sends neither.
    'thinking' => env('DESIGN_AI_THINKING', 'between_tools'), // between_tools | adaptive | omit
    'effort' => env('DESIGN_AI_EFFORT', 'low'),               // low | medium | high | '' to omit
    'strict_tools' => (bool) env('DESIGN_AI_STRICT_TOOLS', true),

    'timeout_seconds' => (int) env('DESIGN_AI_TIMEOUT', 60),
    'connect_timeout_seconds' => 5,
    'max_tokens' => [
        'compose' => 2048,
        'improve' => 4096,
        'copy' => 1536,
    ],

    // Retries on 429, 5xx and network errors. Backoff doubles from backoff_ms, capped at max_backoff_ms, and a
    // Retry-After header (seconds) wins when it is shorter than the cap.
    'retries' => (int) env('DESIGN_AI_RETRIES', 2),
    'backoff_ms' => 500,
    'max_backoff_ms' => 8000,

    // Circuit breaker: this many failed calls in a row opens the circuit for open_seconds (calls are refused without
    // touching the network), then one call is let through again.
    'circuit' => [
        'threshold' => 5,
        'open_seconds' => 60,
    ],

    // Calls to the model per calendar day (application timezone). Failed calls count, refused ones do not.
    'limits' => [
        'user_daily' => (int) env('DESIGN_AI_USER_DAILY', 60),
        'workspace_daily' => (int) env('DESIGN_AI_WORKSPACE_DAILY', 300),
        'per_minute' => (int) env('DESIGN_AI_PER_MINUTE', 12),
    ],

    // Input size caps (characters). Anything longer is refused with a plain message, never truncated silently.
    'input' => [
        'prompt_max' => 1500,
        'instruction_max' => 500,
        'copy_field_max' => 400,
        'max_elements_sent' => 80,
        'element_text_max' => 240,
    ],

    // Proposal rows older than this are removed by `design:prune-ai-proposals` (applied compose rows are the audit
    // trail of what the assistant made, so they are kept twice as long).
    'retention_days' => 90,
];
