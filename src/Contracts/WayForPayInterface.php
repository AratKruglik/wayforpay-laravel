<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Contracts;

use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Domain\Transaction;

interface WayForPayInterface
{
    public function purchase(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): string;
    
    public function createInvoice(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array;
    
    public function removeInvoice(string $orderReference): array;
    
    public function charge(Transaction $transaction, Card $card, ?string $serviceUrl = null): array;
    
    public function checkStatus(string $orderReference): array;
    
    public function refund(string $orderReference, float $amount, string $currency, string $comment): array;

    public function p2pCredit(string $orderReference, float $amount, string $currency, string $cardBeneficiary, ?string $rec2Token = null): array;
    
    public function settle(string $orderReference, float $amount, string $currency): array;

    public function verifyCard(string $orderReference, string $currency = 'UAH'): string;

    public function suspendRecurring(string $orderReference): array;
    
    public function resumeRecurring(string $orderReference): array;
    
    public function removeRecurring(string $orderReference): array;

    public function handleWebhook(array $data): array;
}
