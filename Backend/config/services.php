<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    /*
     * PAC del timbrado fiscal. La llave decide el AMBIENTE: una `sk_test…` es sandbox y una
     * viva es producción; no es un ajuste por empresa.
     *
     * Vive aquí y no en un `env()` suelto del código: fuera de los archivos de configuración,
     * `env()` devuelve null en cuanto alguien cachea la config (`php artisan config:cache`), y
     * el proveedor caería sin avisar a su llave de relleno — es decir, dejaría de timbrar
     * pareciendo que sigue configurado.
     */
    'facturapi' => [
        'key' => env('FACTURAPI_KEY', ''),
    ],

];
