<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class CompensationAccount
{
    public function __construct(
        public string $iban,
        public string $okpo,
        public string $name,
        public ?string $mfo = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        self::validateIban($this->iban);
        self::validateOkpo($this->okpo);
        $this->validateName();
        $this->validateMfo();
    }

    public static function validateIban(string $iban): void
    {
        $cleaned = str_replace(' ', '', strtoupper($iban));

        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $cleaned)) {
            throw new InvalidArgumentException('Invalid IBAN format');
        }

        $rearranged = substr($cleaned, 4) . substr($cleaned, 0, 4);

        $charMap = array_combine(range('A', 'Z'), range(10, 35));
        $numeric = strtr($rearranged, array_map('strval', $charMap));

        if (self::bcmod97($numeric) !== 1) {
            throw new InvalidArgumentException('Invalid IBAN checksum');
        }
    }

    private static function bcmod97(string $number): int
    {
        $remainder = 0;
        for ($i = 0; $i < strlen($number); $i++) {
            $remainder = ($remainder * 10 + (int) $number[$i]) % 97;
        }
        return $remainder;
    }

    public static function validateOkpo(string $okpo): void
    {
        if (!preg_match('/^\d{8}$|^\d{10}$|^\d{14}$/', $okpo)) {
            throw new InvalidArgumentException('OKPO must be 8, 10, or 14 digits');
        }
    }

    private function validateName(): void
    {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Account name cannot be empty');
        }
    }

    private function validateMfo(): void
    {
        if ($this->mfo !== null && !preg_match('/^\d{6}$/', $this->mfo)) {
            throw new InvalidArgumentException('MFO must be 6 digits');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'compensationAccountIban' => $this->iban,
            'compensationAccountMfo' => $this->mfo,
            'compensationAccountOkpo' => $this->okpo,
            'compensationAccountName' => $this->name,
        ], fn($value) => $value !== null);
    }

    public static function maskIban(string $iban): string
    {
        return substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
    }

    public static function maskOkpo(string $okpo): string
    {
        return str_repeat('*', strlen($okpo) - 2) . substr($okpo, -2);
    }

    public function __debugInfo(): array
    {
        return [
            'iban' => self::maskIban($this->iban),
            'okpo' => self::maskOkpo($this->okpo),
            'name' => $this->name,
            'mfo' => $this->mfo,
        ];
    }
}
