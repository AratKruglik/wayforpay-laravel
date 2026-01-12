<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('purchase returns url on success', function () {
    Http::fake([
        'secure.wayforpay.com/pay' => Http::response(['url' => 'https://secure.wayforpay.com/page?Vkh=...'], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('ORD1', 100.0, 'UAH', 123456);
    $transaction->addProduct(new Product('Item', 100.0, 1));

    $url = $service->purchase($transaction);

    expect($url)->toBe('https://secure.wayforpay.com/page?Vkh=...');
});

test('checkStatus returns correct data', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'reasonCode' => 1100,
            'transactionStatus' => 'Approved'
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->checkStatus('ORD1');

    expect($response['transactionStatus'])->toBe('Approved')
        ->and($response['reasonCode'])->toBe(1100);
});

test('refund sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'transactionStatus' => 'Refunded',
            'reasonCode' => 1100
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->refund('ORD1', 50.0, 'UAH', 'Return');

    expect($response['transactionStatus'])->toBe('Refunded');
});