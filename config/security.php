
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identifier Lookup HMAC Key
    |--------------------------------------------------------------------------
    |
    | A dedicated secret for deterministic identifier lookup hashes.
    | This must be independent of Laravel's APP_KEY.
    |
    | Expected format: base64-encoded 32 random bytes.
    |
     */
    'identifier_hmac_key' => env('IDENTIFIER_HMAC_KEY'),
];
