<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string purchase(\AratKruglik\WayForPay\Domain\Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null)
 * @method static array createInvoice(\AratKruglik\WayForPay\Domain\Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null)
 * @method static array removeInvoice(string $orderReference)
 * @method static array charge(\AratKruglik\WayForPay\Domain\Transaction $transaction, \AratKruglik\WayForPay\Domain\Card $card, ?string $serviceUrl = null)
 * @method static array checkStatus(string $orderReference)
 * @method static array refund(string $orderReference, float $amount, string $currency, string $comment)
 * @method static array p2pCredit(string $orderReference, float $amount, string $currency, string $cardBeneficiary, ?string $rec2Token = null)
 * @method static array settle(string $orderReference, float $amount, string $currency)
 * @method static string verifyCard(string $orderReference, string $currency = 'UAH')
 * @method static array suspendRecurring(string $orderReference)
 * @method static array resumeRecurring(string $orderReference)
 * @method static array removeRecurring(string $orderReference)
 * @method static array handleWebhook(array $data)
 *
 * @see \AratKruglik\WayForPay\Services\WayForPayService
 */
class WayForPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'wayforpay';
    }
}
