<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Enums;

enum TransactionStatus: string
{
    case APPROVED = 'Approved';
    case DECLINED = 'Declined';
    case IN_PROCESSING = 'InProcessing';
    case EXPIRED = 'Expired';
    case PENDING = 'Pending';
    case REFUNDED = 'Refunded';
    case VOIDED = 'Voided';
    case WAITING_CONFIRM = 'WaitingAmountConfirm';
    
    // Callback specific
    case ACCEPT = 'accept';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::DECLINED,
            self::EXPIRED,
            self::REFUNDED,
            self::VOIDED,
        ]);
    }

    public function isSuccess(): bool
    {
        return $this === self::APPROVED || $this === self::REFUNDED || $this === self::ACCEPT;
    }
}
