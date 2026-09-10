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
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key_path' => env('APPLE_PRIVATE_KEY_PATH'),
        'redirect_uri' => env('APPLE_REDIRECT_URI'),
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

    /*
     * Checkout SIMULADO: da de alta una empresa completa —con su admin y su token— sin cobrar.
     * Sólo tiene sentido en un servidor de pruebas con `APP_ENV=production`, que es lo que fue
     * la instancia V2 mientras no hubo pasarela.
     *
     * Encenderlo ya no basta para que funcione: `SubscriptionController::simulatorAllowed()` lo
     * ignora en cuanto hay una pasarela de verdad configurada, así que el día que Stripe cobre
     * se apaga solo. Antes había que acordarse de quitar la variable a mano, y ese pendiente
     * dejaba un alta gratuita viva en una URL pública.
     */
    'checkout_simulado' => env('ALLOW_SIMULATED_CHECKOUT', false),

];
