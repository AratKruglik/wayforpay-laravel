<?php

use AratKruglik\WayForPay\Domain\Client;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;

test('product dto creates correctly', function () {
    $product = new Product('Test Item', 100.50, 2);
    expect($product->name)->toBe('Test Item')
        ->and($product->price)->toBe(100.50)
        ->and($product->count)->toBe(2);
});

test('client dto handles nullable fields', function () {
    $client = new Client(nameFirst: 'John');
    expect($client->nameFirst)->toBe('John')
        ->and($client->email)->toBeNull();
    
    $array = $client->toArray();
    expect($array)->toBe(['clientFirstName' => 'John']);
});

test('transaction manages products', function () {
    $transaction = new Transaction('REF123', 200.0, 'UAH', time());
    
    $transaction->addProduct(new Product('Item 1', 100.0, 1));
    $transaction->addProduct(new Product('Item 2', 100.0, 1));
    
    expect($transaction->getProducts())->toHaveCount(2);
});
