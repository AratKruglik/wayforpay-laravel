<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WayForPayCallbackReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $data
    ) {}
}
