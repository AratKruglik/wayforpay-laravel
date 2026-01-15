<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class Client
{
    public function __construct(
        public ?string $nameFirst = null,
        public ?string $nameLast = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $country = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->email !== null && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }

        if ($this->phone !== null && !preg_match('/^\+?[\d\s\-()]{6,20}$/', $this->phone)) {
            throw new InvalidArgumentException('Invalid phone format');
        }

        if ($this->nameFirst !== null && strlen($this->nameFirst) > 100) {
            throw new InvalidArgumentException('First name is too long (max 100 characters)');
        }

        if ($this->nameLast !== null && strlen($this->nameLast) > 100) {
            throw new InvalidArgumentException('Last name is too long (max 100 characters)');
        }

        if ($this->country !== null && !preg_match('/^[A-Z]{2,3}$/', $this->country)) {
            throw new InvalidArgumentException('Country must be a 2-3 letter ISO code');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'clientFirstName' => $this->nameFirst,
            'clientLastName' => $this->nameLast,
            'clientEmail' => $this->email,
            'clientPhone' => $this->phone,
            'clientAddress' => $this->address,
            'clientCity' => $this->city,
            'clientCountry' => $this->country,
        ], fn($value) => !is_null($value));
    }
}
