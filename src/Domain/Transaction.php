<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;

class Transaction
{
    private const VALID_CURRENCIES = ['UAH', 'USD', 'EUR', 'PLN', 'GBP'];

    /** @var Product[] */
    private array $products = [];

    public function __construct(
        public readonly string $orderReference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly int $orderDate,
        public readonly ?Client $client = null,
        public readonly ?string $paymentSystems = null,
        public readonly ?string $defaultPaymentSystem = null,
        public readonly ?int $orderTimeout = null,
        public readonly ?int $orderLifetime = null,
        public readonly ?string $regularMode = null,
        public readonly ?string $regularOn = null,
        public readonly ?string $dateNext = null,
        public readonly ?string $dateEnd = null,
        public readonly ?int $regularCount = null,
        public readonly ?float $regularAmount = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (trim($this->orderReference) === '') {
            throw new InvalidArgumentException('Order reference cannot be empty');
        }

        if (strlen($this->orderReference) > 64) {
            throw new InvalidArgumentException('Order reference is too long (max 64 characters)');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0');
        }

        if (!in_array($this->currency, self::VALID_CURRENCIES, true)) {
            throw new InvalidArgumentException(
                'Invalid currency. Supported: ' . implode(', ', self::VALID_CURRENCIES)
            );
        }

        if ($this->orderDate <= 0) {
            throw new InvalidArgumentException('Order date must be a valid Unix timestamp');
        }

        if ($this->orderTimeout !== null && $this->orderTimeout <= 0) {
            throw new InvalidArgumentException('Order timeout must be positive');
        }

        if ($this->orderLifetime !== null && $this->orderLifetime <= 0) {
            throw new InvalidArgumentException('Order lifetime must be positive');
        }

        if ($this->regularAmount !== null && $this->regularAmount <= 0) {
            throw new InvalidArgumentException('Regular amount must be greater than 0');
        }

        if ($this->regularCount !== null && $this->regularCount < 1) {
            throw new InvalidArgumentException('Regular count must be at least 1');
        }
    }

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

    /**
     * @return Product[]
     * @throws InvalidArgumentException if no products are added
     */
    public function getProducts(): array
    {
        if (empty($this->products)) {
            throw new InvalidArgumentException('Transaction must have at least one product');
        }
        return $this->products;
    }
}
