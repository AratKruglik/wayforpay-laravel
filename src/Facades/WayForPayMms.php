<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Facades;

use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\Partner;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array addPartner(Partner $partner)
 * @method static array partnerInfo(string $partnerCode)
 * @method static array updatePartner(string $partnerCode, array $updates)
 * @method static array addMerchant(Merchant $merchant)
 * @method static array merchantInfo(string $merchantAccountInfo, string $secretKey)
 * @method static array merchantBalance(?string $toDate = null)
 *
 * @see \AratKruglik\WayForPay\Services\MmsService
 */
class WayForPayMms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'wayforpay.mms';
    }
}
