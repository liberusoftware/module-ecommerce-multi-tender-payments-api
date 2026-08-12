<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route prefix and domain
    |--------------------------------------------------------------------------
    |
    | Every operation this package registers hangs off this prefix. A host that
    | serves its API from a dedicated hostname sets `domain`; left null the
    | routes are registered on whichever host the application answers.
    |
    */

    'prefix' => 'api/v1/ecommerce/multi-tender-payments',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Three groups, each defaulting to an empty array and each opted into
    | separately. Never null: a null here becomes `null` in a route definition
    | and Laravel reads that as "no middleware" only by accident, so the shape
    | is asserted in this package's own test suite.
    |
    | This package ships no authentication of its own. A host puts its guard in
    | `all` — `['auth:sanctum', 'throttle:api']` is the usual pair — and may
    | narrow reads or writes further. Scope enforcement is separate and always
    | applied; see `scopes` below.
    |
    */

    'middleware' => [
        'all' => [],
        'read' => [],
        'write' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | Checked with `tokenCan()` on the authenticated actor. An actor that cannot
    | answer `tokenCan()` is refused rather than admitted: an unanswered
    | authorisation question is an exposure, not a pass.
    |
    | These are transport capabilities, not business authorisation. This package
    | makes no authorisation decision about a plan or a tender — the domain owns
    | that, and at 0.1.0 the domain publishes no policy, so there is nothing
    | here to delegate to and nothing here to invent.
    |
    */

    'scopes' => [
        'read' => 'ecommerce:multi-tender-payments:read',
        'write' => 'ecommerce:multi-tender-payments:write',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 100,
    ],

];
