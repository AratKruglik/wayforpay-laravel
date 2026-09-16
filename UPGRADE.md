# Upgrade Guide

## 3.0 → 3.1

This release fixes the `/verify` (card verification) integration, which never worked as documented, and fixes a webhook payload-decoding bug. Both fixes required breaking `WayForPayInterface`. See [ADR-0009](docs/adr/0009-verify-is-a-browser-form-post.md) for the full rationale.

### `verifyCard()` is removed from `WayForPayInterface` and now always throws

`/verify` is a browser form-POST endpoint, not a server-to-server JSON API — `verifyCard()` never returned a working URL, it always failed to find a `url` key in the response.

- `WayForPayInterface::verifyCard()` no longer exists. If you implement `WayForPayInterface` directly (there are no such implementations inside this package itself), remove your `verifyCard()` implementation.
- `WayForPayService::verifyCard()` still exists as a deprecated stub and now always throws `LogicException`.
- Replace any call to `verifyCard($orderReference, $currency)` with the new pair:

```php
// Before (never worked):
$url = WayForPay::verifyCard('VERIFY_ORDER_001');

// After:
$html = WayForPay::verify(
    orderReference: 'VERIFY_ORDER_001',
    returnUrl: 'https://myshop.com/verify/return',
    serviceUrl: 'https://myshop.com/api/wayforpay/callback',
);
return response($html);
```

`recToken` is delivered to `serviceUrl` via a webhook, exactly like `regularMode` — it is never present in the HTTP response to the initial request, browser-rendered or not.

### `WayForPayInterface` gains three new methods

`getVerifyFormData()`, `verify()`, and `handleWebhookRequest()` are now part of `WayForPayInterface`. If you implement this interface directly, you must implement all three.

### Webhook handling: use `handleWebhookRequest($request)` instead of `handleWebhook($request->all())`

`$request->all()` silently corrupts a raw-JSON webhook body when the request lacks a JSON `Content-Type` header — PHP's form-urlencoded parser replaces `.` and spaces in POST field names with `_`, so a body containing a decimal `amount` (e.g. `1.5`) fails to round-trip through `json_decode()` by field name.

```php
// Before:
$response = $service->handleWebhook($request->all());

// After:
$response = $service->handleWebhookRequest($request);
```

The built-in `WebhookController` has been updated internally; if you use Option A (the built-in controller), no change is required on your side. `handleWebhook(array $data): array` still exists unchanged for callers who already have a decoded array.

### `WayForPayException::getResponseData()` now carries more data for VERIFY/token-lookup failures

When an API response is missing an expected key (the `returnKey` failure branch, used by the CHARGE-with-token/VERIFY token-lookup paths), the thrown `WayForPayException` now also carries `reasonCode` and the full response body via `getResponseData()`, matching the behavior already used elsewhere in the same method. If your response bodies for these operations can contain `recToken`/`rec2Token`/card data, be aware that a caught exception's `getResponseData()` may now include it — this is a known, accepted, and unchanged limitation (see `docs/plans/token-based-charging/04-security.md`, Medium #1), not new to this release.
