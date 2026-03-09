<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use AratKruglik\WayForPay\Domain\Concerns\ValidatesOrderReference;
use InvalidArgumentException;

readonly class AccountTransfer
{
    use ValidatesOrderReference;
    public function __construct(
        public string $orderReference,
        public float $amount,
        public string $currency,
        public string $iban,
        public string $okpo,
        public string $accountName,
        public ?string $description = null,
        public ?string $serviceUrl = null,
        public ?string $recipientLastName = null,
        public ?string $recipientPhone = null,
        public ?string $recipientEmail = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        self::assertValidOrderReference($this->orderReference);
        $this->validateAmount();
        $this->validateCurrency();
        CompensationAccount::validateIban($this->iban);
        CompensationAccount::validateOkpo($this->okpo);
        $this->validateAccountName();
        $this->validateServiceUrl();
        $this->validateRecipientEmail();
    }

    private function validateAmount(): void
    {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0');
        }
    }

    private function validateCurrency(): void
    {
        if ($this->currency !== 'UAH') {
            throw new InvalidArgumentException('P2P_ACCOUNT transfers only support UAH currency');
        }
    }

    private function validateAccountName(): void
    {
        if (trim($this->accountName) === '') {
            throw new InvalidArgumentException('Account name cannot be empty');
        }
    }

    private function validateServiceUrl(): void
    {
        if ($this->serviceUrl !== null && filter_var($this->serviceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Service URL must be a valid URL');
        }
    }

    private function validateRecipientEmail(): void
    {
        if ($this->recipientEmail !== null && !filter_var($this->recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email format');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'orderReference' => $this->orderReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'iban' => $this->iban,
            'okpo' => $this->okpo,
            'accountName' => $this->accountName,
            'description' => $this->description,
            'serviceUrl' => $this->serviceUrl,
            'recipientLastName' => $this->recipientLastName,
            'recipientPhone' => $this->recipientPhone,
            'recipientEmail' => $this->recipientEmail,
        ], fn($value) => $value !== null);
    }

    public function __debugInfo(): array
    {
        return [
            'orderReference' => $this->orderReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'iban' => CompensationAccount::maskIban($this->iban),
            'okpo' => CompensationAccount::maskOkpo($this->okpo),
            'accountName' => $this->accountName,
            'description' => $this->description,
            'serviceUrl' => $this->serviceUrl,
            'recipientLastName' => $this->recipientLastName,
            'recipientPhone' => $this->recipientPhone,
            'recipientEmail' => $this->recipientEmail,
        ];
    }
}
