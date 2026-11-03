<?php

return [
    'google' => [
        'client_ids' => array_values(array_filter(
            array_map('trim', explode(',', env('GOOGLE_CLIENT_IDS', '')))
        )),
    ],
    'telegram_admin' => [
        'bot_token' => env('TELEGRAM_ADMIN_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_ADMIN_CHAT_ID', ''),
    ],

];