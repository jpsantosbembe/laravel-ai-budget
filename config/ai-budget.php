<?php

use Bembe\AiBudget\Schema\SubsetSchemaValidator;
use Bembe\AiBudget\Transport\OpenRouterTransport;

return [

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | Channel handed to the package's PSR-3 logger. Null uses the default
    | application channel.
    |
    */

    'log_channel' => env('AI_BUDGET_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Local currency
    |--------------------------------------------------------------------------
    |
    | Provider prices are quoted in USD. The package converts every cost to a
    | local currency so spend ceilings can be expressed in the money your
    | finance team actually budgets in.
    |
    | 'fx_rate' may be a float or any callable returning a float, which is how
    | you plug in a live rate (a cached HTTP lookup, a settings row, ...).
    |
    */

    'currency' => env('AI_BUDGET_CURRENCY', 'USD'),

    'fx_rate' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | Token estimation
    |--------------------------------------------------------------------------
    |
    | Characters per token used to approximate the input size BEFORE the call,
    | which is what the spend ceiling is checked against. Four is a reasonable
    | average for latin-script languages; lower it if you run CJK workloads.
    |
    */

    'chars_per_token' => 4,

    /*
    |--------------------------------------------------------------------------
    | Default request timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Transports
    |--------------------------------------------------------------------------
    |
    | Maps the `provider` column of ai_credentials to a ChatTransport
    | implementation. Add your own by pointing a provider key at a class
    | implementing Bembe\AiBudget\Transport\ChatTransport.
    |
    */

    'transports' => [
        'openrouter' => OpenRouterTransport::class,
    ],

    'default_transport' => 'openrouter',

    /*
    |--------------------------------------------------------------------------
    | Schema validator
    |--------------------------------------------------------------------------
    |
    | The bundled validator covers the subset of JSON Schema this package
    | needs: type, required, properties, items and enum. Swap in your own
    | implementation of SchemaValidator for full spec coverage.
    |
    */

    'schema_validator' => SubsetSchemaValidator::class,

];
