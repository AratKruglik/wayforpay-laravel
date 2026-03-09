<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;
use AratKruglik\WayForPay\Domain\AccountTransfer;
use AratKruglik\WayForPay\Exceptions\WayForPayException;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
});

test('p2pAccount sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'merchantAccount' => 'test_merch_n1',
            'orderReference' => 'ORD_P2P_001',
            'transactionStatus' => 'Approved',
            'reasonCode' => 1100,
            'amount' => 1500.00,
            'currency' => 'UAH',
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transfer = new AccountTransfer(
        'ORD_P2P_001', 1500.00, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Test Receiver'
    );

    $response = $service->p2pAccount($transfer);

    expect($response['transactionStatus'])->toBe('Approved')
        ->and($response['reasonCode'])->toBe(1100);

    Http::assertSent(function ($request) {
        return $request['transactionType'] === 'P2P_ACCOUNT'
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['apiVersion'] === 1
            && $request['orderReference'] === 'ORD_P2P_001'
            && $request['amount'] === 1500.00
            && $request['currency'] === 'UAH'
            && $request['iban'] === 'UA213223130000026007233566001'
            && $request['okpo'] === '12345678'
            && $request['accountName'] === 'Test Receiver'
            && isset($request['merchantSignature']);
    });
});

test('p2pAccount sends optional fields', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'transactionStatus' => 'Approved',
            'reasonCode' => 1100,
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transfer = new AccountTransfer(
        'ORD_P2P_002', 2000.00, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Test Receiver',
        'Payment for services', 'https://callback.com/webhook',
        'Doe', '+380501234567', 'recipient@example.com'
    );

    $service->p2pAccount($transfer);

    Http::assertSent(function ($request) {
        return $request['description'] === 'Payment for services'
            && $request['serviceUrl'] === 'https://callback.com/webhook'
            && $request['recipientLastName'] === 'Doe'
            && $request['recipientPhone'] === '+380501234567'
            && $request['recipientEmail'] === 'recipient@example.com';
    });
});

test('p2pAccount throws exception on error response', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'reason' => 'Duplicate order reference',
            'reasonCode' => 1118,
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transfer = new AccountTransfer(
        'ORD_P2P_DUP', 1000.00, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Test Receiver'
    );

    $service->p2pAccount($transfer);
})->throws(WayForPayException::class, 'Duplicate order reference');
