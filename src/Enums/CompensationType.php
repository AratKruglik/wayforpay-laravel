<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Enums;

enum CompensationType: string
{
    case CARD = 'card';
    case ACCOUNT = 'account';
}
