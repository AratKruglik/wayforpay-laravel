<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

readonly class Product
{
    public function __construct(
        public string $name,
        public float $price,
        public int $count
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Product name cannot be empty');
        }

        if (strlen($this->name) > 255) {
            throw new InvalidArgumentException('Product name is too long (max 255 characters)');
        }

        if ($this->price < 0) {
            throw new InvalidArgumentException('Product price cannot be negative');
        }

        if ($this->count < 1) {
            throw new InvalidArgumentException('Product count must be at least 1');
        }
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'count' => $this->count,
        ];
    }
}
