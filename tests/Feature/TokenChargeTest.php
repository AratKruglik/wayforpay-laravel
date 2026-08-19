<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\CardToken;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

function makeTokenSaleTransaction(): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_TOKEN_SALE',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

function makeTokenHoldTransaction(?int $holdTimeout = 604800): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_TOKEN_HOLD',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863,
        holdTimeout: $holdTimeout
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

// AC-8: chargeWithToken (SALE) outbound payload

test('chargeWithToken sends correct request without card fields', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->chargeWithToken(makeTokenSaleTransaction(), $token);

    expect($response['transactionStatus'])->toBe('Approved');

    Http::assertSent(function ($request) {
        return $request['transactionType'] === 'CHARGE'
            && $request['merchantTransactionType'] === 'SALE'
            && $request['merchantTransactionSecureType'] === 'AUTO'
            && $request['apiVersion'] === 1
            && $request['recToken'] === '550e8400-e29b-41d4-a716-446655440000'
            && !array_key_exists('card', $request->data())
            && !array_key_exists('expMonth', $request->data())
            && !array_key_exists('expYear', $request->data())
            && !array_key_exists('cardCvv', $request->data())
            && !array_key_exists('cardHolder', $request->data())
            && isset($request['merchantSignature']);
    });
});

// AC-9: chargeWithToken must reject a Transaction carrying holdTimeout, same guard as charge()

test('chargeWithToken throws when Transaction carries holdTimeout', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    expect(fn () => $service->chargeWithToken(makeTokenHoldTransaction(), $token))
        ->toThrow(InvalidArgumentException::class, 'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge(). For token-based holds use holdChargeWithToken().');

    Http::assertNothingSent();
});

// AC-10: holdChargeWithToken (AUTH) sends merchantTransactionType=AUTH + holdTimeout, no card fields

test('holdChargeWithToken sends correct request with AUTH and holdTimeout from transaction', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->holdChargeWithToken(makeTokenHoldTransaction(), $token);

    expect($response['transactionStatus'])->toBe('WaitingAuthComplete');

    Http::assertSent(function ($request) {
        return $request['transactionType'] === 'CHARGE'
            && $request['merchantTransactionType'] === 'AUTH'
            && $request['merchantTransactionSecureType'] === 'AUTO'
            && $request['holdTimeout'] === 604800
            && $request['apiVersion'] === 1
            && $request['recToken'] === '550e8400-e29b-41d4-a716-446655440000'
            && !array_key_exists('card', $request->data());
    });
});

test('holdChargeWithToken falls back to config default_hold_timeout when transaction has none', function () {
    Config::set('wayforpay.default_hold_timeout', 86400);

    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->holdChargeWithToken(makeTokenHoldTransaction(holdTimeout: null), $token);

    Http::assertSent(function ($request) {
        return $request['holdTimeout'] === 86400;
    });
});

// AC-12: non-success reasonCode surfaces as WayForPayException; pending code does not throw

test('chargeWithToken throws WayForPayException on decline', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['reason' => 'Declined by issuer', 'reasonCode' => 1101], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $service->chargeWithToken(makeTokenSaleTransaction(), $token);
})->throws(WayForPayException::class, 'Declined by issuer');

test('holdChargeWithToken does not throw on pending reasonCode 5105', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');

    $response = $service->holdChargeWithToken(makeTokenHoldTransaction(), $token);

    expect($response['reasonCode'])->toBe(5105);
});

// AC-1/AC-2: malformed/empty recToken rejected before any HTTP call

test('CardToken rejects empty token before any network call', function () {
    Http::fake();

    expect(fn () => new CardToken(''))->toThrow(InvalidArgumentException::class, 'Card token cannot be empty');

    Http::assertNothingSent();
});

test('CardToken rejects invalid characters before any network call', function () {
    Http::fake();

    expect(fn () => new CardToken('invalid token!'))->toThrow(InvalidArgumentException::class, 'Card token contains invalid characters');

    Http::assertNothingSent();
});

// AC-13: recToken already reaches consumers unfiltered via the existing webhook event

test('webhook payload carrying recToken reaches WayForPayCallbackReceived unchanged', function () {
    Event::fake([WayForPayCallbackReceived::class]);

    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $data = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD_TOKEN_WEBHOOK',
        'amount' => '100.00',
        'currency' => 'UAH',
        'authCode' => '123456',
        'cardPan' => '4111****1111',
        'transactionStatus' => 'Approved',
        'reasonCode' => '1100',
        'recToken' => '550e8400-e29b-41d4-a716-446655440000',
    ];

    $signatureParams = [
        'merchantAccount' => $data['merchantAccount'],
        'orderReference' => $data['orderReference'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'authCode' => $data['authCode'],
        'cardPan' => $data['cardPan'],
        'transactionStatus' => $data['transactionStatus'],
        'reasonCode' => $data['reasonCode'],
    ];

    $data['merchantSignature'] = $signatureGenerator->generateForServiceUrl($signatureParams);

    $response = $service->handleWebhook($data);

    expect($response['orderReference'])->toBe('ORD_TOKEN_WEBHOOK');

    Event::assertDispatched(WayForPayCallbackReceived::class, function ($event) {
        return $event->data['recToken'] === '550e8400-e29b-41d4-a716-446655440000';
    });
});
