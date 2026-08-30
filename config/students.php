<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inactivity Threshold
    |--------------------------------------------------------------------------
    |
    | Number of weeks of no class activity after which a UK student is
    | considered eligible for automatic archiving. Configurable per
    | environment via STUDENT_INACTIVITY_THRESHOLD_WEEKS.
    |
    */

    'inactivity_threshold_weeks' => env('STUDENT_INACTIVITY_THRESHOLD_WEEKS', 4),
];
