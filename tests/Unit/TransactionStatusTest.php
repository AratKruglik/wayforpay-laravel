<?php

use AratKruglik\WayForPay\Enums\TransactionStatus;

test('transaction status enum has correct helpers', function () {
    expect(TransactionStatus::APPROVED->isSuccess())->toBeTrue()
        ->and(TransactionStatus::DECLINED->isSuccess())->toBeFalse()
        ->and(TransactionStatus::APPROVED->isFinal())->toBeTrue()
        ->and(TransactionStatus::PENDING->isFinal())->toBeFalse();
});

test('transaction status can be parsed from string', function () {
    $status = TransactionStatus::tryFrom('Approved');
    expect($status)->toBe(TransactionStatus::APPROVED);
});
