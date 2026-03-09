<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services\Concerns;

use AratKruglik\WayForPay\Enums\ReasonCode;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use Illuminate\Http\Client\Response;

trait HandlesApiResponse
{
    protected function parseResponse(Response $response, string $errorPrefix = 'API'): array
    {
        if ($response->failed()) {
            throw new WayForPayException(
                message: "{$errorPrefix} request failed",
                responseData: ['status' => $response->status()]
            );
        }

        $json = $response->json();

        if (isset($json['reasonCode'])) {
            $code = ReasonCode::tryFrom((int) $json['reasonCode']);
            if ($code && !$code->isSuccess()) {
                throw new WayForPayException(
                    message: $json['reason'] ?? $code->getDescription(),
                    reasonCode: $code,
                    responseData: $json
                );
            }
        }

        return $json;
    }
}
