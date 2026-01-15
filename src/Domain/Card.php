<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class Card
{
    private string $cleanCardNumber;

    public function __construct(
        public string $cardNumber,
        public string $expMonth,
        public string $expYear,
        public string $cvv,
        public ?string $holderName = null
    ) {
        $this->cleanCardNumber = preg_replace('/\D/', '', $this->cardNumber);
        $this->validate();
    }

    private function validate(): void
    {
        $this->validateCardNumber();
        $this->validateExpiration();
        $this->validateCvv();
        $this->validateHolderName();
    }

    private function validateCardNumber(): void
    {
        $length = strlen($this->cleanCardNumber);
        if ($length < 13 || $length > 19) {
            throw new InvalidArgumentException('Card number must be between 13 and 19 digits');
        }

        if (!$this->isValidLuhn($this->cleanCardNumber)) {
            throw new InvalidArgumentException('Invalid card number (Luhn check failed)');
        }
    }

    private function validateExpiration(): void
    {
        if (!preg_match('/^(0[1-9]|1[0-2])$/', $this->expMonth)) {
            throw new InvalidArgumentException('Expiration month must be between 01 and 12');
        }

        if (!preg_match('/^\d{2}$/', $this->expYear)) {
            throw new InvalidArgumentException('Expiration year must be 2 digits');
        }
    }

    private function validateCvv(): void
    {
        if (!preg_match('/^\d{3,4}$/', $this->cvv)) {
            throw new InvalidArgumentException('CVV must be 3 or 4 digits');
        }
    }

    private function validateHolderName(): void
    {
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
        return array_filter([
            'card' => $this->cleanCardNumber,
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'cardCvv' => $this->cvv,
            'cardHolder' => $this->holderName,
        ], fn($value) => $value !== null);
    }
}
