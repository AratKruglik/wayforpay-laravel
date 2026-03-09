<?php

use AratKruglik\WayForPay\Domain\CompensationCard;
use AratKruglik\WayForPay\Domain\CompensationAccount;
use AratKruglik\WayForPay\Domain\Partner;
use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\AccountTransfer;

// CompensationCard tests

test('compensation card creates correctly with valid data', function () {
    $card = new CompensationCard('4111111111111111', '12', '25', '123', 'John Doe');
    expect($card->cardNumber)->toBe('4111111111111111')
        ->and($card->expMonth)->toBe('12')
        ->and($card->expYear)->toBe('25')
        ->and($card->cvv)->toBe('123')
        ->and($card->holderName)->toBe('John Doe');
});

test('compensation card creates with only card number', function () {
    $card = new CompensationCard('4111111111111111');
    expect($card->expMonth)->toBeNull()
        ->and($card->expYear)->toBeNull()
        ->and($card->cvv)->toBeNull()
        ->and($card->holderName)->toBeNull();
});

test('compensation card toArray uses compensationCard prefix', function () {
    $card = new CompensationCard('4111111111111111', '12', '25', '123', 'John Doe');
    $array = $card->toArray();
    expect($array)->toBe([
        'compensationCardNumber' => '4111111111111111',
        'compensationCardExpMonth' => '12',
        'compensationCardExpYear' => '25',
        'compensationCardCvv' => '123',
        'compensationCardHolder' => 'John Doe',
    ]);
});

test('compensation card toArray omits null fields', function () {
    $card = new CompensationCard('4111111111111111');
    $array = $card->toArray();
    expect($array)->toBe(['compensationCardNumber' => '4111111111111111']);
});

test('compensation card cleans non-digit characters', function () {
    $card = new CompensationCard('4111-1111-1111-1111');
    expect($card->toArray()['compensationCardNumber'])->toBe('4111111111111111');
});

test('compensation card throws for invalid card number (too short)', function () {
    new CompensationCard('411111111111');
})->throws(InvalidArgumentException::class, 'Card number must be between 13 and 19 digits');

test('compensation card throws for failed luhn check', function () {
    new CompensationCard('4111111111111112');
})->throws(InvalidArgumentException::class, 'Invalid card number (Luhn check failed)');

test('compensation card throws for invalid expiration month', function () {
    new CompensationCard('4111111111111111', '13');
})->throws(InvalidArgumentException::class, 'Expiration month must be between 01 and 12');

test('compensation card throws for invalid expiration year', function () {
    new CompensationCard('4111111111111111', '12', '2025');
})->throws(InvalidArgumentException::class, 'Expiration year must be 2 digits');

test('compensation card throws for invalid cvv', function () {
    new CompensationCard('4111111111111111', '12', '25', '12');
})->throws(InvalidArgumentException::class, 'CVV must be 3 or 4 digits');

test('compensation card throws for too long holder name', function () {
    new CompensationCard('4111111111111111', holderName: str_repeat('A', 101));
})->throws(InvalidArgumentException::class, 'Card holder name is too long');

test('compensation card debugInfo masks sensitive data', function () {
    $card = new CompensationCard('4111111111111111', '12', '25', '123');
    $debug = $card->__debugInfo();
    expect($debug['cardNumber'])->toBe('************1111')
        ->and($debug['cvv'])->toBe('***');
});

// CompensationAccount tests

test('compensation account creates correctly', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company', '305299');
    expect($account->iban)->toBe('UA213223130000026007233566001')
        ->and($account->okpo)->toBe('12345678')
        ->and($account->name)->toBe('Test Company')
        ->and($account->mfo)->toBe('305299');
});

test('compensation account toArray uses compensationAccount prefix', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company', '305299');
    $array = $account->toArray();
    expect($array)->toBe([
        'compensationAccountIban' => 'UA213223130000026007233566001',
        'compensationAccountMfo' => '305299',
        'compensationAccountOkpo' => '12345678',
        'compensationAccountName' => 'Test Company',
    ]);
});

test('compensation account toArray omits null mfo', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company');
    expect($account->toArray())->not->toHaveKey('compensationAccountMfo');
});

test('compensation account throws for invalid iban format', function () {
    new CompensationAccount('INVALID', '12345678', 'Test');
})->throws(InvalidArgumentException::class, 'Invalid IBAN format');

test('compensation account throws for invalid iban checksum', function () {
    new CompensationAccount('UA003223130000026007233566001', '12345678', 'Test');
})->throws(InvalidArgumentException::class, 'Invalid IBAN checksum');

test('compensation account throws for invalid okpo (wrong length)', function () {
    new CompensationAccount('UA213223130000026007233566001', '12345', 'Test');
})->throws(InvalidArgumentException::class, 'OKPO must be 8, 10, or 14 digits');

test('compensation account accepts 8-digit okpo', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test');
    expect($account->okpo)->toBe('12345678');
});

test('compensation account accepts 10-digit okpo', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '1234567890', 'Test');
    expect($account->okpo)->toBe('1234567890');
});

test('compensation account accepts 14-digit okpo', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678901234', 'Test');
    expect($account->okpo)->toBe('12345678901234');
});

test('compensation account throws for empty name', function () {
    new CompensationAccount('UA213223130000026007233566001', '12345678', '');
})->throws(InvalidArgumentException::class, 'Account name cannot be empty');

test('compensation account throws for invalid mfo', function () {
    new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test', '12345');
})->throws(InvalidArgumentException::class, 'MFO must be 6 digits');

test('compensation account debugInfo masks sensitive data', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test Company');
    $debug = $account->__debugInfo();
    expect($debug['iban'])->toStartWith('UA21')
        ->and($debug['iban'])->toEndWith('6001')
        ->and($debug['iban'])->toContain('*')
        ->and($debug['okpo'])->toBe('******78');
});

// Partner tests

test('partner creates correctly', function () {
    $partner = new Partner('P001', 'https://example.com', '+380501234567', 'test@example.com');
    expect($partner->partnerCode)->toBe('P001')
        ->and($partner->site)->toBe('https://example.com')
        ->and($partner->phone)->toBe('+380501234567')
        ->and($partner->email)->toBe('test@example.com');
});

test('partner creates with all optional fields', function () {
    $card = new CompensationCard('4111111111111111');
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test');
    $partner = new Partner(
        'P001', 'https://example.com', '+380501234567', 'test@example.com',
        'A description', $card, $account, 'token123'
    );
    expect($partner->description)->toBe('A description')
        ->and($partner->compensationCard)->toBe($card)
        ->and($partner->compensationAccount)->toBe($account)
        ->and($partner->compensationCardToken)->toBe('token123');
});

test('partner toArray merges compensation dtos', function () {
    $card = new CompensationCard('4111111111111111');
    $partner = new Partner('P001', 'https://example.com', '+380501234567', 'test@example.com', compensationCard: $card);
    $array = $partner->toArray();
    expect($array)->toHaveKey('partnerCode', 'P001')
        ->and($array)->toHaveKey('compensationCardNumber', '4111111111111111');
});

test('partner throws for empty partner code', function () {
    new Partner('', 'https://example.com', '+380501234567', 'test@example.com');
})->throws(InvalidArgumentException::class, 'Partner code cannot be empty');

test('partner throws for invalid site url', function () {
    new Partner('P001', 'not-a-url', '+380501234567', 'test@example.com');
})->throws(InvalidArgumentException::class, 'Site must be a valid URL');

test('partner throws for invalid phone', function () {
    new Partner('P001', 'https://example.com', 'abc', 'test@example.com');
})->throws(InvalidArgumentException::class, 'Invalid phone format');

test('partner throws for invalid email', function () {
    new Partner('P001', 'https://example.com', '+380501234567', 'not-email');
})->throws(InvalidArgumentException::class, 'Invalid email format');

// Merchant tests

test('merchant creates correctly', function () {
    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com');
    expect($merchant->site)->toBe('https://shop.com')
        ->and($merchant->phone)->toBe('+380501234567')
        ->and($merchant->email)->toBe('shop@example.com');
});

test('merchant creates with compensation card token', function () {
    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com', compensationCardToken: 'token123');
    expect($merchant->compensationCardToken)->toBe('token123');
});

test('merchant toArray merges compensation dtos', function () {
    $account = new CompensationAccount('UA213223130000026007233566001', '12345678', 'Test');
    $merchant = new Merchant('https://shop.com', '+380501234567', 'shop@example.com', compensationAccount: $account);
    $array = $merchant->toArray();
    expect($array)->toHaveKey('site', 'https://shop.com')
        ->and($array)->toHaveKey('compensationAccountIban', 'UA213223130000026007233566001');
});

test('merchant throws for invalid site url', function () {
    new Merchant('bad-url', '+380501234567', 'shop@example.com');
})->throws(InvalidArgumentException::class, 'Site must be a valid URL');

test('merchant throws for invalid phone', function () {
    new Merchant('https://shop.com', 'abc', 'shop@example.com');
})->throws(InvalidArgumentException::class, 'Invalid phone format');

test('merchant throws for invalid email', function () {
    new Merchant('https://shop.com', '+380501234567', 'bad-email');
})->throws(InvalidArgumentException::class, 'Invalid email format');

// AccountTransfer tests

test('account transfer creates correctly', function () {
    $transfer = new AccountTransfer(
        'ORD123', 1000.50, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Receiver Name'
    );
    expect($transfer->orderReference)->toBe('ORD123')
        ->and($transfer->amount)->toBe(1000.50)
        ->and($transfer->currency)->toBe('UAH')
        ->and($transfer->iban)->toBe('UA213223130000026007233566001')
        ->and($transfer->okpo)->toBe('12345678')
        ->and($transfer->accountName)->toBe('Receiver Name');
});

test('account transfer creates with optional fields', function () {
    $transfer = new AccountTransfer(
        'ORD123', 1000.0, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Receiver',
        'Payment for services', 'https://callback.com/webhook',
        'Doe', '+380501234567', 'recipient@example.com'
    );
    expect($transfer->description)->toBe('Payment for services')
        ->and($transfer->serviceUrl)->toBe('https://callback.com/webhook')
        ->and($transfer->recipientLastName)->toBe('Doe')
        ->and($transfer->recipientPhone)->toBe('+380501234567')
        ->and($transfer->recipientEmail)->toBe('recipient@example.com');
});

test('account transfer toArray returns correct structure', function () {
    $transfer = new AccountTransfer(
        'ORD123', 1000.0, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Receiver'
    );
    $array = $transfer->toArray();
    expect($array)->toBe([
        'orderReference' => 'ORD123',
        'amount' => 1000.0,
        'currency' => 'UAH',
        'iban' => 'UA213223130000026007233566001',
        'okpo' => '12345678',
        'accountName' => 'Receiver',
    ]);
});

test('account transfer toArray includes optional fields when present', function () {
    $transfer = new AccountTransfer(
        'ORD123', 1000.0, 'UAH',
        'UA213223130000026007233566001', '12345678', 'Receiver',
        description: 'Test payment'
    );
    expect($transfer->toArray())->toHaveKey('description', 'Test payment');
});

test('account transfer throws for empty order reference', function () {
    new AccountTransfer('', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'Order reference cannot be empty');

test('account transfer throws for order reference exceeding 64 chars', function () {
    new AccountTransfer(str_repeat('A', 65), 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'Order reference must not exceed 64 characters');

test('account transfer throws for zero amount', function () {
    new AccountTransfer('ORD1', 0.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'Amount must be greater than 0');

test('account transfer throws for negative amount', function () {
    new AccountTransfer('ORD1', -100.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'Amount must be greater than 0');

test('account transfer throws for non-UAH currency', function () {
    new AccountTransfer('ORD1', 1000.0, 'USD', 'UA213223130000026007233566001', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'P2P_ACCOUNT transfers only support UAH currency');

test('account transfer throws for invalid iban', function () {
    new AccountTransfer('ORD1', 1000.0, 'UAH', 'INVALID', '12345678', 'Receiver');
})->throws(InvalidArgumentException::class, 'Invalid IBAN format');

test('account transfer throws for invalid okpo', function () {
    new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345', 'Receiver');
})->throws(InvalidArgumentException::class, 'OKPO must be 8, 10, or 14 digits');

test('account transfer accepts 14-digit okpo', function () {
    $transfer = new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678901234', 'Receiver');
    expect($transfer->okpo)->toBe('12345678901234');
});

test('account transfer throws for empty account name', function () {
    new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', '');
})->throws(InvalidArgumentException::class, 'Account name cannot be empty');

test('account transfer throws for invalid service url', function () {
    new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver', serviceUrl: 'bad-url');
})->throws(InvalidArgumentException::class, 'Service URL must be a valid URL');

test('account transfer throws for invalid recipient email', function () {
    new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver', recipientEmail: 'bad-email');
})->throws(InvalidArgumentException::class, 'Invalid recipient email format');

test('account transfer debugInfo masks sensitive data', function () {
    $transfer = new AccountTransfer('ORD1', 1000.0, 'UAH', 'UA213223130000026007233566001', '12345678', 'Receiver');
    $debug = $transfer->__debugInfo();
    expect($debug['iban'])->toStartWith('UA21')
        ->and($debug['iban'])->toContain('*')
        ->and($debug['okpo'])->toBe('******78');
});
