<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Domain;

use AratKruglik\WayForPay\Domain\Concerns\ValidatesContactInfo;
use InvalidArgumentException;

readonly class Partner
{
    use ValidatesContactInfo;

    public function __construct(
        public string $partnerCode,
        public string $site,
        public string $phone,
        public string $email,
        public ?string $description = null,
        public ?CompensationCard $compensationCard = null,
        public ?CompensationAccount $compensationAccount = null,
        public ?string $compensationCardToken = null
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (trim($this->partnerCode) === '') {
            throw new InvalidArgumentException('Partner code cannot be empty');
        }

        self::assertValidUrl($this->site, 'Site');
        self::assertValidPhone($this->phone);
        self::assertValidEmail($this->email);
    }

    public function toArray(): array
    {
        $data = array_filter([
            'partnerCode' => $this->partnerCode,
            'site' => $this->site,
            'phone' => $this->phone,
            'email' => $this->email,
            'description' => $this->description,
            'compensationCardToken' => $this->compensationCardToken,
        ], fn($value) => $value !== null);

        if ($this->compensationCard) {
            $data += $this->compensationCard->toArray();
        }

        if ($this->compensationAccount) {
            $data += $this->compensationAccount->toArray();
        }

        return $data;
    }
}
