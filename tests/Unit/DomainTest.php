<?php

use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Domain\CardToken;
use AratKruglik\WayForPay\Domain\Client;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;

// Product tests

test('product dto creates correctly', function () {
    $product = new Product('Test Item', 100.50, 2);
    expect($product->name)->toBe('Test Item')
        ->and($product->price)->toBe(100.50)
        ->and($product->count)->toBe(2);
});

test('product throws exception for empty name', function () {
    new Product('', 100.0, 1);
})->throws(InvalidArgumentException::class, 'Product name cannot be empty');

test('product throws exception for negative price', function () {
    new Product('Item', -10.0, 1);
})->throws(InvalidArgumentException::class, 'Product price cannot be negative');

test('product throws exception for zero count', function () {
    new Product('Item', 100.0, 0);
})->throws(InvalidArgumentException::class, 'Product count must be at least 1');

test('product allows zero price', function () {
    $product = new Product('Free Item', 0.0, 1);
    expect($product->price)->toBe(0.0);
});

// Client tests

test('client dto handles nullable fields', function () {
    $client = new Client(nameFirst: 'John');
    expect($client->nameFirst)->toBe('John')
        ->and($client->email)->toBeNull();

    $array = $client->toArray();
    expect($array)->toBe(['clientFirstName' => 'John']);
});

test('client throws exception for invalid email', function () {
    new Client(email: 'not-an-email');
})->throws(InvalidArgumentException::class, 'Invalid email format');

test('client throws exception for invalid phone', function () {
    new Client(phone: 'abc');
})->throws(InvalidArgumentException::class, 'Invalid phone format');

test('client accepts valid email and phone', function () {
    $client = new Client(
        email: 'test@example.com',
        phone: '+380501234567'
    );
    expect($client->email)->toBe('test@example.com')
        ->and($client->phone)->toBe('+380501234567');
});

test('client throws exception for invalid country code', function () {
    new Client(country: 'Ukraine');
})->throws(InvalidArgumentException::class, 'Country must be a 2-3 letter ISO code');

test('client accepts valid country code', function () {
    $client = new Client(country: 'UA');
    expect($client->country)->toBe('UA');
});

// Transaction tests

test('transaction manages products', function () {
    $transaction = new Transaction('REF123', 200.0, 'UAH', time());

    $transaction->addProduct(new Product('Item 1', 100.0, 1));
    $transaction->addProduct(new Product('Item 2', 100.0, 1));

    expect($transaction->getProducts())->toHaveCount(2);
});

test('transaction throws exception for empty order reference', function () {
    new Transaction('', 100.0, 'UAH', time());
})->throws(InvalidArgumentException::class, 'Order reference cannot be empty');

test('transaction throws exception for zero amount', function () {
    new Transaction('ORD1', 0.0, 'UAH', time());
})->throws(InvalidArgumentException::class, 'Amount must be greater than 0');

test('transaction throws exception for negative amount', function () {
    new Transaction('ORD1', -50.0, 'UAH', time());
})->throws(InvalidArgumentException::class, 'Amount must be greater than 0');

test('transaction throws exception for invalid currency', function () {
    new Transaction('ORD1', 100.0, 'INVALID', time());
})->throws(InvalidArgumentException::class, 'Invalid currency');

test('transaction accepts valid currencies', function () {
    $currencies = ['UAH', 'USD', 'EUR', 'PLN', 'GBP'];
    foreach ($currencies as $currency) {
        $transaction = new Transaction('ORD1', 100.0, $currency, time());
        expect($transaction->currency)->toBe($currency);
    }
});

test('transaction throws exception when no products added', function () {
    $transaction = new Transaction('ORD1', 100.0, 'UAH', time());
    $transaction->getProducts();
})->throws(InvalidArgumentException::class, 'Transaction must have at least one product');

test('transaction throws exception for invalid order date', function () {
    new Transaction('ORD1', 100.0, 'UAH', -1);
})->throws(InvalidArgumentException::class, 'Order date must be a valid Unix timestamp');

// holdTimeout tests

test('transaction accepts holdTimeout at minimum boundary', function () {
    $transaction = new Transaction('ORD1', 100.0, 'UAH', time(), holdTimeout: 60);
    expect($transaction->holdTimeout)->toBe(60);
});

test('transaction accepts holdTimeout at maximum boundary', function () {
    $transaction = new Transaction('ORD1', 100.0, 'UAH', time(), holdTimeout: 1728000);
    expect($transaction->holdTimeout)->toBe(1728000);
});

test('transaction accepts null holdTimeout', function () {
    $transaction = new Transaction('ORD1', 100.0, 'UAH', time());
    expect($transaction->holdTimeout)->toBeNull();
});

test('transaction throws exception for holdTimeout below minimum', function () {
    new Transaction('ORD1', 100.0, 'UAH', time(), holdTimeout: 59);
})->throws(InvalidArgumentException::class, 'Hold timeout must be between 60 and 1728000');

test('transaction throws exception for holdTimeout above maximum', function () {
    new Transaction('ORD1', 100.0, 'UAH', time(), holdTimeout: 1728001);
})->throws(InvalidArgumentException::class, 'Hold timeout must be between 60 and 1728000');

// Card tests

test('card dto creates correctly with valid data', function () {
    // Using a test card number that passes Luhn (Visa test card)
    $card = new Card('4111111111111111', '12', '25', '123', 'John Doe');
    expect($card->cardNumber)->toBe('4111111111111111')
        ->and($card->expMonth)->toBe('12')
        ->and($card->expYear)->toBe('25')
        ->and($card->cvv)->toBe('123')
        ->and($card->holderName)->toBe('John Doe');
});

test('card toArray returns clean card number', function () {
    $card = new Card('4111-1111-1111-1111', '12', '25', '123');
    $array = $card->toArray();
    expect($array['card'])->toBe('4111111111111111');
});

test('card throws exception for invalid card number (too short)', function () {
    new Card('411111111111', '12', '25', '123');
})->throws(InvalidArgumentException::class, 'Card number must be between 13 and 19 digits');

test('card throws exception for invalid card number (luhn check)', function () {
    new Card('4111111111111112', '12', '25', '123');
})->throws(InvalidArgumentException::class, 'Invalid card number (Luhn check failed)');

test('card throws exception for invalid expiration month', function () {
    new Card('4111111111111111', '13', '25', '123');
})->throws(InvalidArgumentException::class, 'Expiration month must be between 01 and 12');

test('card throws exception for invalid expiration year format', function () {
    new Card('4111111111111111', '12', '2025', '123');
})->throws(InvalidArgumentException::class, 'Expiration year must be 2 digits');

test('card throws exception for invalid CVV', function () {
    new Card('4111111111111111', '12', '25', '12');
})->throws(InvalidArgumentException::class, 'CVV must be 3 or 4 digits');

test('card accepts 4-digit CVV (AMEX)', function () {
    // AMEX test card that passes Luhn
    $card = new Card('378282246310005', '12', '25', '1234');
    expect($card->cvv)->toBe('1234');
});

test('card debugInfo masks sensitive data', function () {
    $card = new Card('4111111111111111', '12', '25', '123');
    $debug = $card->__debugInfo();
    expect($debug['cardNumber'])->toBe('************1111')
        ->and($debug['cvv'])->toBe('***');
});

// CardToken tests

test('cardToken throws exception for empty string', function () {
    new CardToken('');
})->throws(InvalidArgumentException::class, 'Card token cannot be empty');

test('cardToken throws exception for whitespace-only string', function () {
    new CardToken('   ');
})->throws(InvalidArgumentException::class, 'Card token cannot be empty');

test('cardToken throws exception for token exceeding max length', function () {
    new CardToken(str_repeat('a', 256));
})->throws(InvalidArgumentException::class, 'Card token cannot exceed 255 characters');

test('cardToken throws exception for invalid characters', function () {
    new CardToken('tok en/with spaces?');
})->throws(InvalidArgumentException::class, 'Card token contains invalid characters');

test('cardToken accepts a valid UUID-like token', function () {
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');
    expect($token->getRecToken())->toBe('550e8400-e29b-41d4-a716-446655440000');
});

test('cardToken toArray returns exactly recToken key', function () {
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');
    expect($token->toArray())->toBe(['recToken' => '550e8400-e29b-41d4-a716-446655440000']);
});

test('cardToken debugInfo masks middle of a long token', function () {
    $token = new CardToken('550e8400-e29b-41d4-a716-446655440000');
    $debug = $token->__debugInfo();

    expect($debug['recToken'])->toStartWith('550e84')
        ->and($debug['recToken'])->toEndWith('0000')
        ->and($debug['recToken'])->not->toContain('e29b-41d4-a716');
});

test('cardToken debugInfo fully masks a short token without warning or leak', function () {
    $token = new CardToken('abc123');
    $debug = $token->__debugInfo();

    expect($debug['recToken'])->toBe('******')
        ->and($debug['recToken'])->not->toContain('abc123');
});
