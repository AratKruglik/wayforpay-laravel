<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use AratKruglik\WayForPay\Contracts\WayForPayInterface;
use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Enums\ReasonCode;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Exceptions\SignatureMismatchException;

class WayForPayService implements WayForPayInterface
{
    private string $merchantAccount;
    private string $merchantDomain;
    private string $secretKey;
    private int $timeout;
    private string $baseUrl = 'https://api.wayforpay.com/api';

    public function __construct(
        private readonly SignatureGenerator $signatureGenerator,
        private readonly HttpFactory $http
    ) {
        $this->merchantAccount = Config::get('wayforpay.merchant_account');
        $this->merchantDomain = Config::get('wayforpay.merchant_domain');
        $this->secretKey = Config::get('wayforpay.secret_key');
        $this->timeout = (int) Config::get('wayforpay.timeout', 30);
    }

    public function purchase(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): string
    {
        $formData = $this->getPurchaseFormData($transaction, $returnUrl, $serviceUrl);

        return $this->generateAutoSubmitForm($formData);
    }

    public function getPurchaseFormData(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array
    {
        $data = $this->prepareTransactionData($transaction);
        $signature = $this->signatureGenerator->generateForPurchase($data);

        $payload = array_merge($data, [
            'merchantAuthType' => 'SimpleSignature',
            'merchantSignature' => $signature,
            'orderTimeout' => 49000,
            'defaultPaymentSystem' => 'card',
        ]);

        if ($transaction->client) {
            $payload = array_merge($payload, $transaction->client->toArray());
        }

        if ($returnUrl) {
            $payload['returnUrl'] = $this->validateUrl($returnUrl, 'returnUrl');
        }
        if ($serviceUrl) {
            $payload['serviceUrl'] = $this->validateUrl($serviceUrl, 'serviceUrl');
        }

        return $payload;
    }

    private function validateUrl(string $url, string $paramName): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid {$paramName}: URL format is invalid");
        }

        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Invalid {$paramName}: only HTTP/HTTPS URLs are allowed");
        }

        return $url;
    }

    private function generateAutoSubmitForm(array $formData): string
    {
        $inputs = '';

        foreach ($formData as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $escapedValue = htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8');
                    $inputs .= "<input type=\"hidden\" name=\"{$key}[]\" value=\"{$escapedValue}\" />\n";
                }
            } else {
                $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $inputs .= "<input type=\"hidden\" name=\"{$key}\" value=\"{$escapedValue}\" />\n";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Redirecting to payment...</title>
</head>
<body>
    <form id="wayforpay_form" method="POST" action="https://secure.wayforpay.com/pay" accept-charset="utf-8">
        {$inputs}
    </form>
    <script type="text/javascript">
        document.getElementById('wayforpay_form').submit();
    </script>
</body>
</html>
HTML;
    }

    public function createInvoice(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array
    {
        $data = $this->prepareTransactionData($transaction);
        $data['transactionType'] = 'CREATE_INVOICE';
        $data['apiVersion'] = 1;
        
        $signature = $this->signatureGenerator->generateForPurchase($data);
        
        $payload = array_merge($data, [
            'merchantAuthType' => 'SimpleSignature',
            'merchantSignature' => $signature,
            'orderTimeout' => 86400,
        ]);
        
        if ($transaction->client) $payload = array_merge($payload, $transaction->client->toArray());
        if ($serviceUrl) $payload['serviceUrl'] = $serviceUrl;
        
        return $this->sendRequest($payload);
    }
    
    public function removeInvoice(string $orderReference): array
    {
        $data = [
            'transactionType' => 'REMOVE_INVOICE',
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $orderReference,
            'apiVersion' => 1,
        ];
        
        $data['merchantSignature'] = $this->signatureGenerator->generateForRemoveInvoice($data);
        
        return $this->sendRequest($data);
    }
    
    public function charge(Transaction $transaction, Card $card, ?string $serviceUrl = null): array
    {
        $data = $this->prepareTransactionData($transaction);
        $data['transactionType'] = 'CHARGE';
        $data['merchantTransactionType'] = 'SALE';
        $data['merchantTransactionSecureType'] = 'AUTO';
        $data['apiVersion'] = 1;
        
        $data = array_merge($data, $card->toArray());
        $data['merchantSignature'] = $this->signatureGenerator->generateForCharge($data);
        
        if ($transaction->client) {
            $data = array_merge($data, $transaction->client->toArray());
            if (!isset($data['clientIpAddress'])) {
                 $data['clientIpAddress'] = request()->ip() ?? '127.0.0.1';
            }
        }
        if ($serviceUrl) $data['serviceUrl'] = $serviceUrl;
        
        return $this->sendRequest($data);
    }

    private function prepareTransactionData(Transaction $transaction): array
    {
        $productNames = array_map(fn($p) => $p->name, $transaction->getProducts());
        $productCounts = array_map(fn($p) => $p->count, $transaction->getProducts());
        $productPrices = array_map(fn($p) => $p->price, $transaction->getProducts());

        $data = [
            'merchantAccount' => $this->merchantAccount,
            'merchantDomainName' => $this->merchantDomain,
            'orderReference' => $transaction->orderReference,
            'orderDate' => $transaction->orderDate,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'productName' => $productNames,
            'productCount' => $productCounts,
            'productPrice' => $productPrices,
        ];

        if ($transaction->paymentSystems) $data['paymentSystems'] = $transaction->paymentSystems;
        if ($transaction->defaultPaymentSystem) $data['defaultPaymentSystem'] = $transaction->defaultPaymentSystem;
        if ($transaction->orderTimeout) $data['orderTimeout'] = $transaction->orderTimeout;
        if ($transaction->orderLifetime) $data['orderLifetime'] = $transaction->orderLifetime;
        
        if ($transaction->regularMode) $data['regularMode'] = $transaction->regularMode;
        if ($transaction->regularOn) $data['regularOn'] = $transaction->regularOn;
        if ($transaction->dateNext) $data['dateNext'] = $transaction->dateNext;
        if ($transaction->dateEnd) $data['dateEnd'] = $transaction->dateEnd;
        if ($transaction->regularCount) $data['regularCount'] = $transaction->regularCount;
        if ($transaction->regularAmount) $data['regularAmount'] = $transaction->regularAmount;

        return $data;
    }

    public function checkStatus(string $orderReference): array
    {
        $data = [
            'transactionType' => 'CHECK_STATUS',
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $orderReference,
            'apiVersion' => 1,
        ];

        $data['merchantSignature'] = $this->signatureGenerator->generateForCheckStatus($data);

        return $this->sendRequest($data);
    }

    public function refund(string $orderReference, float $amount, string $currency, string $comment): array
    {
        $data = [
            'transactionType' => 'REFUND',
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $orderReference,
            'amount' => $amount,
            'currency' => $currency,
            'comment' => $comment,
            'apiVersion' => 1,
        ];

        $data['merchantSignature'] = $this->signatureGenerator->generateForRefund($data);

        return $this->sendRequest($data);
    }
    
    public function p2pCredit(string $orderReference, float $amount, string $currency, string $cardBeneficiary, ?string $rec2Token = null): array
    {
        $data = [
            'transactionType' => 'P2P_CREDIT',
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $orderReference,
            'amount' => $amount,
            'currency' => $currency,
            'cardBeneficiary' => $cardBeneficiary,
            'rec2Token' => $rec2Token,
            'apiVersion' => 1,
        ];
        
        $data['merchantSignature'] = $this->signatureGenerator->generateForP2PCredit($data);
        
        return $this->sendRequest($data);
    }

    public function settle(string $orderReference, float $amount, string $currency): array
    {
        $data = [
            'transactionType' => 'SETTLE',
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $orderReference,
            'amount' => $amount,
            'currency' => $currency,
            'apiVersion' => 1,
        ];
        
        $data['merchantSignature'] = $this->signatureGenerator->generateForSettle($data);
        
        return $this->sendRequest($data);
    }
    
    public function verifyCard(string $orderReference, string $currency = 'UAH'): string
    {
        $data = [
            'merchantAccount' => $this->merchantAccount,
            'merchantDomainName' => $this->merchantDomain,
            'orderReference' => $orderReference,
            'amount' => 0,
            'currency' => $currency,
            'apiVersion' => 1,
            'paymentSystem' => 'lookupCard',
        ];

        $data['merchantSignature'] = $this->signatureGenerator->generateForVerify($data);
        
        $response = $this->http->asJson()
            ->timeout($this->timeout)
            ->post('https://secure.wayforpay.com/verify', $data);
            
        return $this->handleResponse($response, 'url');
    }

    public function suspendRecurring(string $orderReference): array
    {
        return $this->sendRegularRequest('SUSPEND', $orderReference);
    }

    public function resumeRecurring(string $orderReference): array
    {
        return $this->sendRegularRequest('RESUME', $orderReference);
    }

    public function removeRecurring(string $orderReference): array
    {
        return $this->sendRegularRequest('REMOVE', $orderReference);
    }

    private function sendRegularRequest(string $type, string $orderReference): array
    {
        $data = [
            'requestType' => $type,
            'merchantAccount' => $this->merchantAccount,
            'merchantPassword' => $this->secretKey,
            'orderReference' => $orderReference,
        ];

        $response = $this->http->asJson()
            ->timeout($this->timeout)
            ->post('https://api.wayforpay.com/regularApi', $data);

        return $this->handleResponse($response);
    }

    public function handleWebhook(array $data): array
    {
        // Validate required fields
        $requiredFields = ['merchantAccount', 'orderReference', 'transactionStatus', 'merchantSignature'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new WayForPayException("Missing required webhook field: {$field}");
            }
        }

        $signatureParams = [
            'merchantAccount' => $data['merchantAccount'],
            'orderReference' => $data['orderReference'],
            'amount' => $data['amount'] ?? '',
            'currency' => $data['currency'] ?? '',
            'authCode' => $data['authCode'] ?? '',
            'cardPan' => $data['cardPan'] ?? '',
            'transactionStatus' => $data['transactionStatus'],
            'reasonCode' => $data['reasonCode'] ?? '',
        ];

        $expectedSignature = $this->signatureGenerator->generateForServiceUrl($signatureParams);

        if (!hash_equals($expectedSignature, $data['merchantSignature'])) {
            throw new SignatureMismatchException('Invalid webhook signature');
        }

        \AratKruglik\WayForPay\Events\WayForPayCallbackReceived::dispatch($data);

        $time = time();
        $responseStatus = 'accept';
        $orderRef = $data['orderReference'];

        $responseSignature = $this->signatureGenerator->generateResponseSignature($orderRef, $responseStatus, $time);

        return [
            'orderReference' => $orderRef,
            'status' => $responseStatus,
            'time' => $time,
            'signature' => $responseSignature
        ];
    }

    protected function sendRequest(array $data): array
    {
        $response = $this->http->asJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl, $data);

        return $this->handleResponse($response);
    }

    protected function handleResponse(\Illuminate\Http\Client\Response $response, ?string $returnKey = null): array|string
    {
        if ($response->failed()) {
            throw new WayForPayException('API Request failed: ' . $response->body());
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

        if ($returnKey) {
            if (!isset($json[$returnKey])) {
                 throw new WayForPayException('Failed to retrieve ' . $returnKey . '. Response: ' . $response->body());
            }
            return $json[$returnKey];
        }

        return $json;
    }
}