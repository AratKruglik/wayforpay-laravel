<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class CurrencyRates
{
    public function __construct(
        public int $ratesDate,
        public array $rates
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->ratesDate <= 0) {
            throw new InvalidArgumentException('Rates date must be a positive unix timestamp');
        }

        foreach ($this->rates as $currency => $rate) {
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException('Currency rate key must be a 3-letter uppercase code');
            }

            if (!is_float($rate) || !is_finite($rate) || $rate < 0.0) {
                throw new InvalidArgumentException('Currency rate value must be a finite non-negative float');
            }
        }
    }
}
