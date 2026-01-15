<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;
use AratKruglik\WayForPay\Exceptions\SignatureMismatchException;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

function createValidWebhookData(SignatureGenerator $signatureGenerator): array
{
    $data = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD123',
        'amount' => '100.00',
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

test('webhook processes valid signature and dispatches event', function () {
    Event::fake([WayForPayCallbackReceived::class]);

    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = createValidWebhookData($signatureGenerator);

    $response = $service->handleWebhook($webhookData);

    expect($response)->toHaveKey('orderReference')
        ->and($response['orderReference'])->toBe('ORD123')
        ->and($response)->toHaveKey('status')
        ->and($response['status'])->toBe('accept')
        ->and($response)->toHaveKey('time')
        ->and($response)->toHaveKey('signature');

    Event::assertDispatched(WayForPayCallbackReceived::class, function ($event) {
        return $event->data['orderReference'] === 'ORD123'
            && $event->data['transactionStatus'] === 'Approved';
    });
});

test('webhook throws SignatureMismatchException for invalid signature', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD123',
        'amount' => '100.00',
        'currency' => 'UAH',
        'transactionStatus' => 'Approved',
        'merchantSignature' => 'invalid_signature_here',
    ];

    $service->handleWebhook($webhookData);
})->throws(SignatureMismatchException::class, 'Invalid webhook signature');

test('webhook throws exception for missing merchantAccount', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'orderReference' => 'ORD123',
        'transactionStatus' => 'Approved',
        'merchantSignature' => 'some_signature',
    ];

    $service->handleWebhook($webhookData);
})->throws(WayForPayException::class, 'Missing required webhook field: merchantAccount');

test('webhook throws exception for missing orderReference', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'merchantAccount' => 'test_merch_n1',
        'transactionStatus' => 'Approved',
        'merchantSignature' => 'some_signature',
    ];

    $service->handleWebhook($webhookData);
})->throws(WayForPayException::class, 'Missing required webhook field: orderReference');

test('webhook throws exception for missing transactionStatus', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD123',
        'merchantSignature' => 'some_signature',
    ];

    $service->handleWebhook($webhookData);
})->throws(WayForPayException::class, 'Missing required webhook field: transactionStatus');

test('webhook throws exception for missing merchantSignature', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD123',
        'transactionStatus' => 'Approved',
    ];

    $service->handleWebhook($webhookData);
})->throws(WayForPayException::class, 'Missing required webhook field: merchantSignature');

test('webhook throws exception for empty required field', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = [
        'merchantAccount' => '',
        'orderReference' => 'ORD123',
        'transactionStatus' => 'Approved',
        'merchantSignature' => 'some_signature',
    ];

    $service->handleWebhook($webhookData);
})->throws(WayForPayException::class, 'Missing required webhook field: merchantAccount');

test('webhook response contains valid signature', function () {
    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    $webhookData = createValidWebhookData($signatureGenerator);

    $response = $service->handleWebhook($webhookData);

    // Verify that response signature is correctly generated
    $expectedSignature = $signatureGenerator->generateResponseSignature(
        $response['orderReference'],
        $response['status'],
        $response['time']
    );

    expect($response['signature'])->toBe($expectedSignature);
});

test('webhook handles optional fields gracefully', function () {
    Event::fake([WayForPayCallbackReceived::class]);

    $signatureGenerator = new SignatureGenerator('flk3409refn54t54t*FNJRET');
    $service = new WayForPayService($signatureGenerator, Http::getFacadeRoot());

    // Minimal required data without optional fields
    $data = [
        'merchantAccount' => 'test_merch_n1',
        'orderReference' => 'ORD123',
        'transactionStatus' => 'Approved',
    ];

    $signatureParams = [
        'merchantAccount' => $data['merchantAccount'],
        'orderReference' => $data['orderReference'],
        'amount' => '',
        'currency' => '',
        'authCode' => '',
        'cardPan' => '',
        'transactionStatus' => $data['transactionStatus'],
        'reasonCode' => '',
    ];

    $data['merchantSignature'] = $signatureGenerator->generateForServiceUrl($signatureParams);

    $response = $service->handleWebhook($data);

    expect($response['orderReference'])->toBe('ORD123')
        ->and($response['status'])->toBe('accept');

    Event::assertDispatched(WayForPayCallbackReceived::class);
});
