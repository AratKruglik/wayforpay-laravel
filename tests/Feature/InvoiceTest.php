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

test('createInvoice sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['invoiceUrl' => 'http://pay.com', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('INV1', 100.0, 'UAH', 123456);
    $transaction->addProduct(new Product('Item', 100.0, 1));

    $response = $service->createInvoice($transaction);

    expect($response['invoiceUrl'])->toBe('http://pay.com');
});

test('removeInvoice sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['reason' => 'Removed', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->removeInvoice('INV1');

    expect($response['reason'])->toBe('Removed');
});
