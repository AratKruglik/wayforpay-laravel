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
        $this->validateOrderReference();
        $this->validatePositive($this->amount, 'Amount');
        $this->validateCurrency();
        $this->validatePositive($this->orderDate, 'Order date', 'Order date must be a valid Unix timestamp');
        $this->validatePositive($this->orderTimeout, 'Order timeout');
        $this->validatePositive($this->orderLifetime, 'Order lifetime');
        $this->validatePositive($this->regularAmount, 'Regular amount');
        $this->validateMinimum($this->regularCount, 'Regular count', 1);
    }

    private function validateOrderReference(): void
    {
        if (trim($this->orderReference) === '') {
            throw new InvalidArgumentException('Order reference cannot be empty');
        }

        if (strlen($this->orderReference) > 64) {
            throw new InvalidArgumentException('Order reference is too long (max 64 characters)');
        }
    }

    private function validateCurrency(): void
    {
        if (!in_array($this->currency, self::VALID_CURRENCIES, true)) {
            throw new InvalidArgumentException(
                'Invalid currency. Supported: ' . implode(', ', self::VALID_CURRENCIES)
            );
        }
    }

    private function validatePositive(
        int|float|null $value,
        string $fieldName,
        ?string $errorMessage = null
    ): void {
        if ($value === null) {
            return;
        }

        if ($value <= 0) {
            throw new InvalidArgumentException($errorMessage ?? "{$fieldName} must be greater than 0");
        }
    }

    private function validateMinimum(?int $value, string $fieldName, int $minimum): void
    {
        if ($value !== null && $value < $minimum) {
            throw new InvalidArgumentException("{$fieldName} must be at least {$minimum}");
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
