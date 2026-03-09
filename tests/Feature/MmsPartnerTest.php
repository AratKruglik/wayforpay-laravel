<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\MmsService;
use AratKruglik\WayForPay\Domain\Partner;
use AratKruglik\WayForPay\Domain\CompensationCard;
use AratKruglik\WayForPay\Domain\CompensationAccount;
use AratKruglik\WayForPay\Exceptions\WayForPayException;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('addPartner sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'merchantAccount' => 'partner_001',
            'secretKey' => 'generated_secret_key',
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $partner = new Partner('partner_001', 'https://partner.com', '+380501234567', 'partner@example.com');
    $response = $service->addPartner($partner);

    expect($response['reasonCode'])->toBe(1100)
        ->and($response['merchantAccount'])->toBe('partner_001')
        ->and($response['secretKey'])->toBe('generated_secret_key');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'addPartner.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['partnerCode'] === 'partner_001'
            && $request['phone'] === '+380501234567'
            && $request['email'] === 'partner@example.com'
            && $request['site'] === 'https://partner.com'
            && isset($request['merchantSignature']);
    });
});

test('addPartner sends compensation card data', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response(['reasonCode' => 1100], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $card = new CompensationCard('4111111111111111', '12', '25', '123', 'John Doe');
    $partner = new Partner('partner_001', 'https://partner.com', '+380501234567', 'partner@example.com', compensationCard: $card);
    $service->addPartner($partner);

    Http::assertSent(function ($request) {
        return $request['compensationCardNumber'] === '4111111111111111'
            && $request['compensationCardExpMonth'] === '12'
            && $request['compensationCardExpYear'] === '25'
            && $request['compensationCardCvv'] === '123'
            && $request['compensationCardHolder'] === 'John Doe';
    });
});

test('addPartner sends compensation account data', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response(['reasonCode' => 1100], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company', '305299');
    $partner = new Partner('partner_001', 'https://partner.com', '+380501234567', 'partner@example.com', compensationAccount: $account);
    $service->addPartner($partner);

    Http::assertSent(function ($request) {
        return $request['compensationAccountIban'] === 'UA213223130000026007233566001'
            && $request['compensationAccountOkpo'] === '12345678'
            && $request['compensationAccountName'] === 'Test Company'
            && $request['compensationAccountMfo'] === '305299';
    });
});

test('partnerInfo sends correct request and returns partner data', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Ok',
            'reasonCode' => 1100,
            'merchantAccount' => 'partner_001',
            'partnerCode' => 'partner_001',
            'partnerStatus' => 'Active',
            'site' => 'https://partner.com',
            'phone' => '+380501234567',
            'email' => 'partner@example.com',
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->partnerInfo('partner_001');

    expect($response['partnerStatus'])->toBe('Active')
        ->and($response['partnerCode'])->toBe('partner_001');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'partnerInfo.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['partnerCode'] === 'partner_001'
            && isset($request['merchantSignature']);
    });
});

test('updatePartner maps partnerCode to merchantAccountEdit', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'merchantAccount' => 'partner_001',
            'reason' => 'update',
            'reasonCode' => 1100,
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $response = $service->updatePartner('partner_001', [
        'phone' => '+380509999999',
        'email' => 'new@example.com',
    ]);

    expect($response['reason'])->toBe('update')
        ->and($response['reasonCode'])->toBe(1100);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'updatePartner.php')
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['merchantAccountEdit'] === 'partner_001'
            && $request['phone'] === '+380509999999'
            && $request['email'] === 'new@example.com'
            && isset($request['merchantSignature'])
            && !isset($request['partnerCode']);
    });
});

test('mms service throws exception on api error', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response([
            'reason' => 'Signature mismatch',
            'reasonCode' => 1124,
        ], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $service->partnerInfo('partner_001');
})->throws(WayForPayException::class, 'Signature mismatch');

test('mms service throws exception on http failure', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response('Server Error', 500),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $service->partnerInfo('partner_001');
})->throws(WayForPayException::class, 'MMS API request failed');

test('addPartner sends compensation card token', function () {
    Http::fake([
        'api.wayforpay.com/mms/*' => Http::response(['reasonCode' => 1100], 200),
    ]);

    $service = new MmsService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $partner = new Partner('partner_001', 'https://partner.com', '+380501234567', 'partner@example.com', compensationCardToken: 'tok_abc123');
    $service->addPartner($partner);

    Http::assertSent(function ($request) {
        return $request['compensationCardToken'] === 'tok_abc123';
    });
});
