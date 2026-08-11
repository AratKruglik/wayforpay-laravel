<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use AratKruglik\WayForPay\Contracts\WayForPayInterface;
use AratKruglik\WayForPay\Domain\AccountTransfer;
use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;
use AratKruglik\WayForPay\Exceptions\WayForPayException;
use AratKruglik\WayForPay\Exceptions\SignatureMismatchException;
use AratKruglik\WayForPay\Services\Concerns\HandlesApiResponse;
use InvalidArgumentException;

class WayForPayService implements WayForPayInterface
{
    use HandlesApiResponse;

    private const PAY_URL = 'https://secure.wayforpay.com/pay';
    private const VERIFY_URL = 'https://secure.wayforpay.com/verify';
    private const REGULAR_API_URL = 'https://api.wayforpay.com/regularApi';
    private const PURCHASE_TIMEOUT = 49000;
    private const INVOICE_TIMEOUT = 86400;
    private const DEFAULT_PAYMENT_SYSTEM = 'card';
    private const WEBHOOK_REQUIRED_FIELDS = ['merchantAccount', 'orderReference', 'transactionStatus', 'merchantSignature'];
    private const WEBHOOK_SIGNATURE_FIELDS = ['merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode'];
    private const TRANSACTION_TYPE_AUTH = 'AUTH';
    private const TRANSACTION_TYPE_SALE = 'SALE';
    private const HOLD_TIMEOUT_NOT_ALLOWED_MESSAGE =
        'holdTimeout is only supported for hold (AUTH) operations. Use hold(), getHoldFormData() or holdCharge().';

    private string $merchantAccount;
    private string $merchantDomain;
    private string $secretKey;
    private int $timeout;
    private ?int $defaultHoldTimeout;
    private string $baseUrl = 'https://api.wayforpay.com/api';

    public function __construct(
        private readonly SignatureGenerator $signatureGenerator,
        private readonly HttpFactory $http
    ) {
        $this->merchantAccount = Config::get('wayforpay.merchant_account');
        $this->merchantDomain = Config::get('wayforpay.merchant_domain');
        $this->secretKey = Config::get('wayforpay.secret_key');
        $this->timeout = (int) Config::get('wayforpay.timeout', 30);

        $rawDefaultHoldTimeout = Config::get('wayforpay.default_hold_timeout');
        $this->defaultHoldTimeout = $rawDefaultHoldTimeout === null ? null : (int) $rawDefaultHoldTimeout;
    }

    public function purchase(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): string
    {
        $formData = $this->getPurchaseFormData($transaction, $returnUrl, $serviceUrl);

        return $this->generateAutoSubmitForm($formData);
    }

    public function getPurchaseFormData(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array
    {
        return $this->buildPurchaseFormData($transaction, $returnUrl, $serviceUrl, isHold: false);
    }

    public function hold(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): string
    {
        return $this->generateAutoSubmitForm($this->getHoldFormData($transaction, $returnUrl, $serviceUrl));
    }

    public function getHoldFormData(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array
    {
        $payload = $this->buildPurchaseFormData($transaction, $returnUrl, $serviceUrl, isHold: true);
        $payload['merchantTransactionType'] = self::TRANSACTION_TYPE_AUTH;

        return $payload;
    }

    private function buildPurchaseFormData(Transaction $transaction, ?string $returnUrl, ?string $serviceUrl, bool $isHold): array
    {
        $data = $this->prepareTransactionData($transaction, $isHold);
        $signature = $this->signatureGenerator->generateForPurchase($data);

        $payload = array_merge($data, [
            'merchantAuthType' => 'SimpleSignature',
            'merchantSignature' => $signature,
            'orderTimeout' => self::PURCHASE_TIMEOUT,
            'defaultPaymentSystem' => self::DEFAULT_PAYMENT_SYSTEM,
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
        $parsedUrl = parse_url($url);
        $scheme = $parsedUrl['scheme'] ?? '';

        $isValidUrl = filter_var($url, FILTER_VALIDATE_URL) !== false;
        $isValidScheme = in_array($scheme, ['http', 'https'], true);

        if (!$isValidUrl || !$isValidScheme) {
            throw new InvalidArgumentException("Invalid {$paramName}: must be a valid HTTP/HTTPS URL");
        }

        return $url;
    }

    private function generateAutoSubmitForm(array $formData): string
    {
        $payUrl = self::PAY_URL;
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
    <form id="wayforpay_form" method="POST" action="{$payUrl}" accept-charset="utf-8">
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
            'orderTimeout' => self::INVOICE_TIMEOUT,
        ]);
        
        if ($transaction->client) {
            $payload = array_merge($payload, $transaction->client->toArray());
        }
        if ($serviceUrl) {
            $payload['serviceUrl'] = $serviceUrl;
        }
        
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
        return $this->sendCharge($transaction, $card, isHold: false, serviceUrl: $serviceUrl);
    }

    public function holdCharge(Transaction $transaction, Card $card, ?string $serviceUrl = null): array
    {
        return $this->sendCharge($transaction, $card, isHold: true, serviceUrl: $serviceUrl);
    }

    private function sendCharge(Transaction $transaction, Card $card, bool $isHold, ?string $serviceUrl): array
    {
        $data = $this->prepareTransactionData($transaction, $isHold);
        $data['transactionType'] = 'CHARGE';
        $data['merchantTransactionType'] = $isHold ? self::TRANSACTION_TYPE_AUTH : self::TRANSACTION_TYPE_SALE;
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
        if ($serviceUrl) {
            $data['serviceUrl'] = $serviceUrl;
        }

        return $this->sendRequest($data);
    }

    private function prepareTransactionData(Transaction $transaction, bool $isHold = false): array
    {
        $products = $transaction->getProducts();

        $data = [
            'merchantAccount' => $this->merchantAccount,
            'merchantDomainName' => $this->merchantDomain,
            'orderReference' => $transaction->orderReference,
            'orderDate' => $transaction->orderDate,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'productName' => array_map(fn($p) => $p->name, $products),
            'productCount' => array_map(fn($p) => $p->count, $products),
            'productPrice' => array_map(fn($p) => $p->price, $products),
        ];

        $optionalFields = [
            'paymentSystems' => $transaction->paymentSystems,
            'defaultPaymentSystem' => $transaction->defaultPaymentSystem,
            'orderTimeout' => $transaction->orderTimeout,
            'orderLifetime' => $transaction->orderLifetime,
            'regularMode' => $transaction->regularMode,
            'regularOn' => $transaction->regularOn,
            'dateNext' => $transaction->dateNext,
            'dateEnd' => $transaction->dateEnd,
            'regularCount' => $transaction->regularCount,
            'regularAmount' => $transaction->regularAmount,
        ];

        if ($isHold) {
            $optionalFields['holdTimeout'] = $this->resolveHoldTimeout($transaction);
        } elseif ($transaction->holdTimeout !== null) {
            throw new InvalidArgumentException(self::HOLD_TIMEOUT_NOT_ALLOWED_MESSAGE);
        }

        return array_merge($data, array_filter($optionalFields, fn($value) => $value !== null));
    }

    private function resolveHoldTimeout(Transaction $transaction): ?int
    {
        $value = $transaction->holdTimeout ?? $this->defaultHoldTimeout;

        if ($value !== null && ($value < Transaction::HOLD_TIMEOUT_MIN || $value > Transaction::HOLD_TIMEOUT_MAX)) {
            throw new InvalidArgumentException(
                "Hold timeout must be between " . Transaction::HOLD_TIMEOUT_MIN . " and " . Transaction::HOLD_TIMEOUT_MAX
                . " seconds. Check Transaction::\$holdTimeout or the wayforpay.default_hold_timeout config value."
            );
        }

        return $value;
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

    public function cancelHold(string $orderReference, float $amount, string $currency, string $comment = 'Hold cancelled'): array
    {
        return $this->refund($orderReference, $amount, $currency, $comment);
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

    public function settle(string $orderReference, float $amount, string $currency, ?array $products = null): array
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

        if ($products !== null && $products !== []) {
            foreach ($products as $product) {
                if (!$product instanceof Product) {
                    throw new InvalidArgumentException('All items must be instances of Product');
                }
            }
            $data['productName'] = array_map(fn($product) => $product->name, $products);
            $data['productPrice'] = array_map(fn($product) => $product->price, $products);
            $data['productCount'] = array_map(fn($product) => $product->count, $products);
        }

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
            ->post(self::VERIFY_URL, $data);
            
        return $this->parseResponse($response, returnKey: 'url');
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
            ->post(self::REGULAR_API_URL, $data);

        return $this->parseResponse($response);
    }

    public function p2pAccount(AccountTransfer $transfer): array
    {
        $data = array_merge(
            [
                'transactionType' => 'P2P_ACCOUNT',
                'merchantAccount' => $this->merchantAccount,
                'apiVersion' => 1,
            ],
            $transfer->toArray()
        );

        $data['merchantSignature'] = $this->signatureGenerator->generateForP2pAccount([
            'merchantAccount' => $this->merchantAccount,
            'orderReference' => $transfer->orderReference,
            'amount' => $transfer->amount,
            'currency' => $transfer->currency,
            'iban' => $transfer->iban,
            'okpo' => $transfer->okpo,
            'accountName' => $transfer->accountName,
        ]);

        return $this->sendRequest($data);
    }

    public function handleWebhook(array $data): array
    {
        $this->validateWebhookRequiredFields($data);
        $this->validateWebhookSignature($data);

        WayForPayCallbackReceived::dispatch($data);

        return $this->buildWebhookResponse($data['orderReference']);
    }

    private function validateWebhookRequiredFields(array $data): void
    {
        foreach (self::WEBHOOK_REQUIRED_FIELDS as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new WayForPayException("Missing required webhook field: {$field}");
            }
        }
    }

    private function validateWebhookSignature(array $data): void
    {
        $signatureParams = [];
        foreach (self::WEBHOOK_SIGNATURE_FIELDS as $field) {
            $signatureParams[$field] = $data[$field] ?? '';
        }

        $expectedSignature = $this->signatureGenerator->generateForServiceUrl($signatureParams);

        if (!hash_equals($expectedSignature, $data['merchantSignature'])) {
            throw new SignatureMismatchException('Invalid webhook signature');
        }
    }

    private function buildWebhookResponse(string $orderReference): array
    {
        $time = time();
        $status = 'accept';
        $signature = $this->signatureGenerator->generateResponseSignature($orderReference, $status, $time);

        return [
            'orderReference' => $orderReference,
            'status' => $status,
            'time' => $time,
            'signature' => $signature,
        ];
    }

    protected function sendRequest(array $data): array
    {
        $response = $this->http->asJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl, $data);

        return $this->parseResponse($response);
    }
}