<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain\Concerns;

use InvalidArgumentException;

trait ValidatesCurrency
{
    private const VALID_CURRENCIES = ['UAH', 'USD', 'EUR', 'PLN', 'GBP'];

    private static function assertValidCurrency(string $currency): void
    {
        if (!in_array($currency, self::VALID_CURRENCIES, true)) {
            throw new InvalidArgumentException(
                'Invalid currency. Supported: ' . implode(', ', self::VALID_CURRENCIES)
            );
        }
    }
}
