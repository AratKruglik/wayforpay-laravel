# ADR-0009: `/verify` is a Browser Form-POST Endpoint

## Status

Accepted

## Context

`WayForPayService::verifyCard()` posted a JSON body to `https://secure.wayforpay.com/verify` via `Illuminate\Http\Client` and attempted to read a `url` key from the response, mirroring the shape of the server-to-server API methods (`checkStatus()`, `refund()`, etc.). This never worked: `/verify` is not a machine-readable API. It renders a three-step, browser-side card-lookup wizard (`lookupCard` payment system) directly in the cardholder's browser, exactly like `/pay` does for `purchase()`/`hold()`. The response to a server-side POST against `/verify` is the wizard's first-step HTML page, not a JSON document, and it never carries a `url` field to redirect to. Source: <https://wiki.wayforpay.com/en/view/852189>, checked 2026-09-16.

Consequences of the bug as shipped:

- `returnUrl` was never part of the request payload sent from the server, even though the browser-side wizard requires it to know where to send the cardholder back to.
- `serviceUrl` — the channel WayForPay uses to deliver `recToken` after a successful verification — was also never sent.
- `amount => 0` and `paymentSystem => 'lookupCard'` are correct as-is (verification blocks nothing; ADR-0008's "blocking a small amount" README wording, reused for this feature, was also inaccurate).
- The existing `SignatureGenerator::generateForVerify()` only signs five specific keys (`merchantAccount`, `merchantDomainName`, `orderReference`, `amount`, `currency`), so none of the above additions or fixes touch the signature at all — confirmed by reading `SignatureGenerator.php:93-102`, which indexes named keys rather than iterating the payload array.

This mirrors ADR-0002 (`purchase()` returns an HTML form, not a URL) and ADR-0007 (dedicated `hold()`/`getHoldFormData()` pair): `/verify` needed the same "form-builder + auto-submitting-form renderer" pair that `purchase()`/`getPurchaseFormData()` and `hold()`/`getHoldFormData()` already have, not a JSON API call.

## Alternatives Considered

### 1. Delete `verifyCard()` silently vs. keep a deprecation stub vs. do both

- **Delete silently:** removes the broken method from the public contract with no trace; any consumer who (mistakenly) relied on the never-working method sees a plain "undefined method" error with no guidance.
- **Deprecation stub only (no interface removal):** keeps `WayForPayInterface::verifyCard()` in the contract, so a `never`-returning method would violate the interface's `string` return type — not legal PHP covariance in that direction.
- **Both (chosen):** `verifyCard()` is removed from `WayForPayInterface` (so the contract no longer promises a broken operation) but survives on the concrete `WayForPayService` class as a stub, annotated `@deprecated`, with a return type of `never` and a `LogicException` explaining exactly why it never worked and what to call instead. Because the method is no longer part of the interface, PHP's covariance rules for `never` returns are moot for interface conformance — the stub only needs to be legal against its own previous signature, which it is (`never` is a valid covariant narrowing of any return type in PHP 8.1+). This gives existing callers of the concrete class a clear, actionable error instead of a generic "method not found," while giving contract-typed (`WayForPayInterface`) consumers a compile-time signal that the operation doesn't exist.

### 2. `responseData` in `HandlesApiResponse::parseResponse()`'s `returnKey` branch: pass `$json` as-is vs. redact sensitive fields

This decision is unrelated to the `/verify` fix itself (the `returnKey` branch in question serves `chargeWithToken()`/`holdChargeWithToken()`'s `recToken` lookups, and any future `returnKey`-based call), but was raised in the same bug report and resolved in the same change window.

- **Redact (`recToken`, `rec2Token`, `card`, `cardPan`) before attaching to the exception:** closes the response-body exposure surface but changes the public `WayForPayException::getResponseData()` contract for every operation using this branch, in this same 3.1.0 release window.
- **Pass `$json` through unchanged (chosen):** consistent with the existing, unchanged `reasonCode` branch a few lines above in the same method, which already attaches the full `$json` as `responseData` and has done so since before this change. No new inconsistency is introduced between the two failure branches of the same method.
- **Decision:** pass `$json` through unchanged. This was an explicit human decision at this change's approval gate, not a default. It leaves the pre-existing Medium-severity finding recorded in `docs/plans/token-based-charging/04-security.md` (`WayForPayException::getResponseData()` can carry `recToken`) open and, in fact, extended to one more branch (VERIFY/CHARGE token-lookup failures). It is not fixed by this ADR and is not expected to be fixed by it — a future ADR would be required to change `WayForPayException`'s public contract.

## Decision

- `WayForPayService::generateAutoSubmitForm(array $formData, string $actionUrl = self::PAY_FORM_URL): string` gains a second, defaulted parameter, so the same HTML-form renderer used by `purchase()`/`hold()` can also target `/verify`. `PAY_URL`/`VERIFY_URL` are renamed to `PAY_FORM_URL`/`VERIFY_FORM_URL` to make the browser-form nature of both endpoints explicit at the constant-declaration site, distinct from `REGULAR_API_URL` (server-to-server JSON).
- New pair, mirroring `getHoldFormData()`/`hold()`:
  - `getVerifyFormData(string $orderReference, string $returnUrl, ?string $serviceUrl = null, string $currency = 'UAH'): array` — builds and signs the full VERIFY payload (`merchantAccount`, `merchantDomainName`, `apiVersion`, `orderReference`, `amount = 0`, `currency`, `paymentSystem = 'lookupCard'`, `returnUrl`, optional `serviceUrl`, `merchantSignature`). `orderReference` and `currency` are validated via the same `Domain\Concerns\ValidatesOrderReference`/`ValidatesCurrency` traits `Transaction` uses, and `returnUrl`/`serviceUrl` are validated via the existing private `validateUrl()` — exactly as `buildPurchaseFormData()` already does.
  - `verify(string $orderReference, string $returnUrl, ?string $serviceUrl = null, string $currency = 'UAH'): string` — calls `generateAutoSubmitForm($this->getVerifyFormData(...), self::VERIFY_FORM_URL)`.
- `verifyCard()` is removed from `WayForPayInterface` and survives on `WayForPayService` only as a `@deprecated`, `never`-returning stub that throws `LogicException` pointing callers at `verify()`/`getVerifyFormData()`.
- `WayForPayInterface` also gains `handleWebhookRequest(Request $request): array` (see below) as a deliberate, non-incidental contract addition.
- Currency whitelist validation (`['UAH','USD','EUR','PLN','GBP']`), previously private and duplicated only inside `Domain\Transaction`, is extracted to `Domain\Concerns\ValidatesCurrency`, mirroring the existing `ValidatesOrderReference` trait pattern. `Transaction` and `WayForPayService` both consume it. The validated value is the **raw, unmodified** currency string — no `strtoupper()` normalization is applied, so `getVerifyFormData()`'s validation and `Transaction`'s validation reject and accept the exact same set of literal strings.
- `WayForPayService::handleWebhook(array $data): array` is kept unchanged as the low-level entry point. A new `handleWebhookRequest(Request $request): array` wraps it with `decodeWebhookPayload(Request $request): array`, which resolves the practical problem that `$request->all()` silently mangles a raw-JSON POST body when the request lacks a JSON `Content-Type` header: PHP's own form-urlencoded parser corrupts field **names** containing `&`, `+`, or `%`, and separately replaces `.` and spaces in POST field names with `_` — so a JSON body like `{"amount":1.5,...}` arrives with its sole field name mangled to `..._1_5...`, defeating any attempt to `json_decode()` by field name. `decodeWebhookPayload()`'s fallback order is: (1) `$request->all()` if it already contains `merchantAccount` (normal, correctly content-typed request); (2) `json_decode($request->getContent(), true)` — the actual fix, since `getContent()` returns the raw, unmangled body; (3) `json_decode(array_key_first($data), true)`, guarded by `is_string($key)`, as a last-resort fallback for the cases where `getContent()` is unavailable or empty; (4) the original `$data` as-is, which then fails the existing `validateWebhookRequiredFields()` and surfaces as the existing 400 response — no new 200-on-error path is introduced.
- `WebhookController` now calls `handleWebhookRequest($request)` instead of `handleWebhook($request->all())`. Its existing `catch` blocks (403 for `SignatureMismatchException`, 400 for `WayForPayException`) are unchanged.

## Consequences

### Positive

- `verify()`/`getVerifyFormData()` follow the exact form-builder/renderer pattern already established for `purchase()`/`hold()`, so the new pair requires no new mental model from consumers of this package.
- `SignatureGenerator` is untouched — confirmed by reading `generateForVerify()`'s five named-key indexing, and by an empty `git diff --stat src/Services/SignatureGenerator.php`.
- Webhook payloads with decimal amounts (`"amount":1.5` and similar) are now correctly decoded regardless of the request's `Content-Type` header, closing a real production failure mode.
- Currency validation has a single source of truth (`ValidatesCurrency`), removing the risk of the whitelist silently diverging between `Transaction` and the new VERIFY form-builder.

### Negative / BC-break (3.1.0)

- `WayForPayInterface` changes: `verifyCard()` is removed; `getVerifyFormData()`, `verify()`, and `handleWebhookRequest()` are added. Any third-party class implementing `WayForPayInterface` directly (none exist inside this repository — confirmed by grep) must be updated. Documented in `UPGRADE.md`.
- Calling `WayForPayService::verifyCard()` directly now always throws `LogicException` instead of silently returning `null`/failing at the HTTP layer. This is a deliberate behavior change: the method never worked, so this is a fail-fast improvement, not a regression, but it is still a breaking change in the strict sense that the method's return type changed from `string` to `never`.
- `HandlesApiResponse::parseResponse()`'s `returnKey`-not-found branch now attaches `reasonCode` and the full response body (`responseData`) to the thrown `WayForPayException`, where it previously attached neither. This is shared by every caller of `parseResponse(..., returnKey: ...)` (currently only the VERIFY/token-lookup paths), per the Alternative 2 discussion above — the known `recToken`-in-`responseData` exposure (`docs/plans/token-based-charging/04-security.md`, Medium #1, not fixed) is not closed by this ADR and remains for a future decision.
- `Illuminate\Http\Request` becomes a dependency of `WayForPayInterface`'s type signature. Not a new package dependency in practice, since `illuminate/http` is already required by this Laravel-only package.
