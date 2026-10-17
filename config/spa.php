<?php

return [
    // Explicit opt-in while the independent SPA acceptance gates remain blocked.
    'goals_enabled' => env('GOALS_SPA_ENABLED', false),
];
