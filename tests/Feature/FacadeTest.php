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
    $transaction = new Transaction('ORD_FACADE', 10.0, 'UAH', time());
    $transaction->addProduct(new \AratKruglik\WayForPay\Domain\Product('Test Item', 10.0, 1));

    $html = WayForPay::purchase($transaction);

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('<form id="wayforpay_form"')
        ->and($html)->toContain('action="https://secure.wayforpay.com/pay"')
        ->and($html)->toContain('name="merchantAccount"')
        ->and($html)->toContain('value="test_merch_n1"');
});

test('facade resolves hold and returns auto-submit HTML', function () {
    $transaction = new Transaction('ORD_FACADE_HOLD', 10.0, 'UAH', time(), holdTimeout: 604800);
    $transaction->addProduct(new \AratKruglik\WayForPay\Domain\Product('Test Item', 10.0, 1));

    $html = WayForPay::hold($transaction);

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('name="merchantTransactionType"')
        ->and($html)->toContain('value="AUTH"');
});

test('facade resolves getHoldFormData', function () {
    $transaction = new Transaction('ORD_FACADE_HOLD_FORM', 10.0, 'UAH', time(), holdTimeout: 604800);
    $transaction->addProduct(new \AratKruglik\WayForPay\Domain\Product('Test Item', 10.0, 1));

    $formData = WayForPay::getHoldFormData($transaction);

    expect($formData['merchantTransactionType'])->toBe('AUTH')
        ->and($formData['holdTimeout'])->toBe(604800);
});

test('facade resolves holdCharge', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $transaction = new Transaction('ORD_FACADE_HOLDCHARGE', 10.0, 'UAH', time(), holdTimeout: 604800);
    $transaction->addProduct(new \AratKruglik\WayForPay\Domain\Product('Test Item', 10.0, 1));
    $card = new \AratKruglik\WayForPay\Domain\Card('4111111111111111', '12', '25', '123');

    $response = WayForPay::holdCharge($transaction, $card);

    expect($response['transactionStatus'])->toBe('WaitingAuthComplete');
});

test('facade resolves cancelHold', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Refunded', 'reasonCode' => 1100], 200),
    ]);

    $response = WayForPay::cancelHold('ORD_FACADE_CANCEL', 100.0, 'UAH');

    expect($response['transactionStatus'])->toBe('Refunded');
});
