<?php

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Http\Controllers\WebhookController;
use AratKruglik\WayForPay\Services\Concerns\HandlesApiResponse;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

class VerifyFormAndWebhookFixesApiResponseHarness
{
    use HandlesApiResponse;

    public function parse(Response $response, string $returnKey): array|string
    {
        return $this->parseResponse($response, 'Test', $returnKey);
    }
}

function buildWebhookPayload(SignatureGenerator $signatureGenerator, string $orderReference): array
{
    $data = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => $orderReference,
        'amount' => 1.5,
        'currency' => 'UAH',
        'authCode' => '123456',
        'cardPan' => '4111****1111',
        'transactionStatus' => 'Approved',
        'reasonCode' => '1100',
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

    return $data;
}

function buildMangledFormFieldRequest(string $json): Request
{
    $mangledKey = str_replace(['.', ' '], '_', $json);

    return Request::create(
        '/wayforpay/webhook',
        'POST',
        [$mangledKey => ''],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        $json
    );
}

function buildProperlyTypedJsonRequest(string $json): Request
{
    return Request::create(
        '/wayforpay/webhook',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $json
    );
}

test('getVerifyFormData contains returnUrl, serviceUrl, amount = 0 and paymentSystem = lookupCard', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $formData = $service->getVerifyFormData(
        'ORD_VERIFY_1',
        'https://shop.example.com/return',
        'https://shop.example.com/service'
    );

    expect($formData['returnUrl'])->toBe('https://shop.example.com/return')
        ->and($formData['serviceUrl'])->toBe('https://shop.example.com/service')
        ->and($formData['amount'])->toBe(0)
        ->and($formData['paymentSystem'])->toBe('lookupCard')
        ->and($formData)->toHaveKey('merchantSignature');
});

test('getVerifyFormData without serviceUrl omits the key', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $formData = $service->getVerifyFormData('ORD_VERIFY_NO_SERVICE', 'https://shop.example.com/return');

    expect($formData)->not->toHaveKey('serviceUrl');
});

test('getVerifyFormData merchantSignature is unaffected by returnUrl and serviceUrl', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $formData = $service->getVerifyFormData(
        'ORD_VERIFY_SIG',
        'https://shop.example.com/return',
        'https://shop.example.com/service'
    );

    $expectedSignature = hash_hmac(
        'md5',
        'test_merch_n1;www.market.ua;ORD_VERIFY_SIG;0;UAH',
        'flk3409refn54t54t*FNJRET'
    );

    expect($formData['merchantSignature'])->toBe($expectedSignature);
});

test('verify renders HTML form posting to the verify endpoint, not pay', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $html = $service->verify('ORD_VERIFY_HTML', 'https://shop.example.com/return');

    expect($html)->toContain('action="https://secure.wayforpay.com/verify"')
        ->and($html)->not->toContain('action="https://secure.wayforpay.com/pay"')
        ->and($html)->toContain('name="paymentSystem"')
        ->and($html)->toContain('value="lookupCard"');
});

test('verifyCard throws LogicException pointing to the replacement API', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    expect(fn () => $service->verifyCard('ORD_LEGACY'))
        ->toThrow(LogicException::class);

    try {
        $service->verifyCard('ORD_LEGACY');
        $this->fail('Exception was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())->toContain('getVerifyFormData')
            ->and($e->getMessage())->toContain('verify');
    }
});

test('parseResponse without the expected returnKey carries both reason and reasonCode', function () {
    // reasonCode 1100 (success) is deliberate: any failure/decline code would already
    // throw earlier in the `reasonCode` branch (parseResponse's first check) and never
    // reach the `returnKey` branch that bug 2 actually modified. Do not "fix" this to a
    // decline code (e.g. 1101) — that would silently stop testing the branch under test.
    Http::fake([
        '*' => Http::response([
            'reasonCode' => 1100,
            'reason' => 'Ok',
            'someOtherField' => 'value',
        ], 200),
    ]);

    $response = Http::get('http://test-endpoint.local/response');

    $harness = new VerifyFormAndWebhookFixesApiResponseHarness();

    try {
        $harness->parse($response, 'expectedKeyThatIsMissing');
        test()->fail('Exception was not thrown');
    } catch (\AratKruglik\WayForPay\Exceptions\WayForPayException $e) {
        expect($e->getReasonCode()?->value)->toBe(1100)
            ->and($e->getResponseData()['reason'])->toBe('Ok')
            ->and($e->getResponseData()['someOtherField'])->toBe('value');
    }
});

test('handleWebhookRequest decodes a JSON body that arrived mangled as a form-field name', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $data = buildWebhookPayload($signatureGenerator, 'ORD_MANGLED');
    $json = json_encode($data);

    $mangledRequest = buildMangledFormFieldRequest($json);
    $properRequest = buildProperlyTypedJsonRequest($json);

    expect($mangledRequest->all())->not->toHaveKey('merchantAccount');

    $responseFromMangled = $service->handleWebhookRequest($mangledRequest);
    $responseFromProper = $service->handleWebhookRequest($properRequest);

    // 'time'/'signature' are excluded from the equality check because buildWebhookResponse()
    // stamps time() on every call; the two handleWebhookRequest() invocations above can
    // straddle a second boundary, which would make a raw equality comparison flaky.
    expect($responseFromMangled['orderReference'])->toBe('ORD_MANGLED')
        ->and($responseFromMangled['status'])->toBe('accept')
        ->and(Arr::except($responseFromMangled, ['time', 'signature']))
            ->toBe(Arr::except($responseFromProper, ['time', 'signature']))
        ->and($responseFromMangled['signature'])->toBe(
            $signatureGenerator->generateResponseSignature(
                $responseFromMangled['orderReference'],
                'accept',
                $responseFromMangled['time']
            )
        );
});

test('WebhookController accepts a JSON body with no JSON content-type and returns the signed accept response', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());
    $controller = new WebhookController($service);

    $data = buildWebhookPayload($signatureGenerator, 'ORD_CONTROLLER_MANGLED');
    $json = json_encode($data);

    $request = buildMangledFormFieldRequest($json);

    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);

    $payload = $response->getData(true);

    expect($payload['orderReference'])->toBe('ORD_CONTROLLER_MANGLED')
        ->and($payload['status'])->toBe('accept')
        ->and($payload['signature'])->toBe(
            $signatureGenerator->generateResponseSignature(
                $payload['orderReference'],
                'accept',
                $payload['time']
            )
        );
});

test('WebhookController returns 400, not 500, for a non-scalar required webhook field', function () {
    $json = json_encode([
        'merchantAccount' => 'x',
        'orderReference' => 'y',
        'transactionStatus' => 'z',
        'merchantSignature' => ['a'],
    ]);

    $request = buildProperlyTypedJsonRequest($json);

    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );
    $controller = new WebhookController($service);

    $response = $controller($request);

    expect($response->getStatusCode())->toBe(400);

    $payload = $response->getData(true);

    expect($payload)->toBe([
        'status' => 'error',
        'message' => 'Missing required webhook field: merchantSignature',
    ]);
});

test('WebhookController accepts numeric orderReference and transactionStatus with a valid signature', function () {
    $secretKey = 'flk3409refn54t54t*FNJRET';

    $data = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 12345,
        'amount' => '100.00',
        'currency' => 'UAH',
        'authCode' => '123456',
        'cardPan' => '4111****1111',
        'transactionStatus' => 1100,
        'reasonCode' => '1100',
    ];

    $signatureConcatenated = implode(';', [
        $data['merchantAccount'],
        $data['orderReference'],
        $data['amount'],
        $data['currency'],
        $data['authCode'],
        $data['cardPan'],
        $data['transactionStatus'],
        $data['reasonCode'],
    ]);
    $data['merchantSignature'] = hash_hmac('md5', $signatureConcatenated, $secretKey);

    $json = json_encode($data);
    $request = buildProperlyTypedJsonRequest($json);

    $signatureGenerator = new SignatureGenerator($secretKey);
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());
    $controller = new WebhookController($service);

    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);

    $payload = $response->getData(true);

    expect($payload['orderReference'])->toBe('12345')
        ->and($payload['status'])->toBe('accept')
        ->and($payload['signature'])->toBe(
            $signatureGenerator->generateResponseSignature(
                $payload['orderReference'],
                'accept',
                $payload['time']
            )
        );
});
