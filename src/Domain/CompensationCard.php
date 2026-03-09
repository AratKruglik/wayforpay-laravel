<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class CompensationCard
{
    private string $cleanCardNumber;

    public function __construct(
        public string $cardNumber,
        public ?string $expMonth = null,
        public ?string $expYear = null,
        public ?string $cvv = null,
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

        if (!Card::isValidLuhn($this->cleanCardNumber)) {
            throw new InvalidArgumentException('Invalid card number (Luhn check failed)');
        }
    }

    private function validateExpiration(): void
    {
        if ($this->expMonth !== null && !preg_match('/^(0[1-9]|1[0-2])$/', $this->expMonth)) {
            throw new InvalidArgumentException('Expiration month must be between 01 and 12');
        }

        if ($this->expYear !== null && !preg_match('/^\d{2}$/', $this->expYear)) {
            throw new InvalidArgumentException('Expiration year must be 2 digits');
        }
    }

    private function validateCvv(): void
    {
        if ($this->cvv !== null && !preg_match('/^\d{3,4}$/', $this->cvv)) {
            throw new InvalidArgumentException('CVV must be 3 or 4 digits');
        }
    }

    private function validateHolderName(): void
    {
        if ($this->holderName !== null && strlen($this->holderName) > 100) {
            throw new InvalidArgumentException('Card holder name is too long');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'compensationCardNumber' => $this->cleanCardNumber,
            'compensationCardExpMonth' => $this->expMonth,
            'compensationCardExpYear' => $this->expYear,
            'compensationCardCvv' => $this->cvv,
            'compensationCardHolder' => $this->holderName,
        ], fn($value) => $value !== null);
    }

    public function __debugInfo(): array
    {
        return [
            'cardNumber' => str_repeat('*', strlen($this->cleanCardNumber) - 4) . substr($this->cleanCardNumber, -4),
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'cvv' => $this->cvv !== null ? '***' : null,
            'holderName' => $this->holderName,
        ];
    }
}
