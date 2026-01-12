<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

class Transaction
{
    /** @var Product[] */
    private array $products = [];

    public function __construct(
        public readonly string $orderReference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly int $orderDate,
        public readonly ?Client $client = null,
        public readonly ?string $paymentSystems = null, // e.g. "card;googlePay;applePay"
        public readonly ?string $defaultPaymentSystem = null,
        public readonly ?int $orderTimeout = null,
        public readonly ?int $orderLifetime = null,
        public readonly ?string $regularMode = null, // daily, weekly, monthly, etc.
        public readonly ?string $regularOn = null,
        public readonly ?string $dateNext = null, // DD.MM.YYYY
        public readonly ?string $dateEnd = null,
        public readonly ?int $regularCount = null,
        public readonly ?float $regularAmount = null
    ) {}

    public function addProduct(Product $product): self
    {
        $this->products[] = $product;
        return $this;
    }

    /**
     * @param Product[] $products
     */
    public function setProducts(array $products): self
    {
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                throw new InvalidArgumentException('All items must be instances of Product');
            }
        }
        $this->products = $products;
        return $this;
    }

    public function getProducts(): array
    {
        return $this->products;
    }
}
