<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use AratKruglik\WayForPay\Domain\Concerns\ValidatesCardData;
use InvalidArgumentException;

readonly class Card
{
    use ValidatesCardData;
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
        self::assertValidCardNumber($this->cleanCardNumber);
    }

    private function validateExpiration(): void
    {
        self::assertValidExpMonth($this->expMonth);
        self::assertValidExpYear($this->expYear);
    }

    private function validateCvv(): void
    {
        self::assertValidCvv($this->cvv);
    }

    private function validateHolderName(): void
    {
        if ($this->holderName !== null) {
            self::assertValidHolderName($this->holderName);
        }
    }

    public static function isValidLuhn(string $number): bool
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

    public function __debugInfo(): array
    {
        return [
            'cardNumber' => str_repeat('*', strlen($this->cleanCardNumber) - 4) . substr($this->cleanCardNumber, -4),
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'cvv' => '***',
            'holderName' => $this->holderName,
        ];
    }
}
