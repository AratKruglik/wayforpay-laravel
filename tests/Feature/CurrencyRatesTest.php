<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\CurrencyRates;
use AratKruglik\WayForPay\Enums\ReasonCode;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');

    $this->service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );
});

function fakeCurrencyRatesResponse(): array
{
    return [
        'REASONCODE' => 1100,
        'REASON' => 'OK',
        'RATESDATE' => 1519115604,
        'RATES' => [
            'AUD' => 19.41,
            'BTC' => 192278,
            'CAD' => 19.44,
            'CHF' => 27.08,
            'CNY' => 3.85,
            'CZK' => 1.11,
            'EUR' => 29.76,
            'GBP' => 33.78,
            'HKD' => 3.37,
            'ILS' => 7.39,
            'JPY' => 0.23,
            'KZT' => 0.08,
            'PLN' => 7.04,
            'SGD' => 18.98,
            'USD' => 26.45,
        ],
    ];
}

test('AC-1: sends correct request payload', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.wayforpay.com/api'
            && $request['transactionType'] === 'CURRENCY_RATES'
            && $request['merchantAccount'] === 'test_merch_n1'
            && $request['orderDate'] === 1519885604
            && $request['apiVersion'] === 1;
    });
});

test('AC-2: signs request with the correct signature', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604);

    $expected = hash_hmac('md5', 'test_merch_n1;1519885604', 'flk3409refn54t54t*FNJRET');

    Http::assertSent(fn ($request) => $request['merchantSignature'] === $expected);
});

test('AC-3: signature is unaffected by currency filter', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604, 'USD');

    $expected = hash_hmac('md5', 'test_merch_n1;1519885604', 'flk3409refn54t54t*FNJRET');

    Http::assertSent(fn ($request) => $request['merchantSignature'] === $expected);
});

test('AC-4: currency key omitted when not provided', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604);

    Http::assertSent(fn ($request) => !isset($request['currency']));
});

test('AC-4: currency is sent uppercased', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604, 'CHF');

    Http::assertSent(fn ($request) => $request['currency'] === 'CHF');

    $this->service->getCurrencyRates(1519885604, 'usd');

    Http::assertSent(fn ($request) => ($request['currency'] ?? null) === 'USD');
});

test('AC-5: returns all rates as floats', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604);

    expect($rates->rates)->toHaveCount(15)
        ->and($rates->rates['EUR'])->toBe(29.76)
        ->and($rates->rates['BTC'])->toBe(192278.0)
        ->and($rates->rates['BTC'])->toBeFloat();

    foreach ($rates->rates as $rate) {
        expect($rate)->toBeFloat();
    }
});

test('AC-6: ratesDate comes from RATESDATE, not orderDate', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604);

    expect($rates->ratesDate)->toBe(1519115604)
        ->and($rates->ratesDate)->not->toBe(1519885604);
});

test('currency filter returns exactly the requested currency', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604, 'USD');

    expect($rates->rates)->toBe(['USD' => 26.45]);
});

test('currency filter returns empty map when currency is absent from RATES', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604, 'ZZZ');

    expect($rates->rates)->toBe([]);
});

test('missing RATES returns empty map', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
        ], 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604);

    expect($rates->rates)->toBe([]);
});

test('null RATES returns empty map', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
            'RATES' => null,
        ], 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604);

    expect($rates->rates)->toBe([]);
});

test('empty object RATES returns empty map', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
            'RATES' => [],
        ], 200),
    ]);

    $rates = $this->service->getCurrencyRates(1519885604);

    expect($rates->rates)->toBe([]);
});

test('RATES as a non-array value throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
            'RATES' => 'oops',
        ], 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class, 'Invalid RATES in currency rates response');
});

test('a non-numeric rate value throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
            'RATES' => ['USD' => '26.45'],
        ], 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class, 'Invalid RATES in currency rates response');
});

test('a malformed currency code that survives normalization is rethrown as WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATESDATE' => 1519115604,
            'RATES' => ['US' => 1.0],
        ], 200),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getMessage())->toBe('Malformed currency rates response')
            ->and($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
});

test('missing RATESDATE throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1100,
            'REASON' => 'OK',
            'RATES' => ['USD' => 26.45],
        ], 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class, 'Missing or invalid RATESDATE in currency rates response');
});

test('AC-7: uppercase REASONCODE 1109 maps to FORMAT_ERROR', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1109,
            'REASON' => 'Format error',
        ], 200),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getReasonCode())->toBe(ReasonCode::FORMAT_ERROR)
            ->and($e->getMessage())->toBe('Format error')
            ->and($e->getResponseData())->toBe([
                'REASONCODE' => 1109,
                'REASON' => 'Format error',
            ]);
    }
});

test('AC-7: uppercase REASONCODE 1124 maps to SIGNATURE_MISMATCH', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1124,
            'REASON' => 'Signature is wrong',
        ], 200),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getReasonCode())->toBe(ReasonCode::SIGNATURE_MISMATCH)
            ->and($e->getMessage())->toBe('Signature is wrong');
    }
});

test('AC-8: unknown REASONCODE throws WayForPayException with null reasonCode', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 9999,
            'REASON' => 'Unknown failure',
        ], 200),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getReasonCode())->toBeNull();
    }
});

test('camelCase reasonCode 1124 is also treated as a failure', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'reasonCode' => 1124,
            'reason' => 'Signature is wrong',
        ], 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class);
});

test('response without any reason code throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'RATESDATE' => 1519115604,
            'RATES' => ['USD' => 26.45],
        ], 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class, 'Currency rates request failed');
});

test('non-string REASON on error response throws WayForPayException with fallback message', function (mixed $reason) {
    Http::fake([
        'api.wayforpay.com/api' => Http::response([
            'REASONCODE' => 1109,
            'REASON' => $reason,
        ], 200),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getMessage())->toBe('Currency rates request failed')
            ->and($e->getReasonCode())->toBe(ReasonCode::FORMAT_ERROR);
    }
})->with([
    'integer' => [12345],
    'array' => [[]],
    'boolean' => [false],
    'empty string' => [''],
]);

test('AC-9: HTTP 500 throws WayForPayException with status in response data', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response('Internal Server Error', 500),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getMessage())->toBe('API request failed')
            ->and($e->getResponseData()['status'])->toBe(500);
    }
});

test('non-JSON 200 response throws WayForPayException, not TypeError', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response('<html>Not JSON</html>', 200),
    ]);

    expect(fn () => $this->service->getCurrencyRates(1519885604))
        ->toThrow(WayForPayException::class, 'Malformed currency rates response');
});

test('infinite rate value in raw response throws WayForPayException', function () {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(
            '{"REASONCODE":1100,"RATESDATE":1519115604,"RATES":{"USD":1e400}}',
            200
        ),
    ]);

    try {
        $this->service->getCurrencyRates(1519885604);
        $this->fail('Expected WayForPayException was not thrown');
    } catch (WayForPayException $e) {
        expect($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
});

test('AC-10: orderDate zero throws InvalidArgumentException and sends nothing', function () {
    Http::fake();

    expect(fn () => $this->service->getCurrencyRates(0))
        ->toThrow(InvalidArgumentException::class, 'orderDate must be a positive unix timestamp');

    Http::assertNothingSent();
});

test('AC-10: negative orderDate throws InvalidArgumentException and sends nothing', function () {
    Http::fake();

    expect(fn () => $this->service->getCurrencyRates(-1))
        ->toThrow(InvalidArgumentException::class, 'orderDate must be a positive unix timestamp');

    Http::assertNothingSent();
});

test('AC-11: invalid currency codes throw InvalidArgumentException and send nothing', function (string $invalidCurrency) {
    Http::fake();

    expect(fn () => $this->service->getCurrencyRates(1519885604, $invalidCurrency))
        ->toThrow(InvalidArgumentException::class, 'Currency must be a 3-letter code');

    Http::assertNothingSent();
})->with([
    '',
    'us',
    'US D',
    'USDT',
    "USD\n",
]);

test('AC-11: valid 3-letter currency codes are accepted', function (string $validCurrency) {
    Http::fake([
        'api.wayforpay.com/api' => Http::response(fakeCurrencyRatesResponse(), 200),
    ]);

    $this->service->getCurrencyRates(1519885604, $validCurrency);

    Http::assertSent(fn ($request) => ($request['currency'] ?? null) === strtoupper($validCurrency));
})->with(['BTC', 'CHF']);
