<?php

use AratKruglik\WayForPay\Enums\ReasonCode;

test('reason code enum has correct values', function () {
    expect(ReasonCode::OK->value)->toBe(1100)
        ->and(ReasonCode::DECLINED_BY_ISSUER->value)->toBe(1101)
        ->and(ReasonCode::REGULAR_OK->value)->toBe(4100);
});

test('reason code enum has correct success checks', function () {
    expect(ReasonCode::OK->isSuccess())->toBeTrue()
        ->and(ReasonCode::REGULAR_OK->isSuccess())->toBeTrue()
        ->and(ReasonCode::BAD_CVV2->isSuccess())->toBeFalse();
});

test('reason code enum gives correct description', function () {
    expect(ReasonCode::OK->getDescription())->toBe('Operation was performed without errors')
        ->and(ReasonCode::BAD_CVV2->getDescription())->toBe('Wrong CVV code');
});

test('reason code can be parsed from integer', function () {
    $code = ReasonCode::tryFrom(1100);
    expect($code)->toBe(ReasonCode::OK);
    
    $invalid = ReasonCode::tryFrom(9999);
    expect($invalid)->toBeNull();
});
