# ADR-0002: purchase() Returns HTML Form, Not a URL

## Status

Accepted

## Context

WayForPay's hosted payment page (`https://secure.wayforpay.com/pay`) accepts payment data via a POST form submission. Unlike redirect-based gateways that provide a URL with query parameters, WayForPay requires the merchant to POST form fields including signed data directly to their payment endpoint.

The `purchase()` method needs to initiate a browser-side payment flow. The question is what it should return.

## Alternatives Considered

### 1. Return a redirect URL with query parameters

- **Pros:** Simple to use; standard redirect pattern.
- **Cons:** WayForPay's hosted page requires POST, not GET. Query parameter URLs would not work.

### 2. Return raw form data array (let the developer build the form)

- **Pros:** Maximum flexibility; developer controls the UI.
- **Cons:** Every consumer must manually build the HTML form and handle auto-submission; error-prone; repetitive boilerplate. This option is still available via `getPurchaseFormData()`.

### 3. Return a self-submitting HTML page (chosen)

- **Pros:** Ready to use as an HTTP response; handles POST submission automatically; secure (HTML-escaped values); works in all browsers.
- **Cons:** Returns an HTML string rather than a structured data type; assumes the developer wants auto-submission.

## Decision

`purchase()` returns a complete, self-submitting HTML document that POSTs the signed form data to `https://secure.wayforpay.com/pay`. A companion method `getPurchaseFormData()` returns the raw key-value array for developers who need custom form rendering.

```php
public function purchase(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): string;
public function getPurchaseFormData(Transaction $transaction, ?string $returnUrl = null, ?string $serviceUrl = null): array;
```

The HTML form escapes all values with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` to prevent XSS.

## Consequences

### Positive

- **Zero-effort integration**: `return response($wayforpay->purchase($transaction))` is a complete payment redirect.
- **Secure by default**: all form values are HTML-escaped.
- **Escape hatch available**: `getPurchaseFormData()` provides raw data for custom implementations (AJAX, SPAs, custom form styling).

### Negative

- **Opinionated output**: the HTML structure is fixed. Developers who need a different HTML layout must use `getPurchaseFormData()` instead.
- **String return type**: unlike other methods that return arrays, `purchase()` returns a string, which is a minor inconsistency in the API surface.
