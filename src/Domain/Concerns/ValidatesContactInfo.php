<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain\Concerns;

use InvalidArgumentException;

trait ValidatesContactInfo
{
    private static function assertValidPhone(string $phone): void
    {
        if (!preg_match('/^\+?[\d\s\-()]{6,20}$/', $phone)) {
            throw new InvalidArgumentException('Invalid phone format');
        }
    }

    private static function assertValidEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }
    }

    private static function assertValidUrl(string $url, string $fieldName = 'URL'): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("{$fieldName} must be a valid URL");
        }
    }
}
