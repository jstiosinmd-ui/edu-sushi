<?php

return ['push' => [
    'public_key' => env('SUSHI_VAPID_PUBLIC_KEY'),
    'private_key' => env('SUSHI_VAPID_PRIVATE_KEY'),
    'subject' => env('SUSHI_VAPID_SUBJECT') ?: env('APP_URL'),
]];
