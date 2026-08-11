<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

function makeHoldTransaction(?int $holdTimeout = 604800): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_HOLD',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863,
        holdTimeout: $holdTimeout
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

function makeSaleTransaction(): Transaction
{
    $transaction = new Transaction(
        orderReference: 'ORD_SALE',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863
    );
    $transaction->addProduct(new Product('Item', 100.0, 1));

    return $transaction;
}

// AC-5 / AC-7: getHoldFormData / hold widget payload

test('getHoldFormData contains merchantTransactionType AUTH and holdTimeout', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $formData = $service->getHoldFormData(makeHoldTransaction());

    expect($formData['merchantTransactionType'])->toBe('AUTH')
        ->and($formData['holdTimeout'])->toBe(604800);
});

test('hold returns HTML containing hidden input for merchantTransactionType AUTH', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $html = $service->hold(makeHoldTransaction());

    expect($html)->toContain('name="merchantTransactionType"')
        ->and($html)->toContain('value="AUTH"');
});

// AC-6 (reformulated): merchantSignature identical between getHoldFormData (hold transaction)
// and getPurchaseFormData (otherwise-identical transaction WITHOUT holdTimeout)

test('merchantSignature from getHoldFormData equals merchantSignature from getPurchaseFormData on an equivalent non-hold transaction', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $holdTransaction = makeHoldTransaction();
    $plainTransaction = new Transaction(
        orderReference: 'ORD_HOLD',
        amount: 100.0,
        currency: 'UAH',
        orderDate: 1415379863
    );
    $plainTransaction->addProduct(new Product('Item', 100.0, 1));

    $holdFormData = $service->getHoldFormData($holdTransaction);
    $purchaseFormData = $service->getPurchaseFormData($plainTransaction);

    expect($holdFormData['merchantSignature'])->toBe($purchaseFormData['merchantSignature'])
        ->and($purchaseFormData)->not->toHaveKey('merchantTransactionType');
});

// AC-8: holdCharge outbound payload

test('holdCharge sends correct request', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $card = new Card('4111111111111111', '12', '25', '123', 'John Doe');

    $response = $service->holdCharge(makeHoldTransaction(), $card);

    expect($response['transactionStatus'])->toBe('WaitingAuthComplete');

    Http::assertSent(function ($request) {
        return $request['transactionType'] === 'CHARGE'
            && $request['merchantTransactionType'] === 'AUTH'
            && $request['merchantTransactionSecureType'] === 'AUTO'
            && $request['holdTimeout'] === 604800
            && $request['apiVersion'] === 1
            && $request['card'] === '4111111111111111'
            && $request['expMonth'] === '12'
            && $request['expYear'] === '25'
            && $request['cardCvv'] === '123'
            && isset($request['merchantSignature']);
    });
});

// AC-9: config default hold timeout resolution

test('holdCharge falls back to config default_hold_timeout when transaction has none', function () {
    Config::set('wayforpay.default_hold_timeout', 86400);

    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    $service->holdCharge(makeHoldTransaction(holdTimeout: null), $card);

    Http::assertSent(function ($request) {
        return $request['holdTimeout'] === 86400;
    });
});

test('holdCharge transaction holdTimeout wins over config default', function () {
    Config::set('wayforpay.default_hold_timeout', 86400);

    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    $service->holdCharge(makeHoldTransaction(holdTimeout: 3600), $card);

    Http::assertSent(function ($request) {
        return $request['holdTimeout'] === 3600;
    });
});

test('out-of-range config default_hold_timeout throws InvalidArgumentException at call time', function () {
    Config::set('wayforpay.default_hold_timeout', 30);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    $service->holdCharge(makeHoldTransaction(holdTimeout: null), $card);
})->throws(InvalidArgumentException::class, 'Hold timeout must be between 60 and 1728000');

// P-1 critical invariant: config default must not affect non-hold calls

test('config default_hold_timeout does not break plain purchase, charge or createInvoice', function () {
    Config::set('wayforpay.default_hold_timeout', 86400);

    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    expect(fn () => $service->getPurchaseFormData(makeSaleTransaction()))->not->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->purchase(makeSaleTransaction()))->not->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->charge(makeSaleTransaction(), $card))->not->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->createInvoice(makeSaleTransaction()))->not->toThrow(InvalidArgumentException::class);
});

// Non-AUTH guard: holdTimeout on Transaction not allowed for purchase/charge/createInvoice

test('getPurchaseFormData throws when Transaction carries holdTimeout', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->getPurchaseFormData(makeHoldTransaction());
})->throws(InvalidArgumentException::class, 'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge().');

test('purchase throws when Transaction carries holdTimeout', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->purchase(makeHoldTransaction());
})->throws(InvalidArgumentException::class, 'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge().');

test('charge throws when Transaction carries holdTimeout', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    $service->charge(makeHoldTransaction(), $card);
})->throws(InvalidArgumentException::class, 'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge().');

test('createInvoice throws when Transaction carries holdTimeout', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->createInvoice(makeHoldTransaction());
})->throws(InvalidArgumentException::class, 'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge().');

// Regression: charge()/getPurchaseFormData() unaffected when no holdTimeout present

test('regression: charge without holdTimeout still sends merchantTransactionType SALE and no holdTimeout key', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());
    $card = new Card('4111111111111111', '12', '25', '123');

    $service->charge(makeSaleTransaction(), $card);

    Http::assertSent(function ($request) {
        return $request['merchantTransactionType'] === 'SALE'
            && !array_key_exists('holdTimeout', $request->data());
    });
});

test('regression: getPurchaseFormData contains no merchantTransactionType key', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $formData = $service->getPurchaseFormData(makeSaleTransaction());

    expect($formData)->not->toHaveKey('merchantTransactionType');
});

// AC-12: settle with optional products

test('settle with products sends productName, productPrice and productCount', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH', products: [
        new Product('Item A', 30.0, 1),
        new Product('Item B', 30.0, 1),
    ]);

    Http::assertSent(function ($request) {
        return $request['productName'] === ['Item A', 'Item B']
            && $request['productPrice'] === [30.0, 30.0]
            && $request['productCount'] === [1, 1];
    });
});

test('settle without products argument does not send product keys at all', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH');

    Http::assertSent(function ($request) {
        return !array_key_exists('productName', $request->data())
            && !array_key_exists('productPrice', $request->data())
            && !array_key_exists('productCount', $request->data());
    });
});

test('settle with empty products array behaves as null', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH', products: []);

    Http::assertSent(function ($request) {
        return !array_key_exists('productName', $request->data());
    });
});

test('settle throws InvalidArgumentException when a products element is not a Product', function () {
    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH', products: ['not a product']);
})->throws(InvalidArgumentException::class, 'All items must be instances of Product');

test('settle sends partial amount exactly as given', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Approved', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH');

    Http::assertSent(function ($request) {
        return $request['amount'] === 60.0;
    });
});

// AC-13: cancelHold

test('cancelHold sends transactionType REFUND with default comment', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'Refunded', 'reasonCode' => 1100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->cancelHold('ORD_AUTH', 100.0, 'UAH');

    Http::assertSent(function ($request) {
        return $request['transactionType'] === 'REFUND'
            && $request['comment'] === 'Hold cancelled';
    });
});

// Fixture-driven pending guard (asserts OUR enum wiring against a fake response,
// not WayForPay's real documented behaviour)

test('fixture-driven: reasonCode 5100 does not throw from parseResponse', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5100], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $response = $service->settle('ORD_AUTH', 60.0, 'UAH');

    expect($response['reasonCode'])->toBe(5100);
});

test('fixture-driven: reasonCode 5105 does not throw from parseResponse', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'WaitingAuthComplete', 'reasonCode' => 5105], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $response = $service->settle('ORD_AUTH', 60.0, 'UAH');

    expect($response['reasonCode'])->toBe(5105);
});

test('fixture-driven: reasonCode 1131 does not throw from parseResponse', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['transactionStatus' => 'InProcessing', 'reasonCode' => 1131], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $response = $service->settle('ORD_AUTH', 60.0, 'UAH');

    expect($response['reasonCode'])->toBe(1131);
});

test('fixture-driven: a genuine decline (1101) still throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(['reason' => 'Declined by issuer', 'reasonCode' => 1101], 200),
    ]);

    $service = new WayForPayService(new SignatureGenerator('flk3409refn54t54t*FNJRET'), Http::getFacadeRoot());

    $service->settle('ORD_AUTH', 60.0, 'UAH');
})->throws(WayForPayException::class, 'Declined by issuer');
