<?php

/*
|--------------------------------------------------------------------------
| Model price table
|--------------------------------------------------------------------------
|
| USD per MILLION tokens, keyed by the model id you pass to the provider.
| Every cost figure in this package — the pre-flight estimate, the spend
| ceiling and the ai_usage_logs rows — is computed from this table.
|
| Models missing here fall back to the generic price below. Overestimating is
| deliberate: a zero price would silently switch off the ceiling for exactly
| the models nobody bothered to register.
|
| Prices move. Publish this file (`--tag=ai-budget-config`) and own the copy
| that matters to your bill.
|
| Last checked against OpenRouter: 2026-09-16.
|
*/

return [

    'fallback' => ['input' => 2.50, 'output' => 10.00],

    'models' => [

        // Google
        'google/gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
        'google/gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        'google/gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
        'google/gemini-3-flash-preview' => ['input' => 0.50, 'output' => 3.00],
        'google/gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],

        // Anthropic
        'anthropic/claude-haiku-4.5' => ['input' => 1.00, 'output' => 5.00],
        'anthropic/claude-sonnet-4.5' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-sonnet-4.6' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],

        // OpenAI
        'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'openai/gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'openai/gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
        'openai/gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
        'openai/gpt-5.1' => ['input' => 1.25, 'output' => 10.00],

        // Open weights
        'deepseek/deepseek-chat-v3.1' => ['input' => 0.25, 'output' => 0.95],
        'deepseek/deepseek-v3.2' => ['input' => 0.26, 'output' => 0.38],
        'meta-llama/llama-3.3-70b-instruct' => ['input' => 0.10, 'output' => 0.32],
        'meta-llama/llama-4-maverick' => ['input' => 0.20, 'output' => 0.80],
        'qwen/qwen3-235b-a22b-2507' => ['input' => 0.09, 'output' => 0.55],
        'qwen/qwen3-max' => ['input' => 0.78, 'output' => 3.90],

        // Audio models are billed per minute, not per token. Zeroed here so the
        // token maths does not invent a number; budget them separately.
        'openai/whisper-large-v3-turbo' => ['input' => 0.0, 'output' => 0.0],
        'openai/whisper-large-v3' => ['input' => 0.0, 'output' => 0.0],
        'openai/whisper-1' => ['input' => 0.0, 'output' => 0.0],

    ],

];
