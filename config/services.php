<?php

return [
    'google' => [
        'client_ids' => array_values(array_filter(
            array_map('trim', explode(',', env('GOOGLE_CLIENT_IDS', '')))
        )),
    ],
];
