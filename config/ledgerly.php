<?php

return [
    /*
    | First admin, created on boot by `php artisan app:ensure-admin` when both are set.
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME', 'Administrator'),
    ],
];
