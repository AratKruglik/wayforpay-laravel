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

test('purchase returns HTML form that auto-submits', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('ORD1', 100.0, 'UAH', 123456);
    $transaction->addProduct(new Product('Item', 100.0, 1));

    $html = $service->purchase($transaction);

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('<form id="wayforpay_form"')
        ->and($html)->toContain('action="https://secure.wayforpay.com/pay"')
        ->and($html)->toContain('method="POST"')
        ->and($html)->toContain('name="merchantAccount"')
        ->and($html)->toContain('value="test_merch_n1"')
        ->and($html)->toContain('name="amount"')
        ->and($html)->toContain('value="100"')
        ->and($html)->toContain('name="currency"')
        ->and($html)->toContain('value="UAH"')
        ->and($html)->toContain('name="merchantSignature"')
        ->and($html)->toContain('document.getElementById(\'wayforpay_form\').submit()');
});

test('getPurchaseFormData returns correct form data', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $transaction = new Transaction('ORD1', 100.0, 'UAH', 123456);
    $transaction->addProduct(new Product('Item', 100.0, 1));

    $formData = $service->getPurchaseFormData($transaction, 'http://example.com/return', 'http://example.com/service');

    expect($formData)->toHaveKey('merchantAccount')
        ->and($formData['merchantAccount'])->toBe('test_merch_n1')
        ->and($formData)->toHaveKey('merchantDomainName')
        ->and($formData['merchantDomainName'])->toBe('www.market.ua')
        ->and($formData)->toHaveKey('amount')
        ->and($formData['amount'])->toBe(100.0)
        ->and($formData)->toHaveKey('currency')
        ->and($formData['currency'])->toBe('UAH')
        ->and($formData)->toHaveKey('merchantSignature')
        ->and($formData)->toHaveKey('returnUrl')
        ->and($formData['returnUrl'])->toBe('http://example.com/return')
        ->and($formData)->toHaveKey('serviceUrl')
        ->and($formData['serviceUrl'])->toBe('http://example.com/service');
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