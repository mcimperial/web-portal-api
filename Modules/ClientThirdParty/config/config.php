<?php

return [
    'name' => 'ClientThirdParty',
    
    /*
    |--------------------------------------------------------------------------
    | Sync Database Connection
    |--------------------------------------------------------------------------
    |
    | Configuration for the third-party sync database connection
    |
    */
    'sync_connection' => env('DB_CONNECTION_SYNC', 'mysql_sync'),
];
