<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

readonly class Card
{
    public function __construct(
        public string $cardNumber,
        public string $expMonth,
        public string $expYear,
        public string $cvv,
        public ?string $holderName = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'card' => $this->cardNumber,
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'cardCvv' => $this->cvv,
            'cardHolder' => $this->holderName,
        ], fn($value) => !is_null($value));
    }
}
