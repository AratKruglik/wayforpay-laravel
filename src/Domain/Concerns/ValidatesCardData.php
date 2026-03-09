<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain\Concerns;

use AratKruglik\WayForPay\Domain\Card;
use InvalidArgumentException;

trait ValidatesCardData
{
    private static function assertValidCardNumber(string $cleanNumber): void
    {
        $length = strlen($cleanNumber);
        if ($length < 13 || $length > 19) {
            throw new InvalidArgumentException('Card number must be between 13 and 19 digits');
        }

        if (!Card::isValidLuhn($cleanNumber)) {
            throw new InvalidArgumentException('Invalid card number (Luhn check failed)');
        }
    }

    private static function assertValidExpMonth(string $expMonth): void
    {
        if (!preg_match('/^(0[1-9]|1[0-2])$/', $expMonth)) {
            throw new InvalidArgumentException('Expiration month must be between 01 and 12');
        }
    }

    private static function assertValidExpYear(string $expYear): void
    {
        if (!preg_match('/^\d{2}$/', $expYear)) {
            throw new InvalidArgumentException('Expiration year must be 2 digits');
        }
    }

    private static function assertValidCvv(string $cvv): void
    {
        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            throw new InvalidArgumentException('CVV must be 3 or 4 digits');
        }
    }

    private static function assertValidHolderName(string $holderName): void
    {
        if (strlen($holderName) > 100) {
            throw new InvalidArgumentException('Card holder name is too long');
        }
    }
}
