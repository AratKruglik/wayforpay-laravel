<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('verifyCard returns url', function () {
    Http::fake([
        'secure.wayforpay.com/verify' => Http::response(['url' => 'http://verify.com'], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $url = $service->verifyCard('ORD_VERIFY');

    expect($url)->toBe('http://verify.com');
});

test('regularApi methods send correct request', function () {
    Http::fake([
        'api.wayforpay.com/regularApi' => Http::response(['reason' => 'Ok', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $responseSuspend = $service->suspendRecurring('ORD_REG');
    expect($responseSuspend['reason'])->toBe('Ok');

    $responseResume = $service->resumeRecurring('ORD_REG');
    expect($responseResume['reason'])->toBe('Ok');
});
