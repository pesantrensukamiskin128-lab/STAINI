<?php

return [
    // Endpoint migrasi lama harus nonaktif pada server publik.
    'enabled' => (bool) env('MIGRATION_ENDPOINTS_ENABLED', false),
    'key' => env('MIGRATION_ENDPOINT_KEY'),
];
