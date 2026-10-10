<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ValorPay API
    |--------------------------------------------------------------------------
    |
    | The Valor Connect app ID, the virtual terminal app key and the EPI (the
    | 10-digit "device" ID, starting with 2) of the merchant account. Single-quote
    | the ID and key in .env: they can contain $, # and %.
    |
    */

    'url' => env('VALORPAY_URL', 'https://securelink-prod.valorpaytech.com:4430'),

    'app_id' => env('VALORPAY_APP_ID'),

    'app_key' => env('VALORPAY_APP_KEY'),

    'epi' => env('VALORPAY_EPI'),

    // How many days a hosted payment page stays payable.
    'link_valid_days' => (int) env('VALORPAY_LINK_VALID_DAYS', 1),

    // Seconds to wait for ValorPay on each call.
    'timeout' => (int) env('VALORPAY_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Webhook signature (optional)
    |--------------------------------------------------------------------------
    |
    | ValorPay documents an HMAC signature on webhooks but, in practice, sends
    | none. Never trust a webhook body: use it only to decide what to re-check
    | with the transaction list. See the README.
    |
    */

    'webhook_secret' => env('VALORPAY_WEBHOOK_SECRET'),

    'webhook_tolerance_seconds' => (int) env('VALORPAY_WEBHOOK_TOLERANCE', 300),

];
