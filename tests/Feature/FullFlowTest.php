<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Client;
use AratKruglik\WayForPay\Services\SignatureGenerator;
use AratKruglik\WayForPay\Services\WayForPayService;

beforeEach(function () {
    Config::set('wayforpay.merchant_account', 'test_merch_n1');
    Config::set('wayforpay.merchant_domain', 'www.market.ua');
    Config::set('wayforpay.secret_key', 'flk3409refn54t54t*FNJRET');
});

test('purchase with full options generates correct form data', function () {
    $service = new WayForPayService(
        new SignatureGenerator('flk3409refn54t54t*FNJRET'),
        Http::getFacadeRoot()
    );

    $client = new Client(nameFirst: 'John', email: 'john@doe.com');

    $transaction = new Transaction(
        orderReference: 'FULL_ORD',
        amount: 500.0,
        currency: 'UAH',
        orderDate: 1234567890,
        client: $client,
        paymentSystems: 'card;googlePay',
        regularMode: 'monthly',
        regularAmount: 500.0
    );

    $transaction->addProduct(new Product('Sub', 500.0, 1));

    $formData = $service->getPurchaseFormData($transaction);

    expect($formData['merchantAccount'])->toBe('test_merch_n1')
        ->and($formData['paymentSystems'])->toBe('card;googlePay')
        ->and($formData['regularMode'])->toBe('monthly')
        ->and($formData['clientFirstName'])->toBe('John')
        ->and($formData['regularAmount'])->toBe(500.0)
        ->and($formData['amount'])->toBe(500.0)
        ->and($formData['currency'])->toBe('UAH');
});
