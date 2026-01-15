<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;
use AratKruglik\WayForPay\Exceptions\WayForPayException;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'secret');
});

test('service throws exception on api failure for API methods', function () {
    Http::fake([
        '*' => Http::response('Server Error', 500),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('secret'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('FAIL', 10.0, 'UAH', time());
    $transaction->addProduct(new Product('Item', 10.0, 1));

    expect(fn() => $service->checkStatus('ORDER123'))
        ->toThrow(WayForPayException::class, 'API Request failed');
});

test('service throws exception on business error code for API methods', function () {
    Http::fake([
        '*' => Http::response([
            'reasonCode' => 1101,
            'reason' => 'Declined by Bank'
        ], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('secret'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('FAIL_CODE', 10.0, 'UAH', time());
    $transaction->addProduct(new Product('Item', 10.0, 1));

    try {
        $service->checkStatus('ORDER123');
        $this->fail('Exception was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getMessage())->toBe('Declined by Bank')
            ->and($e->getReasonCode()->value)->toBe(1101);
    }
});

test('webhook throws exception on invalid signature', function () {
    $service = new WayForPayService(
        new SignatureGenerator('secret'),
        Http::getFacadeRoot()
    );

    $data = [
        'merchantAccount' => 'test',
        'orderReference' => '123',
        'transactionStatus' => 'Approved',
        'merchantSignature' => 'invalid_hash'
    ];

    expect(fn() => $service->handleWebhook($data))
        ->toThrow(\AratKruglik\WayForPay\Exceptions\SignatureMismatchException::class, 'Invalid webhook signature');
});
