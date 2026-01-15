<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class Card
{
    public function __construct(
        public string $cardNumber,
        public string $expMonth,
        public string $expYear,
        public string $cvv,
        public ?string $holderName = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        $cleanCardNumber = preg_replace('/\D/', '', $this->cardNumber);

        if (strlen($cleanCardNumber) < 13 || strlen($cleanCardNumber) > 19) {
            throw new InvalidArgumentException('Card number must be between 13 and 19 digits');
        }

        if (!$this->isValidLuhn($cleanCardNumber)) {
            throw new InvalidArgumentException('Invalid card number (Luhn check failed)');
        }

        if (!preg_match('/^(0[1-9]|1[0-2])$/', $this->expMonth)) {
            throw new InvalidArgumentException('Expiration month must be between 01 and 12');
        }

        if (!preg_match('/^\d{2}$/', $this->expYear)) {
            throw new InvalidArgumentException('Expiration year must be 2 digits');
        }

        if (!preg_match('/^\d{3,4}$/', $this->cvv)) {
            throw new InvalidArgumentException('CVV must be 3 or 4 digits');
        }

        if ($this->holderName !== null && strlen($this->holderName) > 100) {
            throw new InvalidArgumentException('Card holder name is too long');
        }
    }

    private function isValidLuhn(string $number): bool
    {
        $sum = 0;
        $isEven = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($isEven) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $isEven = !$isEven;
        }

        return ($sum % 10) === 0;
    }

    public function toArray(): array
    {
        $cleanCardNumber = preg_replace('/\D/', '', $this->cardNumber);

        return array_filter([
            'card' => $cleanCardNumber,
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'cardCvv' => $this->cvv,
            'cardHolder' => $this->holderName,
        ], fn($value) => !is_null($value));
    }
}
