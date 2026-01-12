<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Enums;

enum ReasonCode: int
{
    case OK = 1100;
    case DECLINED_BY_ISSUER = 1101;
    case BAD_CVV2 = 1102;
    case EXPIRED_CARD = 1103;
    case INSUFFICIENT_FUNDS = 1104;
    case INVALID_CARD = 1105;
    case EXCEED_WITHDRAWAL_FREQUENCY = 1106;
    case THREE_DS_FAIL = 1108;
    case FORMAT_ERROR = 1109;
    case TRANSACTION_NOT_ALLOWED = 1114;
    case SYSTEM_ERROR = 1116;
    case DUPLICATE_ORDER_REFERENCE = 1118;
    case SIGNATURE_MISMATCH = 1124;
    case MERCHANT_DISABLED = 1125;
    
    // Regular API codes
    case REGULAR_OK = 4100;
    case REGULAR_NOT_FOUND = 4101;
    case REGULAR_ALREADY_ACTIVE = 4102;
    case REGULAR_SUSPENDED = 4103;

    public function isSuccess(): bool
    {
        return $this === self::OK || $this === self::REGULAR_OK;
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::OK => 'Operation was performed without errors',
            self::DECLINED_BY_ISSUER => 'Refusal of the issuing Bank',
            self::BAD_CVV2 => 'Wrong CVV code',
            self::EXPIRED_CARD => 'The card is overdue',
            self::INSUFFICIENT_FUNDS => 'Lack of assets',
            self::INVALID_CARD => 'Wrong card number or unallowable status',
            self::EXCEED_WITHDRAWAL_FREQUENCY => 'Limit of operations by card exceeded',
            self::THREE_DS_FAIL => 'Impossible to perform 3DS transaction',
            self::FORMAT_ERROR => 'Format error',
            self::TRANSACTION_NOT_ALLOWED => 'Transaction not allowed',
            self::SYSTEM_ERROR => 'System error',
            self::DUPLICATE_ORDER_REFERENCE => 'Duplicate order reference',
            self::SIGNATURE_MISMATCH => 'Signature mismatch',
            self::MERCHANT_DISABLED => 'Merchant account disabled',
            self::REGULAR_OK => 'Regular operation successful',
            self::REGULAR_NOT_FOUND => 'Subscription not found',
            self::REGULAR_ALREADY_ACTIVE => 'Subscription already active',
            self::REGULAR_SUSPENDED => 'Subscription suspended',
        };
    }
}
