<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WayForPay Merchant Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are used to authenticate with the WayForPay API.
    | You can find them in your WayForPay merchant cabinet.
    |
    */

    'merchant_account' => env('WAYFORPAY_MERCHANT_ACCOUNT', ''),
    'secret_key' => env('WAYFORPAY_SECRET_KEY', ''),
    'merchant_domain' => env('WAYFORPAY_MERCHANT_DOMAIN', ''),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout for API requests in seconds.
    |
    */

    'timeout' => env('WAYFORPAY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Default Hold Timeout
    |--------------------------------------------------------------------------
    |
    | Fallback value (in seconds) for Transaction::$holdTimeout when creating
    | a hold via hold()/getHoldFormData()/holdCharge(). Null means no default
    | is applied and WayForPay's own default (1728000 seconds) is used.
    |
    */

    'default_hold_timeout' => env('WAYFORPAY_HOLD_TIMEOUT'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will log requests and responses.
    |
    */

    'debug' => env('WAYFORPAY_DEBUG', false),
];
