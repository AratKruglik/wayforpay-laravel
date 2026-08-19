# WayForPay Laravel Package

![Tests](https://github.com/AratKruglik/wayforpay-laravel/actions/workflows/tests.yml/badge.svg)
![License](https://img.shields.io/packagist/l/aratkruglik/wayforpay-laravel)
![Version](https://img.shields.io/packagist/v/aratkruglik/wayforpay-laravel)

Native Laravel integration for the [WayForPay](https://wayforpay.com) payment gateway. Built on `Illuminate\Http\Client` with no external SDK dependencies. Provides strict DTOs, automatic HMAC_MD5 signature handling, and built-in webhook support.

Supports **Laravel 11.x-13.x** and **PHP 8.2+**.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Purchase (Widget)](#1-purchase-widget)
  - [Invoices](#2-invoices)
  - [Direct Charge (Host-to-Host)](#3-direct-charge-host-to-host)
  - [Recurring Payments](#4-recurring-payments)
  - [Refunds](#5-refunds)
  - [Holds (Two-Phase Payments)](#6-holds-two-phase-payments)
    - [Creating a hold via widget](#creating-a-hold-via-widget)
    - [Creating a hold host-to-host](#creating-a-hold-host-to-host)
    - [Settling a hold](#settling-a-hold)
    - [Cancelling a hold](#cancelling-a-hold)
    - [Hold statuses and lifecycle](#hold-statuses-and-lifecycle)
  - [Token-Based Charging (Merchant-Initiated)](#7-token-based-charging-merchant-initiated)
  - [P2P Credit (Payouts)](#8-p2p-credit-payouts)
  - [P2P Account Transfer](#9-p2p-account-transfer)
  - [Card Verification](#10-card-verification)
  - [Check Status](#11-check-status)
- [Webhooks](#webhooks)
- [Marketplace Integration (MMS API)](#marketplace-integration-mms-api)
  - [Partner Management](#partner-management)
  - [Merchant Management](#merchant-management)
  - [Balance and Reporting](#balance-and-reporting)
- [Testing](#testing)
- [License](#license)

---

## Installation

```bash
composer require aratkruglik/wayforpay-laravel
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=wayforpay-config
```

Add credentials to `.env`:

```env
WAYFORPAY_MERCHANT_ACCOUNT=your_merchant_login
WAYFORPAY_SECRET_KEY=your_secret_key
WAYFORPAY_MERCHANT_DOMAIN=your_domain.com
WAYFORPAY_HOLD_TIMEOUT=1728000
```

`WAYFORPAY_HOLD_TIMEOUT` is the default `holdTimeout` (in seconds) used by `hold()`/`holdCharge()` when `Transaction::$holdTimeout` is not set. An invalid value (outside 60..1728000) surfaces as an `InvalidArgumentException` at `hold()`/`holdCharge()` call time, not at application boot.

---

## Usage

### 1. Purchase (Widget)

Generate a self-submitting HTML form that redirects the user to the WayForPay checkout page.

```php
use AratKruglik\WayForPay\Facades\WayForPay;
use AratKruglik\WayForPay\Domain\Transaction;
use AratKruglik\WayForPay\Domain\Product;
use AratKruglik\WayForPay\Domain\Client;

$client = new Client(
    nameFirst: 'John',
    nameLast: 'Doe',
    email: 'john@example.com',
    phone: '+380501234567'
);

$transaction = new Transaction(
    orderReference: 'ORDER_' . time(),
    amount: 100.50,
    currency: 'UAH',
    orderDate: time(),
    client: $client,
    paymentSystems: 'card;googlePay;applePay'
);

$transaction->addProduct(new Product('T-Shirt', 100.50, 1));

$html = WayForPay::purchase(
    $transaction,
    returnUrl: 'https://myshop.com/payment/success',
    serviceUrl: 'https://myshop.com/api/wayforpay/callback'
);

return response($html);
```

#### Custom Form Rendering

For SPA or custom frontend integration, use `getPurchaseFormData` to get raw form fields:

```php
$formData = WayForPay::getPurchaseFormData($transaction, $returnUrl, $serviceUrl);

return response()->json([
    'form_action' => 'https://secure.wayforpay.com/pay',
    'form_data' => $formData,
]);
```

Submit the form programmatically on the client side:

```javascript
const form = document.createElement('form');
form.method = 'POST';
form.action = data.form_action;

Object.entries(data.form_data).forEach(([key, value]) => {
    if (Array.isArray(value)) {
        value.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${key}[]`;
            input.value = item;
            form.appendChild(input);
        });
    } else {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }
});

document.body.appendChild(form);
form.submit();
```

### 2. Invoices

Generate a payment link to send via email or messenger.

```php
$response = WayForPay::createInvoice($transaction, returnUrl: 'https://myshop.com/success');
$invoiceUrl = $response['invoiceUrl'];

WayForPay::removeInvoice('ORDER_123');
```

### 3. Direct Charge (Host-to-Host)

> **Warning:** Requires PCI DSS compliance when handling raw card data server-side.

For the AUTH (hold) variant of a direct charge, see [Creating a hold host-to-host](#creating-a-hold-host-to-host).

```php
use AratKruglik\WayForPay\Domain\Card;
use AratKruglik\WayForPay\Enums\ReasonCode;

$card = new Card(
    cardNumber: '4111111111111111',
    expMonth: '12',
    expYear: '25',
    cvv: '123',
    holderName: 'JOHN DOE'
);

$response = WayForPay::charge($transaction, $card);

$code = ReasonCode::tryFrom((int) $response['reasonCode']);
if ($code?->isSuccess()) {
    // Payment successful
}
```

### 4. Recurring Payments

Create a subscription by passing regular payment parameters during the initial purchase:

```php
$transaction = new Transaction(
    orderReference: 'SUB_123',
    amount: 100.00,
    currency: 'UAH',
    orderDate: time(),
    regularMode: 'monthly',
    regularAmount: 100.00,
    dateNext: '25.05.2025',
    dateEnd: '25.05.2026'
);

$html = WayForPay::purchase($transaction);
```

Manage existing subscriptions:

```php
WayForPay::suspendRecurring('SUB_123');
WayForPay::resumeRecurring('SUB_123');
WayForPay::removeRecurring('SUB_123');
```

### 5. Refunds

```php
WayForPay::refund('ORDER_123', 50.00, 'UAH', 'Customer return');
```

### 6. Holds (Two-Phase Payments)

A hold (AUTH) freezes funds on the customer's card without capturing them. You create the hold first, then either **settle** it (capture some or all of the frozen amount) or **cancel** it (release the funds). Typical uses: marketplace bookings that need confirmation before charging, rental deposits, or any flow where the final amount is only known after the AUTH step. See the [WayForPay wiki](https://wiki.wayforpay.com/view/852113) for the underlying API contract.

`holdTimeout` controls how long WayForPay keeps the hold active:

| | Value |
|---|---|
| Unit | seconds |
| Default | `1728000` (20 days) |
| Minimum | `60` |
| Maximum | `1728000` |

```php
$transaction = new Transaction(
    orderReference: 'HOLD_' . time(),
    amount: 100.50,
    currency: 'UAH',
    orderDate: time(),
    holdTimeout: 7 * 86400, // 7 days
);
$transaction->addProduct(new Product('Booking deposit', 100.50, 1));
```

> **Warning:** 3DS is not supported for holds. If `merchantTransactionSecureType=3DS` is used, WayForPay returns `InProcessing` with `reasonCode` 5100 and requires a `COMPLETE_3DS` follow-up call that this package does not implement. `holdCharge()` always runs in `AUTO` mode. Consumers should check `ReasonCode::isPending()` on the response before treating it as final.

> **Warning:** Without a `settle()` call, WayForPay auto-cancels the hold within up to 21 calendar days. `holdTimeout` itself caps at 20 days (1728000 seconds) — it cannot be used to extend the window past that.

> **Warning:** Holds are **incompatible** with installment payment systems (`payParts`, `payPartsMono`, `payPartsPrivat`, ...). For those, the bank pays the merchant immediately and collects from the buyer in instalments, so there is nothing to freeze. Use `purchase()` + `refund()` instead.

> **Warning:** On the widget path, `merchantTransactionType` and `holdTimeout` are **not** part of the HMAC signature, so a determined client can tamper with them in the browser before submitting the form. Never base fulfilment decisions (shipping goods, granting access) on the synchronous response or on the assumption that the submitted form values were honoured — rely on the `transactionStatus` received via the signed webhook or via `checkStatus()` instead.

#### Creating a hold via widget

```php
$html = WayForPay::hold(
    $transaction,
    returnUrl: 'https://myshop.com/payment/success',
    serviceUrl: 'https://myshop.com/api/wayforpay/callback'
);

return response($html);
```

The generated form sends `merchantTransactionType=AUTH` and `holdTimeout` in addition to the regular purchase fields. For custom rendering, use `getHoldFormData()` the same way as `getPurchaseFormData()`:

```php
$formData = WayForPay::getHoldFormData($transaction, $returnUrl, $serviceUrl);
```

#### Creating a hold host-to-host

> **Warning:** Requires PCI DSS compliance when handling raw card data server-side.

```php
$response = WayForPay::holdCharge($transaction, $card);

$code = ReasonCode::tryFrom((int) $response['reasonCode']);
if ($code?->isPending()) {
    // AUTH is still in progress (e.g. WAIT_3DS_DATA, TRANSACTION_IN_PROCESSING) — do not treat as final yet
} elseif ($code?->isSuccess()) {
    // AUTH accepted synchronously — the hold is in place
}
```

Neither branch is a substitute for the webhook: always confirm the final state via the signed webhook or `checkStatus()`.

#### Settling a hold

Capture the full authorized amount:

```php
WayForPay::settle('HOLD_123', 100.50, 'UAH');
```

Capture a partial amount — the remainder is automatically released back to the customer:

```php
WayForPay::settle('HOLD_123', 60.00, 'UAH');
```

Optionally attach line items:

```php
WayForPay::settle('HOLD_123', 60.00, 'UAH', products: [
    new Product('Booking deposit', 60.00, 1),
]);
```

The package does **not** validate that `sum(price * count)` matches `amount`, nor that `amount` does not exceed the original AUTH amount — it has no local state about the hold. WayForPay validates both server-side and rejects mismatches with a non-success `reasonCode`, which surfaces as a `WayForPayException`.

#### Cancelling a hold

> **Warning:** `cancelHold()` is not a safe no-op. On an already-settled transaction it performs a **real refund** of captured funds, because it is the same `REFUND` operation under the hood. Verify the transaction is still held (e.g. `checkStatus()` + `TransactionStatus::isHold()`) before calling it.

```php
WayForPay::cancelHold('HOLD_123', 100.50, 'UAH');
```

`cancelHold()` is a semantic alias for the same `REFUND` operation used elsewhere in this package — calling `refund()` directly on a held transaction releases the hold just as well.

#### Hold statuses and lifecycle

| Status | Meaning |
|---|---|
| `WaitingAuthComplete` | Hold created, awaiting settle or cancellation |
| `WaitingAmountConfirm` | Awaiting amount confirmation (also used outside holds) |
| `Approved` | Settled (captured) or otherwise completed |
| `Expired` | Auto-cancelled without a `settle()` call |
| `Refunded` | Cancelled via `cancelHold()`/`refund()` |

```php
use AratKruglik\WayForPay\Enums\TransactionStatus;

Event::listen(WayForPayCallbackReceived::class, function ($event) {
    $status = TransactionStatus::tryFrom($event->data['transactionStatus']);

    if ($status?->isHold()) {
        // Hold is still open — neither settled nor cancelled yet
    }
});
```

The synchronous `transactionStatus` returned immediately after creating a hold is not guaranteed by WayForPay's documentation to be one specific value — do not build logic around a single expected status; use `isHold()` instead.

### 7. Token-Based Charging (Merchant-Initiated)

Charge (or hold) a previously-saved card via WayForPay's `recToken` mechanism, **without the cardholder present**, at any moment your application chooses — e.g. exactly 24 hours before a scheduled event, or a hold placed automatically at a scheduled time rather than at booking time. This is different from `regularMode` subscriptions (§4), where WayForPay itself owns the recurring schedule; here, your application decides when to charge.

> **Warning:** WayForPay's public documentation does not contain an explicit statement that `merchantTransactionType=AUTH` may be combined with `recToken`. Support for `holdChargeWithToken()` is inferred from the CHARGE parameter table (see [ADR-0008](docs/adr/0008-token-based-charging.md) for the full research trail), not from a direct confirmation of this specific combination. **Verify this combination against a WayForPay sandbox / test merchant account before relying on it in production.**

**Note:** unlike [Direct Charge](#3-direct-charge-host-to-host) and [Creating a hold host-to-host](#creating-a-hold-host-to-host), token-based charging does **not** require PCI DSS compliance on your side — the raw card number never passes through your application for this path.

**Note:** merchant-initiated transactions require the cardholder's consent to future charges, obtained by your application at the time the token was created (e.g. during `regularMode` setup or `verifyCard()`) — this package does not track or enforce consent.

```php
use AratKruglik\WayForPay\Domain\CardToken;

$token = new CardToken($savedRecToken);

// SALE — immediate withdrawal
$response = WayForPay::chargeWithToken($transaction, $token);

// AUTH — hold funds, to be settled later via settle()
$holdTransaction = new Transaction(
    orderReference: 'HOLD_' . time(),
    amount: 100.50,
    currency: 'UAH',
    orderDate: time(),
    holdTimeout: 7 * 86400, // 7 days
);
$holdTransaction->addProduct(new Product('Booking deposit', 100.50, 1));

$response = WayForPay::holdChargeWithToken($holdTransaction, $token);
// ... later, at the moment your application chooses:
WayForPay::settle($holdTransaction->orderReference, 100.50, 'UAH');
```

**Note:** since token-based charging is typically triggered from a cron job or queue worker, there is no HTTP request context to derive `clientIpAddress` from. The package does **not** substitute a fallback IP for this path — if no `Domain\Client` is attached to the `Transaction`, `clientIpAddress` is simply omitted from the request. To send a meaningful value for WayForPay's fraud scoring, attach a `Domain\Client` carrying the IP address that was captured when the token was originally created.

**Note:** generate a unique `orderReference` for every charge attempt, and prefer `checkStatus()` over blindly retrying after a network timeout — WayForPay may have already accepted the charge even if your process never saw the response.

`recToken` is obtained from a `regularMode` transaction or from `verifyCard()`, and — like the rest of the raw webhook payload — is already delivered to your application unchanged via the existing `WayForPayCallbackReceived` event; see [Webhooks](#webhooks). This package does not persist tokens: storing and retrieving `recToken` for later use is your application's responsibility (it is a stateless HTTP-client wrapper, see [ADR-0001](docs/adr/0001-laravel-http-client-over-external-sdk.md)).

### 8. P2P Credit (Payouts)

Send funds from the merchant account to a recipient card:

```php
WayForPay::p2pCredit(
    orderReference: 'PAYOUT_001',
    amount: 500.00,
    currency: 'UAH',
    cardBeneficiary: '4111111111111111'
);
```

### 9. P2P Account Transfer

Transfer funds to a bank account (UAH only):

```php
use AratKruglik\WayForPay\Domain\AccountTransfer;

$transfer = new AccountTransfer(
    orderReference: 'TRANSFER_001',
    amount: 1500.00,
    currency: 'UAH',
    iban: 'UA213223130000026007233566001',
    okpo: '12345678',
    accountName: 'FOP Ivanov I.I.',
    description: 'Payout for services',
    serviceUrl: 'https://myshop.com/api/wayforpay/callback',
    recipientEmail: 'recipient@example.com'
);

$response = WayForPay::p2pAccount($transfer);
```

### 10. Card Verification

Verify a card by blocking a small amount that is automatically reversed:

```php
$url = WayForPay::verifyCard('VERIFY_ORDER_001');
return redirect($url);
```

### 11. Check Status

```php
$status = WayForPay::checkStatus('ORDER_123');
// $status['transactionStatus']
```

---

## Webhooks

The package handles signature verification automatically.

**Option A: Built-in controller with event dispatching**

Register the route in `routes/api.php`:

```php
Route::post('wayforpay/callback', \AratKruglik\WayForPay\Http\Controllers\WebhookController::class);
```

Listen for the event:

```php
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;

Event::listen(WayForPayCallbackReceived::class, function ($event) {
    $data = $event->data;

    if ($data['transactionStatus'] === 'Approved') {
        // Update order status
    }
});
```

**Option B: Manual handling in a custom controller**

```php
use AratKruglik\WayForPay\Services\WayForPayService;
use AratKruglik\WayForPay\Exceptions\WayForPayException;

public function handle(Request $request, WayForPayService $service)
{
    try {
        $response = $service->handleWebhook($request->all());

        // Process order logic...

        return response()->json($response);
    } catch (WayForPayException $e) {
        return response()->json(['status' => 'error'], 400);
    }
}
```

---

## Marketplace Integration (MMS API)

The MMS (Merchant Management System) API enables marketplace platforms to programmatically manage sub-merchants and partners, configure compensation (payout) methods, and query balances.

Available via the `Mms` facade or constructor injection:

```php
use AratKruglik\WayForPay\Facades\Mms;

// Facade
Mms::addPartner($partner);

// Constructor injection
use AratKruglik\WayForPay\Contracts\MmsServiceInterface;

class PartnerController extends Controller
{
    public function __construct(
        private readonly MmsServiceInterface $mms
    ) {}
}
```

### Partner Management

#### Register a new partner

```php
use AratKruglik\WayForPay\Facades\Mms;
use AratKruglik\WayForPay\Domain\Partner;
use AratKruglik\WayForPay\Domain\CompensationCard;
use AratKruglik\WayForPay\Domain\CompensationAccount;

// Option 1: Compensation via card
$partner = new Partner(
    partnerCode: 'PARTNER_001',
    site: 'https://partner-shop.com',
    phone: '+380501234567',
    email: 'partner@example.com',
    description: 'Partner shop description',
    compensationCard: new CompensationCard(
        cardNumber: '4111111111111111',
        expMonth: '12',
        expYear: '25',
        cvv: '123',
        holderName: 'PARTNER NAME'
    )
);

// Option 2: Compensation via bank account
$partner = new Partner(
    partnerCode: 'PARTNER_002',
    site: 'https://partner-shop.com',
    phone: '+380501234567',
    email: 'partner@example.com',
    compensationAccount: new CompensationAccount(
        iban: 'UA213223130000026007233566001',
        okpo: '12345678',
        name: 'FOP Partner Name'
    )
);

// Option 3: Compensation via tokenized card
$partner = new Partner(
    partnerCode: 'PARTNER_003',
    site: 'https://partner-shop.com',
    phone: '+380501234567',
    email: 'partner@example.com',
    compensationCardToken: 'card_token_from_wayforpay'
);

$response = Mms::addPartner($partner);
```

#### Query partner info

```php
$info = Mms::partnerInfo('PARTNER_001');
```

#### Update partner details

```php
Mms::updatePartner('PARTNER_001', [
    'phone' => '+380509876543',
    'email' => 'new-email@example.com',
    'compensationCardToken' => 'new_token',
]);
```

### Merchant Management

#### Register a sub-merchant

```php
use AratKruglik\WayForPay\Facades\Mms;
use AratKruglik\WayForPay\Domain\Merchant;
use AratKruglik\WayForPay\Domain\CompensationCard;

$merchant = new Merchant(
    site: 'https://sub-merchant.com',
    phone: '+380501234567',
    email: 'merchant@example.com',
    description: 'Sub-merchant description',
    compensationCard: new CompensationCard(
        cardNumber: '4111111111111111'
    )
);

$response = Mms::addMerchant($merchant);
```

#### Query merchant info

Requires the sub-merchant's account ID and secret key:

```php
$info = Mms::merchantInfo('sub_merchant_account', 'sub_merchant_secret_key');
```

### Balance and Reporting

```php
use AratKruglik\WayForPay\Facades\Mms;

$balance = Mms::merchantBalance();

// With date filter (dd.mm.yyyy format)
$balance = Mms::merchantBalance('01.01.2026');
```

---

## Upgrading from 1.x to 2.0

Version 2.0 adds hold (two-phase payment) support and is a deliberate semver BC-break in the `WayForPayInterface` contract:

- **`WayForPayInterface` gained new methods** (`hold`, `getHoldFormData`, `holdCharge`, `cancelHold`). Any external implementation of this interface (e.g. a test double or a decorator) must implement them too, or it will no longer compile against the interface.
- **`settle()` gained an optional 4th argument** (`?array $products = null`). Existing 3-argument calls remain unaffected.
- **`TransactionStatus` gained a new case** (`WAITING_AUTH_COMPLETE`) and a new `isHold()` method. `isFinal()`/`isSuccess()` behavior for existing cases is unchanged.
- **`ReasonCode` gained three new cases** (`TRANSACTION_IN_PROCESSING`, `WAIT_3DS_DATA`, `WAITING_AMOUNT_CONFIRM`) and a new `isPending()` method. `HandlesApiResponse::parseResponse()` now treats pending codes as non-error, so responses carrying these codes no longer throw `WayForPayException`.
- **New guard on `holdTimeout`**: passing a `Transaction` with `holdTimeout` set into `purchase()`, `getPurchaseFormData()`, `charge()`, or `createInvoice()` now throws `InvalidArgumentException`. `holdTimeout` is only valid for `hold()`, `getHoldFormData()`, and `holdCharge()`.

---

## Upgrading from 2.x to 3.0

Version 3.0 adds token-based charging (`recToken`) support and is a deliberate semver BC-break in the `WayForPayInterface` contract:

- **`WayForPayInterface` gained new methods** (`chargeWithToken`, `holdChargeWithToken`). Any external implementation of this interface (e.g. a test double or a decorator) must implement them too, or it will no longer compile against the interface.
- **New `Domain\CardToken` DTO.** No existing DTO changed shape.
- **`HOLD_TIMEOUT_NOT_ALLOWED_MESSAGE` gained a suffix** pointing to `holdChargeWithToken()`. The original message text is preserved as an exact prefix, so any existing string-matching against the previous message (e.g. `str_contains`) continues to work unmodified.
- No other public method signatures changed; existing calls to `charge()`, `holdCharge()`, `purchase()`, `hold()`, etc. are unaffected.

---

## Testing

```bash
vendor/bin/pest
```

---

## License

The MIT License (MIT). See [License File](LICENSE.md) for details.
