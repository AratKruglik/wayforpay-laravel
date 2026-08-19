<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use InvalidArgumentException;
use SensitiveParameter;

readonly class CardToken
{
    private const MAX_LENGTH = 255;
    private const VALID_PATTERN = '/^[A-Za-z0-9._:\-]+$/D';

    public function __construct(
        #[SensitiveParameter]
        private string $recToken
    ) {
        $this->validate();
    }

    public function getRecToken(): string
    {
        return $this->recToken;
    }

    private function validate(): void
    {
        if (trim($this->recToken) === '') {
            throw new InvalidArgumentException('Card token cannot be empty');
        }

        if (strlen($this->recToken) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Card token cannot exceed 255 characters');
        }

        if (!preg_match(self::VALID_PATTERN, $this->recToken)) {
            throw new InvalidArgumentException('Card token contains invalid characters');
        }
    }

    public function toArray(): array
    {
        return ['recToken' => $this->recToken];
    }

    public function __debugInfo(): array
    {
        return ['recToken' => $this->maskToken()];
    }

    private function maskToken(): string
    {
        $length = strlen($this->recToken);

        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($this->recToken, 0, 6) . str_repeat('*', $length - 10) . substr($this->recToken, -4);
    }
}
