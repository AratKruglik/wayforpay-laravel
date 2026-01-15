<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use AratKruglik\WayForPay\Services\WayForPayService;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Exceptions\SignatureMismatchException;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WayForPayService $service
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $response = $this->service->handleWebhook($request->all());
            return response()->json($response);
        } catch (SignatureMismatchException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature'
            ], 403);
        } catch (WayForPayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
