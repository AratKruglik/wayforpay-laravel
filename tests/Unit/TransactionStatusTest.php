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

test('WaitingAuthComplete is a recognized status with hold semantics', function () {
    $status = TransactionStatus::tryFrom('WaitingAuthComplete');

    expect($status)->not->toBeNull()
        ->and($status->isHold())->toBeTrue()
        ->and($status->isFinal())->toBeFalse()
        ->and($status->isSuccess())->toBeFalse();
});

test('WaitingAmountConfirm is also a hold status', function () {
    expect(TransactionStatus::WAITING_CONFIRM->isHold())->toBeTrue();
});

test('regression: approved status remains final and successful', function () {
    expect(TransactionStatus::APPROVED->isFinal())->toBeTrue()
        ->and(TransactionStatus::APPROVED->isSuccess())->toBeTrue();
});

test('non-hold statuses are not reported as hold', function () {
    expect(TransactionStatus::DECLINED->isHold())->toBeFalse()
        ->and(TransactionStatus::APPROVED->isHold())->toBeFalse();
});
