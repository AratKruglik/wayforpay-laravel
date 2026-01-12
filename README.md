# WayForPay Laravel Package

![Tests](https://github.com/AratKruglik/wayforpay-laravel/actions/workflows/tests.yml/badge.svg)
![License](https://img.shields.io/packagist/l/aratkruglik/wayforpay-laravel)
![Version](https://img.shields.io/packagist/v/aratkruglik/wayforpay-laravel)

A robust, native Laravel integration for the WayForPay payment gateway. This package provides a complete replacement for the legacy `wayforpay/php-sdk`, offering modern PHP features, strict typing, and seamless integration with Laravel's ecosystem (Service Container, Events, Http Client).

Supports **Laravel 11.x, 12.x** and **PHP 8.2+**.

## 🚀 Features

- **Native Implementation:** Built directly on top of `Illuminate\Support\Facades\Http`. No external SDK dependencies.
- **Complete API Coverage:**
    - 🛒 **Purchase Widget:** Generate secure payment URLs.
    - 💳 **Direct Charge (Host-to-Host):** Process payments server-side.
    - 🧾 **Invoices:** Create and manage invoices via API.
    - 🔄 **Recurring Payments:** Complete subscription management (create, suspend, resume, remove).
    - 💸 **P2P Credit:** Send funds to cards (Account to Card).
    - 🔒 **Holds & Settlement:** Authorize and capture funds (Auth/Settle).
    - ↩️ **Refunds:** Full or partial refunds.
    - ✅ **Card Verification:** Verify card validity.
- **Strict DTOs:** Data Transfer Objects (`Transaction`, `Product`, `Client`, `Card`) ensure data integrity before sending requests.
- **Secure:** Automatic HMAC_MD5 signature generation and verification.
- **Webhooks:** Built-in controller and Event dispatching for easy webhook handling.

---

## 📦 Installation

Install the package via Composer:

```bash
composer require aratkruglik/wayforpay-laravel
```

## ⚙️ Configuration

1. **Publish the configuration file:**

```bash
php artisan vendor:publish --tag=wayforpay-config
```

2. **Add credentials to your `.env` file:**

```env
WAYFORPAY_MERCHANT_ACCOUNT=your_merchant_login
WAYFORPAY_SECRET_KEY=your_secret_key
WAYFORPAY_MERCHANT_DOMAIN=your_domain.com
```

---

## 🛠 Usage

### 1. Purchase (Widget)

Generate a self-submitting HTML form that redirects the user to the secure WayForPay checkout page.

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
    paymentSystems: 'card;googlePay;applePay' // Optional: limit payment methods
);

$transaction->addProduct(new Product('T-Shirt', 100.50, 1));

// Returns HTML with auto-submitting form
$html = WayForPay::purchase(
    $transaction,
    returnUrl: 'https://myshop.com/payment/success',
    serviceUrl: 'https://myshop.com/api/wayforpay/callback'
);

return response($html);
```

#### Custom Form Rendering

If you need to render the form yourself (e.g., for SPA applications):

```php
// Get raw form data as an array
$formData = WayForPay::getPurchaseFormData($transaction, $returnUrl, $serviceUrl);

// Pass to your frontend
return response()->json([
    'form_action' => 'https://secure.wayforpay.com/pay',
    'form_data' => $formData
]);
```

Then in your JavaScript:

```javascript
// Create and submit form programmatically
const form = document.createElement('form');
form.method = 'POST';
form.action = 'https://secure.wayforpay.com/pay';

Object.entries(formData).forEach(([key, value]) => {
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

Generate a payment link that can be sent to a client via email or messenger.

```php
// Create Invoice
$response = WayForPay::createInvoice($transaction, returnUrl: 'https://myshop.com/success');
$invoiceUrl = $response['invoiceUrl'];

// Remove Invoice (if needed)
WayForPay::removeInvoice('ORDER_123');
```

### 3. Direct Charge (Host-to-Host)

**⚠️ Warning:** Requires PCI DSS compliance if handling raw card data on your server.

```php
use AratKruglik\WayForPay\Domain\Card;

$card = new Card(
    cardNumber: '4111111111111111',
    expMonth: '12',
    expYear: '25',
    cvv: '123',
    holderName: 'JOHN DOE'
);

$response = WayForPay::charge($transaction, $card);

if ($response['reasonCode'] == 1100) {
    // Payment Successful
}

// Or using the built-in Enum
use AratKruglik\WayForPay\Enums\ReasonCode;

$code = ReasonCode::tryFrom((int)$response['reasonCode']);
if ($code?->isSuccess()) {
    // Success
}
```

### 4. Recurring Payments (Subscriptions)

**Step 1: Create Subscription**
Pass regular payment parameters during the initial purchase.

```php
$transaction = new Transaction(
    orderReference: 'SUB_123',
    amount: 100.00,
    currency: 'UAH',
    orderDate: time(),
    regularMode: 'monthly', // 'daily', 'weekly', 'quarterly', etc.
    regularAmount: 100.00,
    dateNext: '25.05.2025',
    dateEnd: '25.05.2026'
);

$url = WayForPay::purchase($transaction);
```

**Step 2: Manage Subscription**

```php
// Pause subscription
WayForPay::suspendRecurring('SUB_123');

// Resume subscription
WayForPay::resumeRecurring('SUB_123');

// Cancel subscription permanently
WayForPay::removeRecurring('SUB_123');
```

### 5. Refunds

Process a full or partial refund.

```php
// Refund 50 UAH from the order
WayForPay::refund('ORDER_123', 50.00, 'UAH', 'Customer return');
```

### 6. Holds (Two-Phase Payments)

**Authorize (Hold funds):**
Use `charge` with `merchantTransactionType` set to `'AUTH'`. (Currently, `charge` defaults to `SALE`, you may need to extend or modify the `Transaction` config if complex auth flows are needed, or simply rely on `purchase` with hold settings).

**Settle (Confirm transaction):**

```php
// Confirm and withdraw the held amount
WayForPay::settle('ORDER_123', 100.50, 'UAH');
```

### 7. P2P Credit (Payouts)

Send money from your merchant account to a client's card.

```php
WayForPay::p2pCredit(
    orderReference: 'PAYOUT_001',
    amount: 500.00,
    currency: 'UAH',
    cardBeneficiary: '4111111111111111' // Recipient card
);
```

### 8. Card Verification

Verify a card by blocking a random amount (e.g., 1 UAH) which is then reversed.

```php
$url = WayForPay::verifyCard('VERIFY_ORDER_001');
return redirect($url);
```

### 9. Check Status

```php
$status = WayForPay::checkStatus('ORDER_123');
// $status['transactionStatus']
```

---

## 🪝 Webhooks (Callback Handling)

This package handles signature verification automatically.

**Option A: Use the built-in Event**

Create a route in your `routes/api.php` that points to the built-in controller:

```php
Route::post('wayforpay/callback', \AratKruglik\WayForPay\Http\Controllers\WebhookController::class);
```

Then listen for the `WayForPayCallbackReceived` event in your `EventServiceProvider`:

```php
use AratKruglik\WayForPay\Events\WayForPayCallbackReceived;

Event::listen(WayForPayCallbackReceived::class, function ($event) {
    $data = $event->data;
    
    if ($data['transactionStatus'] === 'Approved') {
        // Update order status in database
    }
});
```

**Option B: Manual Handling**

Use the service method within your own controller:

```php
public function handle(Request $request, \AratKruglik\WayForPay\Services\WayForPayService $service)
{
    try {
        // Validates signature and returns the correct success response array for WayForPay
        $response = $service->handleWebhook($request->all());
        
        // Process your logic here...
        
        return response()->json($response);
    } catch (\AratKruglik\WayForPay\Exceptions\WayForPayException $e) {
        return response()->json(['status' => 'error'], 400);
    }
}
```

---

## ✅ Testing

Run the test suite with Pest:

```bash
vendor/bin/pest
```

---

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.