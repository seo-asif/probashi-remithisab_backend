
<?php

return [
    'max_registrations'      => 5,

    'reservation_hours'      => 24,

    'hash_key'               => env('REGISTRATION_DEVICE_HASH_KEY'),
    'enforcement_started_at' => env('REGISTRATION_DEVICE_ENFORCEMENT_STARTED_AT'),
];
