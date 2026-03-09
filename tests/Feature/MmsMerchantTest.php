<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\MmsService;
use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\CompensationCard;
use AratKruglik\WayForPay\Domain\CompensationAccount;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('addMerchant sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'merchantAccount' => 'new_shop_account',
            'secretKey' => 'new_shop_secret',
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com', 'My shop');
    $response = $service->addMerchant($merchant);

    expect($response['merchantAccount'])->toBe('new_shop_account')
        ->and($response['secretKey'])->toBe('new_shop_secret')
        ->and($response['reasonCode'])->toBe(1100);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'addMerchant.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['site'] === 'https://shop.com'
            && $request['phone'] === '+380501234567'
            && $request['email'] === 'shop@example.com'
            && $request['description'] === 'My shop'
            && isset($request['merchantSignature']);
    });
});

test('addMerchant sends compensation card token', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response(['reasonCode' => 1100], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com', compensationCardToken: 'tok_abc123');
    $service->addMerchant($merchant);

    Http::assertSent(function ($request) {
        return $request['compensationCardToken'] === 'tok_abc123';
    });
});

test('merchantInfo sends secretKey directly without hmac', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'merchantAccount' => 'sub_merchant_001',
            'site' => 'https://sub-shop.com',
            'status' => 'Active',
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->merchantInfo('sub_merchant_001', 'sub_merchant_secret_key');

    expect($response['merchantAccount'])->toBe('sub_merchant_001')
        ->and($response['status'])->toBe('Active');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'merchantInfo.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['merchantAccountInfo'] === 'sub_merchant_001'
            && $request['secretKey'] === 'sub_merchant_secret_key'
            && !isset($request['merchantSignature']);
    });
});

test('merchantBalance sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'merchantAccount' => 'test_merch_n1',
            'balance_UAH' => 15000.50,
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->merchantBalance();

    expect($response['balance_UAH'])->toBe(15000.50)
        ->and($response['reasonCode'])->toBe(1100);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'merchantBalance.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && isset($request['merchantSignature'])
            && !isset($request['toDate']);
    });
});

test('merchantBalance sends toDate when provided', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reasonCode' => 1100,
            'balance_UAH' => 10000.00,
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $service->merchantBalance('05.04.2018');

    Http::assertSent(function ($request) {
        return $request['toDate'] === '05.04.2018';
    });
});

test('addMerchant with compensation account', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response(['reasonCode' => 1100], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company');
    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com', compensationAccount: $account);
    $service->addMerchant($merchant);

    Http::assertSent(function ($request) {
        return $request['compensationAccountIban'] === 'UA213223130000026007233566001'
            && $request['compensationAccountOkpo'] === '12345678'
            && $request['compensationAccountName'] === 'Test Company';
    });
});
