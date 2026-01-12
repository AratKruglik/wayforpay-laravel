<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Facades\WayForPay;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('facade resolves and calls service', function () {
    Http::fake([
        'secure.wayforpay.com/pay' => Http::response(['url' => 'http://example.com'], 200),
    ]);

    $transaction = new Transaction('ORD_FACADE', 10.0, 'UAH', time());
    
    $url = WayForPay::purchase($transaction);
    
    expect($url)->toBe('http://example.com');
});
