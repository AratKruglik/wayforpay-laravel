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
  - [P2P Credit (Payouts)](#7-p2p-credit-payouts)
  - [P2P Account Transfer](#8-p2p-account-transfer)
  - [Card Verification](#9-card-verification)
  - [Check Status](#10-check-status)
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
```

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

Settle a previously authorized hold:

```php
WayForPay::settle('ORDER_123', 100.50, 'UAH');
```

### 7. P2P Credit (Payouts)

Send funds from the merchant account to a recipient card:

```php
WayForPay::p2pCredit(
    orderReference: 'PAYOUT_001',
    amount: 500.00,
    currency: 'UAH',
    cardBeneficiary: '4111111111111111'
);
```

### 8. P2P Account Transfer

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

### 9. Card Verification

Verify a card by blocking a small amount that is automatically reversed:

```php
$url = WayForPay::verifyCard('VERIFY_ORDER_001');
return redirect($url);
```

### 10. Check Status

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

## Testing

```bash
vendor/bin/pest
```

---

## License

The MIT License (MIT). See [License File](LICENSE.md) for details.
