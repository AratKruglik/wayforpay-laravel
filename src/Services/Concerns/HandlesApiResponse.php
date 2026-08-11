<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services\Concerns;

use AratKruglik\WayForPay\Enums\ReasonCode;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use Illuminate\Http\Client\Response;

trait HandlesApiResponse
{
    protected function parseResponse(
        Response $response,
        string $errorPrefix = 'API',
        ?string $returnKey = null
    ): array|string {
        if ($response->failed()) {
            throw new WayForPayException(
                message: "{$errorPrefix} request failed",
                responseData: ['status' => $response->status()]
            );
        }

        $json = $response->json();

        if (isset($json['reasonCode'])) {
            $code = ReasonCode::tryFrom((int) $json['reasonCode']);
            // Pending codes (e.g. hold WaitingAmountConfirm) are neither success nor failure
            // and must not be treated as an error response.
            if ($code && !$code->isSuccess() && !$code->isPending()) {
                throw new WayForPayException(
                    message: $json['reason'] ?? $code->getDescription(),
                    reasonCode: $code,
                    responseData: $json
                );
            }
        }

        if ($returnKey !== null) {
            if (!isset($json[$returnKey])) {
                throw new WayForPayException("Failed to retrieve {$returnKey} from API response");
            }
            return $json[$returnKey];
        }

        return $json;
    }
}
