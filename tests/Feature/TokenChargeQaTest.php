<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\CardToken;
use AratKruglik\WayForPay\Domain\Client;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

function qaTokenSaleTransaction(?Client $client = null): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_QA_TOKEN_SALE',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863,
        client: $client
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

function qaTokenHoldTransaction(?int $holdTimeout = 604800): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_QA_TOKEN_HOLD',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863,
        holdTimeout: $holdTimeout
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

// FR-4: chargeWithToken() must support serviceUrl parity with charge()

test('chargeWithToken includes serviceUrl in payload when provided', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->chargeWithToken(qaTokenSaleTransaction(), $token, 'https://example.com/callback');

    Http::assertSent(function ($request) {
        return $request['serviceUrl'] === 'https://example.com/callback';
    });
});

test('holdChargeWithToken includes serviceUrl in payload when provided', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->holdChargeWithToken(qaTokenHoldTransaction(), $token, 'https://example.com/hold-callback');

    Http::assertSent(function ($request) {
        return $request['serviceUrl'] === 'https://example.com/hold-callback';
    });
});

test('chargeWithToken serviceUrl is merged after signing and does not change merchantSignature', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->chargeWithToken(qaTokenSaleTransaction(), $token);
    $service->chargeWithToken(qaTokenSaleTransaction(), $token, 'https://example.com/callback');

    $signatures = [];
    Http::assertSentCount(2);
    Http::assertSent(function ($request) use (&$signatures) {
        $signatures[] = $request['merchantSignature'];

        return true;
    });

    expect($signatures[0])->toBe($signatures[1]);
});

// C-4: clientIpAddress / client fields presence on the token path (documented README behavior)

test('chargeWithToken omits clientIpAddress and client fields entirely when no Client is passed', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->chargeWithToken(qaTokenSaleTransaction(client: null), $token);

    Http::assertSent(function ($request) {
        return !array_key_exists('clientIpAddress', $request->data())
            && !array_key_exists('clientEmail', $request->data())
            && !array_key_exists('clientPhone', $request->data());
    });
});

test('chargeWithToken merges client fields and defaults clientIpAddress to 127.0.0.1 without an HTTP request context', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');
    $client = new Client(nameFirst: 'John', nameLast: 'Doe', email: 'john@example.com', phone: '+380671234567');

    $service->chargeWithToken(qaTokenSaleTransaction(client: $client), $token);

    Http::assertSent(function ($request) {
        return $request['clientEmail'] === 'john@example.com'
            && $request['clientIpAddress'] === '127.0.0.1';
    });
});

// AC-2 boundary: exactly-255-char token must be accepted (only 256 was tested by dev phase)

test('CardToken accepts a token exactly at the 255-character max length boundary', function () {
    $token = new CardToken(str_repeat('a', 255));

    expect($token->getRecToken())->toHaveLength(255)
        ->and($token->toArray())->toBe(['recToken' => str_repeat('a', 255)]);
});

// AC-4/AC-5: __debugInfo() must never leak the raw token, including at the masking boundary

test('CardToken debugInfo never contains the raw token for a long token', function () {
    $raw = '550e8400-e29b-41d4-a716-446655440000';
    $token = new CardToken($raw);

    expect($token->__debugInfo()['recToken'])->not->toContain($raw);
});

test('CardToken debugInfo at the 11-character boundary partially masks without leaking the raw token', function () {
    $raw = 'abcdefghijk';
    $token = new CardToken($raw);
    $debug = $token->__debugInfo()['recToken'];

    expect($debug)->toBe('abcdef*hijk')
        ->and($debug)->not->toBe($raw);
});

test('CardToken debugInfo at the 10-character boundary fully masks', function () {
    $raw = 'abcdefghij';
    $token = new CardToken($raw);

    expect($token->__debugInfo()['recToken'])->toBe(str_repeat('*', 10));
});

// AC-12: all three documented pending reasonCodes must not throw, on the token path

test('holdChargeWithToken does not throw on pending reasonCode 1131', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'InProcessing', 'reasonCode' => 1131], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->holdChargeWithToken(qaTokenHoldTransaction(), $token);

    expect($response['reasonCode'])->toBe(1131);
});

test('holdChargeWithToken does not throw on pending reasonCode 5100', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'InProcessing', 'reasonCode' => 5100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->holdChargeWithToken(qaTokenHoldTransaction(), $token);

    expect($response['reasonCode'])->toBe(5100);
});

// AC-9/RS-4: holdTimeout guard must apply symmetrically — holdChargeWithToken() must NOT
// throw when the transaction legitimately carries holdTimeout (contrast to chargeWithToken()).

test('holdChargeWithToken does not throw InvalidArgumentException when transaction carries holdTimeout', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->holdChargeWithToken(qaTokenHoldTransaction(holdTimeout: 604800), $token);

    expect($response['reasonCode'])->toBe(5105);
});
