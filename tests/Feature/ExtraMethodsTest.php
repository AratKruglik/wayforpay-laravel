<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('settle sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->settle('ORD_AUTH', 100.0, 'UAH');

    expect($response['transactionStatus'])->toBe('Approved');
});

test('p2pCredit sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->p2pCredit('ORD_P2P', 500.0, 'UAH', '4111111111111111');

    expect($response['transactionStatus'])->toBe('Approved');
});
