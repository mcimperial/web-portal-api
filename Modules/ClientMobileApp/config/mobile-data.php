<?php

return [
    'name' => 'ClientMobileData',

    /*
    |--------------------------------------------------------------------------
    | Database connections
    |--------------------------------------------------------------------------
    | sync_connection   -> llibiapp_sync   (masterlist, hospitals, doctors)
    | portal_connection -> llibiapp_portal (LOA requests, utilization)
    */
    'sync_connection' => 'mysql_sync',
    'portal_connection' => 'mysql_masterlist',

    // Default pagination size for list endpoints.
    'per_page' => 20,
    'max_per_page' => 100,
];
