<?php

use AratKruglik\WayForPay\Enums\PartnerStatus;
use AratKruglik\WayForPay\Enums\CompensationType;

test('partner status enum has correct values', function () {
    expect(PartnerStatus::NEW->value)->toBe('New')
        ->and(PartnerStatus::ACTIVE->value)->toBe('Active')
        ->and(PartnerStatus::BLOCKED->value)->toBe('Blocked');
});

test('partner status can be created from string', function () {
    expect(PartnerStatus::from('New'))->toBe(PartnerStatus::NEW)
        ->and(PartnerStatus::from('Active'))->toBe(PartnerStatus::ACTIVE)
        ->and(PartnerStatus::from('Blocked'))->toBe(PartnerStatus::BLOCKED);
});

test('partner status tryFrom returns null for unknown value', function () {
    expect(PartnerStatus::tryFrom('Unknown'))->toBeNull();
});

test('compensation type enum has correct values', function () {
    expect(CompensationType::CARD->value)->toBe('card')
        ->and(CompensationType::ACCOUNT->value)->toBe('account');
});

test('compensation type can be created from string', function () {
    expect(CompensationType::from('card'))->toBe(CompensationType::CARD)
        ->and(CompensationType::from('account'))->toBe(CompensationType::ACCOUNT);
});

test('compensation type tryFrom returns null for unknown value', function () {
    expect(CompensationType::tryFrom('crypto'))->toBeNull();
});
