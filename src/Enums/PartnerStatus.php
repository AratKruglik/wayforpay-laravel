<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Enums;

enum PartnerStatus: string
{
    case NEW = 'New';
    case ACTIVE = 'Active';
    case BLOCKED = 'Blocked';
}
