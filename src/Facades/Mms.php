<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array addPartner(\AratKruglik\WayForPay\Domain\Partner $partner)
 * @method static array partnerInfo(string $partnerCode)
 * @method static array updatePartner(string $partnerCode, array $updates)
 * @method static array addMerchant(\AratKruglik\WayForPay\Domain\Merchant $merchant)
 * @method static array merchantInfo(string $merchantAccountInfo, string $secretKey)
 * @method static array merchantBalance(?string $toDate = null)
 *
 * @see \AratKruglik\WayForPay\Services\MmsService
 */
class Mms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'wayforpay.mms';
    }
}
