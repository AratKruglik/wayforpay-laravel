<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AratKruglik\WayForPay\Services\WayForPayService;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WayForPayService $service
    ) {}

    public function __invoke(Request $request)
    {
        try {
            $response = $this->service->handleWebhook($request->all());
            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}