<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

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
    ) {}

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
