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
