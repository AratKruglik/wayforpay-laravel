<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

readonly class Product
{
    public function __construct(
        public string $name,
        public float $price,
        public int $count
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'count' => $this->count,
        ];
    }
}
