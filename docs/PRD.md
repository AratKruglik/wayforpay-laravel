# Product Requirements Document: aratkruglik/wayforpay-laravel

## 1. Executive Summary

`aratkruglik/wayforpay-laravel` is a native Laravel package for integrating with the [WayForPay](https://wayforpay.com) payment gateway. It provides a clean, type-safe, fully testable PHP interface for accepting payments, managing invoices, processing refunds, handling recurring subscriptions, and receiving payment callbacks.

**Target audience:** Laravel developers building e-commerce, SaaS, or marketplace applications that accept payments in Ukraine and neighboring markets.

**Key value proposition:** Zero external SDK dependencies, first-class Laravel integration (DI, Facades, Events, `Http::fake()` testing), and constructor-validated domain objects that prevent invalid data from reaching the payment API.

## 2. Goals and Non-Goals

### Goals

- Provide a complete PHP interface to WayForPay's payment API operations
- Integrate natively with Laravel's service container, facades, events, and HTTP client
- Ensure type safety and early validation through readonly DTOs
- Enable full testability without hitting external APIs
- Handle webhook signature verification and signed acknowledgment automatically

### Non-Goals

- **Not a multi-gateway abstraction**: this package supports WayForPay only, not Stripe/LiqPay/etc.
- **Not a UI kit**: no Blade components, Livewire widgets, or JavaScript SDKs
- **Not an order management system**: the package handles payment API communication, not order lifecycle
- **Not multi-merchant**: one merchant configuration per application instance
- **Not a queue/job system**: webhook events are dispatched synchronously; async processing is the application's responsibility

## 3. System Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ^8.2 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| `illuminate/support` | ^11.0 \| ^12.0 \| ^13.0 |
| `illuminate/http` | ^11.0 \| ^12.0 \| ^13.0 |

**Dev dependencies:** Orchestra Testbench ^9.0\|^10.0, Pest ^5.0 with Laravel and Mutate plugins.

**External SDK dependencies:** None. All HTTP communication uses Laravel's built-in HTTP client.

## 4. Architecture Overview

### Layers

```
Facades/WayForPay          (Static proxy)
        |
Contracts/WayForPayInterface  (Public contract - 13 methods)
        |
Services/WayForPayService     (Implementation - API operations, webhook handling)
        |
   +----+----+
   |         |
Services/   Domain/
Signature   Transaction, Product, Client, Card
Generator
   |
Enums/      Exceptions/     Events/
ReasonCode  WayForPayException  WayForPayCallbackReceived
TransactionStatus  SignatureMismatchException
```

### Namespace Map

| Namespace | Purpose |
|-----------|---------|
| `AratKruglik\WayForPay\Services` | Core service and signature generator |
| `AratKruglik\WayForPay\Contracts` | Public interface |
| `AratKruglik\WayForPay\Facades` | Laravel facade |
| `AratKruglik\WayForPay\Domain` | Validated DTOs (Transaction, Product, Client, Card) |
| `AratKruglik\WayForPay\Enums` | ReasonCode, TransactionStatus |
| `AratKruglik\WayForPay\Exceptions` | WayForPayException, SignatureMismatchException |
| `AratKruglik\WayForPay\Events` | WayForPayCallbackReceived |
| `AratKruglik\WayForPay\Http\Controllers` | WebhookController |
| `AratKruglik\WayForPay\Config` | Package configuration |

### Service Registration

`WayForPayServiceProvider` registers:
- `SignatureGenerator` as singleton (injected with `secret_key`)
- `WayForPayService` as singleton (injected with `SignatureGenerator` + `HttpFactory`)
- `WayForPayInterface` bound to `WayForPayService`
- `'wayforpay'` alias for facade resolution

## 5. Configuration

Published via: `php artisan vendor:publish --tag=wayforpay-config`

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `merchant_account` | `WAYFORPAY_MERCHANT_ACCOUNT` | `''` | Merchant account identifier |
| `secret_key` | `WAYFORPAY_SECRET_KEY` | `''` | Secret key for HMAC signatures and Regular API password |
| `merchant_domain` | `WAYFORPAY_MERCHANT_DOMAIN` | `''` | Merchant domain name |
| `timeout` | `WAYFORPAY_TIMEOUT` | `30` | HTTP request timeout in seconds |
| `default_hold_timeout` | `WAYFORPAY_HOLD_TIMEOUT` | `null` | Default `holdTimeout` (seconds) used by `hold()`/`holdCharge()` when `Transaction::$holdTimeout` is not set |
| `debug` | `WAYFORPAY_DEBUG` | `false` | Enable request/response logging |

## 6. Integration Patterns

### Facade

```php
use AratKruglik\WayForPay\Facades\WayForPay;

$html = WayForPay::purchase($transaction, returnUrl: 'https://example.com/thanks');
return response($html);
```

### Dependency Injection

```php
use AratKruglik\WayForPay\Contracts\WayForPayInterface;

class PaymentController
{
    public function __construct(private WayForPayInterface $wayforpay) {}

    public function pay(Transaction $transaction)
    {
        return response($this->wayforpay->purchase($transaction));
    }
}
```

### Event Listener

```php
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;

Event::listen(WayForPayCallbackReceived::class, function ($event) {
    $data = $event->data;
    Order::where('reference', $data['orderReference'])
         ->update(['status' => $data['transactionStatus']]);
});
```

## 7. Domain Model Specification

### Transaction

| Property | Type | Required | Validation |
|----------|------|----------|------------|
| `orderReference` | `string` | Yes | Non-empty, max 64 chars |
| `amount` | `float` | Yes | > 0 |
| `currency` | `string` | Yes | One of: UAH, USD, EUR, PLN, GBP |
| `orderDate` | `int` | Yes | > 0 (Unix timestamp) |
| `client` | `?Client` | No | Valid Client instance |
| `paymentSystems` | `?string` | No | - |
| `defaultPaymentSystem` | `?string` | No | - |
| `orderTimeout` | `?int` | No | > 0 |
| `orderLifetime` | `?int` | No | > 0 |
| `regularMode` | `?string` | No | - |
| `regularOn` | `?string` | No | - |
| `dateNext` | `?string` | No | - |
| `dateEnd` | `?string` | No | - |
| `regularCount` | `?int` | No | >= 1 |
| `regularAmount` | `?float` | No | > 0 |
| `holdTimeout` | `?int` | No | Between 60 and 1728000 (seconds); only valid for hold operations |

Products are added via `addProduct(Product)` or `setProducts(Product[])`. At least one product is required when `getProducts()` is called.

### Product (readonly)

| Property | Type | Validation |
|----------|------|------------|
| `name` | `string` | Non-empty, max 255 chars |
| `price` | `float` | >= 0 |
| `count` | `int` | >= 1 |

### Client (readonly)

| Property | Type | Validation |
|----------|------|------------|
| `nameFirst` | `?string` | Max 100 chars |
| `nameLast` | `?string` | Max 100 chars |
| `email` | `?string` | Valid email (FILTER_VALIDATE_EMAIL) |
| `phone` | `?string` | Regex: `/^\+?[\d\s\-()]{6,20}$/` |
| `address` | `?string` | - |
| `city` | `?string` | - |
| `country` | `?string` | ISO 2-3 letter code (`/^[A-Z]{2,3}$/`) |

### Card (readonly)

| Property | Type | Validation |
|----------|------|------------|
| `cardNumber` | `string` | 13-19 digits after stripping non-digits; Luhn check |
| `expMonth` | `string` | 01-12 |
| `expYear` | `string` | 2 digits |
| `cvv` | `string` | 3-4 digits |
| `holderName` | `?string` | Max 100 chars |

## 8. API Operations Specification

### 8.1 purchase

Generates an auto-submitting HTML form that POSTs to WayForPay's hosted payment page.

- **Method:** `purchase(Transaction, ?returnUrl, ?serviceUrl): string`
- **Endpoint:** `https://secure.wayforpay.com/pay` (browser POST)
- **Signature fields:** merchantAccount, merchantDomainName, orderReference, orderDate, amount, currency, productName[], productCount[], productPrice[]
- **Returns:** HTML string

### 8.2 getPurchaseFormData

Returns raw form data array for custom form rendering.

- **Method:** `getPurchaseFormData(Transaction, ?returnUrl, ?serviceUrl): array`
- **Signature fields:** Same as purchase
- **Returns:** Associative array of form fields

### 8.3 createInvoice

Creates a payment invoice via API.

- **Method:** `createInvoice(Transaction, ?returnUrl, ?serviceUrl): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `CREATE_INVOICE`
- **Signature fields:** Same as purchase
- **Returns:** API response array

### 8.4 removeInvoice

Removes a previously created invoice.

- **Method:** `removeInvoice(string orderReference): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `REMOVE_INVOICE`
- **Signature fields:** merchantAccount, orderReference
- **Returns:** API response array

### 8.5 charge

Server-to-server card charge (requires PCI DSS compliance).

- **Method:** `charge(Transaction, Card, ?serviceUrl): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `CHARGE`
- **Merchant transaction type:** `SALE` (hardcoded)
- **Signature fields:** merchantAccount, merchantDomainName, orderReference, orderDate, amount, currency, [card, expMonth, expYear, cardCvv, cardHolder], productName[], productCount[], productPrice[]
- **Returns:** API response array

### 8.6 checkStatus

Queries the status of an existing transaction.

- **Method:** `checkStatus(string orderReference): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `CHECK_STATUS`
- **Signature fields:** merchantAccount, orderReference
- **Returns:** API response array

### 8.7 refund

Refunds a completed transaction.

- **Method:** `refund(string orderReference, float amount, string currency, string comment): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `REFUND`
- **Signature fields:** merchantAccount, orderReference, amount, currency
- **Returns:** API response array

### 8.8 p2pCredit

Peer-to-peer credit transfer to a card.

- **Method:** `p2pCredit(string orderReference, float amount, string currency, string cardBeneficiary, ?string rec2Token): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `P2P_CREDIT`
- **Signature fields:** merchantAccount, orderReference, amount, currency, cardBeneficiary, rec2Token
- **Returns:** API response array

### 8.9 settle

Settles (captures) a previously authorized transaction, in full or in part.

- **Method:** `settle(string orderReference, float amount, string currency, ?array $products = null): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `SETTLE`
- **Signature fields:** merchantAccount, orderReference, amount, currency
- **Optional fields:** `productName[]`, `productPrice[]`, `productCount[]` — sent only when `$products` is a non-empty `Product[]` array; not part of the signature
- **Returns:** API response array

### 8.10 verifyCard

Initiates card verification (zero-amount lookup).

- **Method:** `verifyCard(string orderReference, string currency = 'UAH'): string`
- **Endpoint:** `https://secure.wayforpay.com/verify`
- **Signature fields:** merchantAccount, merchantDomainName, orderReference, amount (0), currency
- **Returns:** Verification URL string

### 8.11 suspendRecurring

Suspends an active recurring subscription.

- **Method:** `suspendRecurring(string orderReference): array`
- **Endpoint:** `https://api.wayforpay.com/regularApi`
- **Auth:** merchantPassword (secret key sent directly)
- **Returns:** API response array

### 8.12 resumeRecurring

Resumes a suspended recurring subscription.

- **Method:** `resumeRecurring(string orderReference): array`
- **Endpoint:** `https://api.wayforpay.com/regularApi`
- **Auth:** merchantPassword
- **Returns:** API response array

### 8.13 removeRecurring

Permanently removes a recurring subscription.

- **Method:** `removeRecurring(string orderReference): array`
- **Endpoint:** `https://api.wayforpay.com/regularApi`
- **Auth:** merchantPassword
- **Returns:** API response array

### 8.14 hold

Generates an auto-submitting HTML form that creates a hold (AUTH) via WayForPay's hosted payment page.

- **Method:** `hold(Transaction, ?returnUrl, ?serviceUrl): string`
- **Endpoint:** `https://secure.wayforpay.com/pay` (browser POST)
- **Transaction type:** `merchantTransactionType=AUTH` (not part of the signature)
- **Signature fields:** Same as purchase
- **Returns:** HTML string

### 8.15 getHoldFormData

Returns raw form data array for a hold, for custom form rendering.

- **Method:** `getHoldFormData(Transaction, ?returnUrl, ?serviceUrl): array`
- **Signature fields:** Same as purchase; `merchantTransactionType` and `holdTimeout` are not signed
- **Returns:** Associative array of form fields

### 8.16 holdCharge

Server-to-server AUTH charge that creates a hold (requires PCI DSS compliance).

- **Method:** `holdCharge(Transaction, Card, ?serviceUrl): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `CHARGE`
- **Merchant transaction type:** `AUTH`
- **Merchant transaction secure type:** `AUTO` (COMPLETE_3DS flow is not implemented)
- **Signature fields:** Same as charge
- **Returns:** API response array

### 8.17 cancelHold

Cancels a hold by delegating to `refund()`.

- **Method:** `cancelHold(string orderReference, float amount, string currency, string comment = 'Hold cancelled'): array`
- **Endpoint:** `https://api.wayforpay.com/api`
- **Transaction type:** `REFUND`
- **Signature fields:** Same as refund
- **Returns:** API response array

## 9. Webhook Handling

### Flow

1. WayForPay POSTs callback data to the configured `serviceUrl`.
2. `WebhookController::__invoke()` receives the request.
3. `WayForPayService::handleWebhook()` validates required fields.
4. Signature is verified using `SignatureGenerator::generateForServiceUrl()` + `hash_equals()`.
5. `WayForPayCallbackReceived` event is dispatched with the raw data.
6. A signed acknowledgment response is returned.

### Required Webhook Fields

`merchantAccount`, `orderReference`, `transactionStatus`, `merchantSignature`

### Signature Verification Fields (ordered)

`merchantAccount`, `orderReference`, `amount`, `currency`, `authCode`, `cardPan`, `transactionStatus`, `reasonCode`

### Acknowledgment Response Format

```json
{
    "orderReference": "ORDER-123",
    "status": "accept",
    "time": 1709827200,
    "signature": "<HMAC-MD5 of orderReference;accept;time>"
}
```

### Error Responses

| Exception | HTTP Status | Body |
|-----------|-------------|------|
| `SignatureMismatchException` | 403 | `{"status": "error", "message": "Invalid signature"}` |
| `WayForPayException` | 400 | `{"status": "error", "message": "..."}` |

## 10. Security Model

### HMAC-MD5 Signatures

All API operations (except Regular API) authenticate via HMAC-MD5 signatures. Fields are concatenated with `;` separator and signed with the merchant's secret key.

### Timing-Safe Comparison

Webhook signature verification uses `hash_equals()` to prevent timing attacks.

### URL Validation

`returnUrl` and `serviceUrl` parameters are validated via `filter_var(FILTER_VALIDATE_URL)` with scheme restricted to `http` or `https`.

### HTML Escaping

All values in the auto-submitting purchase form are escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` to prevent XSS.

### Card Data (PCI DSS)

The `charge()` method accepts raw card data server-to-server. Applications using this method must ensure their own PCI DSS compliance. The `Card` DTO validates card format but does not store or log card data.

### Secret Key Protection

The secret key is loaded from environment variables via Laravel config and never exposed in responses or logs.

## 11. Enums

### ReasonCode (int-backed)

| Case | Value | Description |
|------|-------|-------------|
| `OK` | 1100 | Operation successful |
| `DECLINED_BY_ISSUER` | 1101 | Issuing bank refused |
| `BAD_CVV2` | 1102 | Wrong CVV code |
| `EXPIRED_CARD` | 1103 | Card expired |
| `INSUFFICIENT_FUNDS` | 1104 | Insufficient funds |
| `INVALID_CARD` | 1105 | Invalid card number or status |
| `EXCEED_WITHDRAWAL_FREQUENCY` | 1106 | Card operation limit exceeded |
| `THREE_DS_FAIL` | 1108 | 3DS transaction failed |
| `FORMAT_ERROR` | 1109 | Format error |
| `TRANSACTION_NOT_ALLOWED` | 1114 | Transaction not allowed |
| `SYSTEM_ERROR` | 1116 | System error |
| `DUPLICATE_ORDER_REFERENCE` | 1118 | Duplicate order reference |
| `SIGNATURE_MISMATCH` | 1124 | Signature mismatch |
| `MERCHANT_DISABLED` | 1125 | Merchant account disabled |
| `REGULAR_OK` | 4100 | Regular operation successful |
| `REGULAR_NOT_FOUND` | 4101 | Subscription not found |
| `REGULAR_ALREADY_ACTIVE` | 4102 | Subscription already active |
| `REGULAR_SUSPENDED` | 4103 | Subscription suspended |
| `TRANSACTION_IN_PROCESSING` | 1131 | Transaction is in processing |
| `WAIT_3DS_DATA` | 5100 | Waiting for 3DS authentication data |
| `WAITING_AMOUNT_CONFIRM` | 5105 | Waiting for amount confirmation (hold) |

**Methods:** `isSuccess(): bool` (true for OK and REGULAR_OK), `isPending(): bool` (true for TRANSACTION_IN_PROCESSING, WAIT_3DS_DATA, WAITING_AMOUNT_CONFIRM — treated as non-error in `parseResponse()`), `getDescription(): string`

### TransactionStatus (string-backed)

| Case | Value |
|------|-------|
| `APPROVED` | `Approved` |
| `DECLINED` | `Declined` |
| `IN_PROCESSING` | `InProcessing` |
| `EXPIRED` | `Expired` |
| `PENDING` | `Pending` |
| `REFUNDED` | `Refunded` |
| `VOIDED` | `Voided` |
| `WAITING_CONFIRM` | `WaitingAmountConfirm` |
| `WAITING_AUTH_COMPLETE` | `WaitingAuthComplete` |
| `ACCEPT` | `accept` |

**Methods:** `isFinal(): bool` (true for Approved, Declined, Expired, Refunded, Voided), `isSuccess(): bool` (true for Approved, Refunded, Accept), `isHold(): bool` (true for WaitingAuthComplete, WaitingAmountConfirm)

## 12. Exception Handling

### Hierarchy

```
Exception
  └── WayForPayException
        └── SignatureMismatchException
```

### WayForPayException

Thrown when:
- An API request returns a non-successful HTTP status
- An API response contains a non-success `reasonCode`
- A webhook is missing required fields
- A required response key is missing

**Additional data access:**
- `getReasonCode(): ?ReasonCode` - the WayForPay reason code (if available)
- `getResponseData(): array` - the full API response payload

### SignatureMismatchException

Thrown when webhook signature verification fails. Extends `WayForPayException` with a default message of "Signature mismatch".

## 13. External Endpoints

| Endpoint | Purpose | Auth Mode |
|----------|---------|-----------|
| `https://api.wayforpay.com/api` | Main API (charge, refund, settle, status, invoice, p2p) | HMAC signature |
| `https://secure.wayforpay.com/pay` | Hosted payment page (purchase) | HMAC signature (form POST) |
| `https://secure.wayforpay.com/verify` | Card verification | HMAC signature |
| `https://api.wayforpay.com/regularApi` | Recurring subscription management | merchantPassword |

## 14. Testing

### Framework

Pest 4 with Orchestra Testbench for Laravel package testing.

### Structure

```
tests/
  TestCase.php              (extends Orchestra\TestCase, loads WayForPayServiceProvider)
  Unit/
    SignatureGeneratorTest.php
    ...
  Feature/
    ...
```

### Patterns

- **HTTP faking:** All external API calls are faked via `Http::fake()` - no real network requests in tests.
- **Constructor validation tests:** DTOs are tested for both valid construction and expected `InvalidArgumentException` on invalid input.
- **Signature verification:** Specific field orderings are tested to prevent regressions.
- **Webhook flow tests:** Full webhook lifecycle including signature generation, validation, event dispatching, and response verification.

### Commands

```bash
vendor/bin/pest                    # Run all tests
vendor/bin/pest --testsuite=Unit   # Unit tests only
vendor/bin/pest --testsuite=Feature # Feature tests only
vendor/bin/pest --mutate           # Mutation testing
```

## 15. Non-Functional Requirements

### Performance

- No database queries or file I/O in the package itself
- HTTP timeout configurable via `WAYFORPAY_TIMEOUT` (default: 30s)
- Singleton registration prevents redundant service instantiation

### Reliability

- Constructor validation ensures only valid data reaches the API
- Signature verification prevents processing of tampered webhooks
- Exception hierarchy provides granular error handling

### Maintainability

- Single namespace (`AratKruglik\WayForPay`) with clear layer separation
- Per-operation signature methods make field ordering explicit and independently testable
- Readonly DTOs prevent accidental state mutation

## 16. Known Limitations

1. **Debug mode not implemented**: The `debug` config option is defined but not used in the current implementation.
2. **No automatic retry**: Failed API requests are not retried. Applications should implement retry logic if needed.
3. **Single-merchant only**: One set of credentials per application. Multi-merchant setups require custom service provider overrides.
4. **No webhook route registration**: The package provides `WebhookController` but does not register routes automatically. Applications must define the route manually.
5. **No idempotency handling**: Duplicate webhook deliveries are not deduplicated by the package.

## 17. Glossary

| Term | Definition |
|------|-----------|
| **merchantAccount** | Unique identifier assigned to the merchant by WayForPay |
| **merchantSignature** | HMAC-MD5 hash authenticating a request or callback |
| **orderReference** | Merchant-generated unique order identifier (max 64 chars) |
| **serviceUrl** | Merchant URL where WayForPay sends payment callbacks (webhooks) |
| **returnUrl** | URL where the customer is redirected after payment |
| **reasonCode** | Numeric code indicating the result of an API operation |
| **rec2Token** | Token for recurring payments, received after initial card charge |
| **Regular API** | WayForPay's API for managing recurring subscriptions |
| **settle** | Capture a previously authorized (but not yet settled) transaction |
| **3DS** | 3-D Secure - cardholder authentication protocol |
| **Luhn check** | Checksum algorithm for validating card numbers |
| **hold** | A two-phase payment where funds are frozen (AUTH) without being captured, until a later `settle()` or `cancelHold()` |
| **holdTimeout** | Seconds WayForPay keeps a hold active before auto-cancelling it (60..1728000) |
| **AUTH** | `merchantTransactionType` value that creates a hold instead of an immediate sale (`SALE`) |
