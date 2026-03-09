<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain\Concerns;

use InvalidArgumentException;

trait ValidatesOrderReference
{
    private static function assertValidOrderReference(string $orderReference): void
    {
        if (trim($orderReference) === '') {
            throw new InvalidArgumentException('Order reference cannot be empty');
        }

        if (strlen($orderReference) > 64) {
            throw new InvalidArgumentException('Order reference must not exceed 64 characters');
        }
    }
}
