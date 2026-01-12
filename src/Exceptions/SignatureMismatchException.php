<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Exceptions;

class SignatureMismatchException extends WayForPayException
{
    public function __construct(string $message = "Signature mismatch")
    {
        parent::__construct($message);
    }
}
