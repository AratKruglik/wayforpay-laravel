<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Contracts;

use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\Partner;

interface MmsServiceInterface
{
    public function addPartner(Partner $partner): array;

    public function partnerInfo(string $partnerCode): array;

    public function updatePartner(string $partnerCode, array $updates): array;

    public function addMerchant(Merchant $merchant): array;

    public function merchantInfo(string $merchantAccountInfo, string $secretKey): array;

    public function merchantBalance(?string $toDate = null): array;
}
