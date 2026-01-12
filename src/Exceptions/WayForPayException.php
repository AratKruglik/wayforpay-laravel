<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Exceptions;

use Exception;

use AratKruglik\WayForPay\Enums\ReasonCode;

class WayForPayException extends Exception
{
    public function __construct(
        string $message = "",
        int $code = 0,
        private readonly ?ReasonCode $reasonCode = null,
        private readonly array $responseData = [],
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getReasonCode(): ?ReasonCode
    {
        return $this->reasonCode;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }
}
