<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use AratKruglik\WayForPay\Domain\Concerns\ValidatesOrderReference;
use InvalidArgumentException;

class Transaction
{
    use ValidatesOrderReference;
    private const VALID_CURRENCIES = ['UAH', 'USD', 'EUR', 'PLN', 'GBP'];
    public const HOLD_TIMEOUT_MIN = 60;
    public const HOLD_TIMEOUT_MAX = 1728000;

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
        public readonly ?float $regularAmount = null,
        public readonly ?int $holdTimeout = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        self::assertValidOrderReference($this->orderReference);
        $this->validatePositive($this->amount, 'Amount');
        $this->validateCurrency();
        $this->validatePositive($this->orderDate, 'Order date', 'Order date must be a valid Unix timestamp');
        $this->validatePositive($this->orderTimeout, 'Order timeout');
        $this->validatePositive($this->orderLifetime, 'Order lifetime');
        $this->validatePositive($this->regularAmount, 'Regular amount');
        $this->validateMinimum($this->regularCount, 'Regular count', 1);
        $this->validateRange($this->holdTimeout, 'Hold timeout', self::HOLD_TIMEOUT_MIN, self::HOLD_TIMEOUT_MAX);
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

    private function validateRange(?int $value, string $fieldName, int $min, int $max): void
    {
        if ($value === null) {
            return;
        }

        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("{$fieldName} must be between {$min} and {$max}");
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
