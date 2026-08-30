<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lumina Works feature flag
    |--------------------------------------------------------------------------
    |
    | Master switch for the Lumina Works job-matching module. Off by default so
    | the module can ship to staging/prod without being visible until the
    | pilot reveal.
    |
    */

    'enabled' => env('LUMINAWORKS_ENABLED', false),

    // Default search parameters for the Adzuna pull. Entry-level, UK-wide by
    // default; per-region pulls pass their own `where`.
    'pull' => [
        'results_per_page' => (int) env('LUMINAWORKS_RESULTS_PER_PAGE', 50),
        'max_pages' => (int) env('LUMINAWORKS_MAX_PAGES', 2),
        'max_days_old' => (int) env('LUMINAWORKS_MAX_DAYS_OLD', 14),
    ],

];
