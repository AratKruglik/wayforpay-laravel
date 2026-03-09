<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use AratKruglik\WayForPay\Domain\Concerns\ValidatesCardData;
use InvalidArgumentException;

readonly class CompensationCard
{
    use ValidatesCardData;
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
        self::assertValidCardNumber($this->cleanCardNumber);
    }

    private function validateExpiration(): void
    {
        if ($this->expMonth !== null) {
            self::assertValidExpMonth($this->expMonth);
        }
        if ($this->expYear !== null) {
            self::assertValidExpYear($this->expYear);
        }
    }

    private function validateCvv(): void
    {
        if ($this->cvv !== null) {
            self::assertValidCvv($this->cvv);
        }
    }

    private function validateHolderName(): void
    {
        if ($this->holderName !== null) {
            self::assertValidHolderName($this->holderName);
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
