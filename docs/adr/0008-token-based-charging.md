# ADR-0008: Token-Based Charging via `recToken` (Merchant-Initiated)

## Status

Accepted

## Context

Before this change, `charge()`/`holdCharge()` both required a non-nullable `Domain\Card`, so a CHARGE request could only be built from raw PAN/CVV/expiry. There was no way to charge a previously-saved card via WayForPay's `recToken` mechanism, without the cardholder present — a true merchant-initiated, server-side charge, as opposed to the existing `regularMode` subscription flow (README §4), which only lets WayForPay itself trigger recurring debits on WayForPay's own schedule.

The stakeholder-clarified business need (resolving BA conflict C-1) is specifically: create a **HOLD (AUTH)** on a customer's saved card at a scheduled moment the consuming application controls — the concrete scenario is placing a hold 24 hours before a booked event, using a token captured earlier when the booking was made, with no cardholder present at hold time. This makes `holdChargeWithToken()` the primary business driver of this feature, not a secondary/conditional method; `chargeWithToken()` (SALE) remains needed as the underlying building block (the same `sendCharge()`, widened to a `Card|CardToken` union type).

### Research: does WayForPay support `merchantTransactionType=AUTH` combined with `recToken`?

WayForPay's public documentation does not contain a direct, literal statement that this specific combination is supported. However, three documented facts, read together, imply support:

1. On the Charge (host2host) page, `merchantTransactionType` is a **required** field of any CHARGE request, with values SALE ("withdraw funds from card") or AUTH ("blocking money on the payment card"). Source: <https://wiki.wayforpay.com/en/view/852194>
2. On the same page, in the same parameter table as `merchantTransactionType` and `holdTimeout`, `recToken` is documented as "Card token for recarring withdrawals, without client (without reference to card details)", with the note: "fields (card+expMonth+expYear+cardCvv+cardHolder) or recToken should be obligatory." This places `recToken` as an alternative to the card fields within the *same* CHARGE request type, not as a separate operation. Source: <https://wiki.wayforpay.com/en/view/852194>
3. The Tokenization page describes token-based withdrawal as "performance of operation of withdrawal/**blocking** of the assets on card without participation of the client through the transfer of Token from the merchant." "Blocking" corresponds to AUTH per fact 1. Source: <https://wiki.wayforpay.com/en/view/852175>

**This is an inference from the CHARGE parameter table, not a direct quote confirming the AUTH+recToken combination specifically.** No sandbox/test-merchant run against a real WayForPay account was performed in this pipeline (no credentials available in this environment). Given the criticality of the business need to `holdChargeWithToken()`, this ADR consciously accepts Variant A (ship it as a working method) rather than Variant B (throw immediately), on the strength of the above indirect but internally consistent evidence. Consumers are advised — via a matching README warning — to verify this combination against a WayForPay sandbox/test merchant account before relying on it in production.

`SignatureGenerator::generateForCharge()` already conditionally appends card fields only `if (isset($data['card']))`. A token-based payload never sets `data['card']`, so that branch simply does not fire — no `SignatureGenerator` changes are needed, verified by unit tests (AC-6, AC-7) and by an empty `git diff --stat src/Services/SignatureGenerator.php`.

## Alternatives Considered

### 1. Union type `Card|CardToken` on `sendCharge()` vs. a `ChargePayable` interface

- **Pros of an interface:** more open/extensible; a documented contract (`toArray(): array`) rather than an implicit one.
- **Cons:** it would require retroactively adding `implements ChargePayable` to `Domain\Card`, expanding public API surface for a single internal use site; it would open the private `sendCharge()` payload contract to arbitrary third-party implementations, which is undesirable for a payment payload; and the WayForPay documentation itself presents CHARGE's payable as a closed choice ("card fields **or** recToken"), so an open abstraction is not justified by the domain.
- **Decision:** union type `Card|CardToken`, matching this package's existing pattern of thin public wrappers over one shared private method (`getPurchaseFormData()`/`getHoldFormData()` → `buildPurchaseFormData(..., bool $isHold)`; now `charge()`/`holdCharge()`/`chargeWithToken()`/`holdChargeWithToken()` → `sendCharge(Transaction, Card|CardToken $payable, bool $isHold, ?string $serviceUrl)`).

### 2. `holdChargeWithToken()` ships as a working method (Variant A) vs. throws immediately (Variant B)

- **Pros of Variant B (throw):** safer default — never silently attempts an unconfirmed API combination; zero risk of a silent downgrade to SALE.
- **Cons of Variant B:** it directly blocks the head business case identified by the stakeholder clarification (T-24h AUTH hold on a saved card). Shipping the feature without a working AUTH+token path would make the primary scenario undeliverable.
- **Decision:** Variant A — `holdChargeWithToken()` ships as a working method, with the "inference, not direct quote" disclaimer carried into this Context section, this Decision, and the README warning block. No silent downgrade to SALE exists in either variant; there is no code path that substitutes AUTH with SALE.

### 3. Nullable `?CardToken` parameter added to the existing `charge()`/`holdCharge()` instead of new methods

- **Pros:** no new public methods.
- **Cons:** WayForPay's CHARGE contract requires exactly one of "card fields" or `recToken`; a nullable-parameter signature (`charge(Transaction, ?Card, ?CardToken)`) would allow both-null and both-set call sites to compile, deferring a validation error to runtime instead of the type system, and would force every existing caller of `charge()`/`holdCharge()` to reason about a parameter they never use. Rejected in favor of dedicated methods, mirroring the precedent set for holds in ADR-0007 (dedicated `hold()`/`holdCharge()` instead of parameterizing `purchase()`/`charge()`).

## Decision

- New readonly DTO `Domain\CardToken` wraps a single `string $recToken`, validated fail-fast in the constructor (ADR-0004 style): non-empty after trim, max 255 characters, permissive character class (`/^[A-Za-z0-9._:\-]+$/`) — deliberately not a strict UUID pattern, since WayForPay does not publish a formal token grammar and an overly strict pattern risks rejecting legitimate tokens. `toArray()` returns exactly `['recToken' => $this->recToken]`. `__debugInfo()` masks the token (first 6 + last 4 characters, matching `Card::__debugInfo()`'s convention), with an explicit degrade-to-full-mask branch for tokens of length ≤ 10 to avoid a negative-length `str_repeat()` and avoid ever exposing a short token in full.
- `WayForPayInterface` and `WayForPayService` gain two new methods: `chargeWithToken(Transaction, CardToken, ?string $serviceUrl = null): array` (SALE) and `holdChargeWithToken(Transaction, CardToken, ?string $serviceUrl = null): array` (AUTH). Both delegate to the existing private `sendCharge()`, whose second parameter is widened from `Card` to `Card|CardToken`; the method body is otherwise unchanged — `$payable->toArray()` works identically for both types, and the field-merge order (payable → signature → client → serviceUrl) is preserved exactly as in ADR-0007, since the signature must be computed after the payable fields are merged in and before the client fields are merged in.
- The `holdTimeout` guard and injection point (`prepareTransactionData(Transaction, bool $isHold)`, the P-1 invariant from ADR-0007) needed **no new code**: because both new methods delegate through `sendCharge()` → `prepareTransactionData()`, `chargeWithToken()` automatically rejects a `Transaction` carrying `holdTimeout` and `holdChargeWithToken()` automatically injects it (from the transaction or from `wayforpay.default_hold_timeout`), with zero duplication.
- `HOLD_TIMEOUT_NOT_ALLOWED_MESSAGE` gained a suffix pointing callers to `holdChargeWithToken()`, keeping the original text as an exact prefix so the four existing substring assertions in `tests/Feature/HoldTest.php` remain valid unmodified.
- `SignatureGenerator` is **not modified**. `recToken` is never a signed field for the CHARGE operation (confirmed against the WayForPay parameter table, wiki 852194, the same way ADR-0007 verified `holdTimeout`/`merchantTransactionType` are unsigned); the existing `if (isset($data['card']))` branch in `generateForCharge()` simply does not fire for a token-based payload.
- `clientIpAddress` behavior for cron/queue-triggered token charges is **not changed**. The existing code only sends `clientIpAddress` (with an `?? '127.0.0.1'` fallback) when the caller supplies a `Domain\Client` on the `Transaction`; if no `Client` is supplied, the field is omitted entirely — WayForPay decides how to handle its absence. Consumers charging from a queue/cron context should pass a `Client` carrying the IP address captured when the token was created, if they want a meaningful value sent for fraud scoring.

## Consequences

### Positive

- Merchant-initiated charges/holds no longer require PCI DSS scope on the consuming application's side, since raw PAN never passes through it for the token path — a meaningful advantage over `charge()`/`holdCharge()`, called out in the README.
- Zero duplication: one new DTO, two thin public methods, one widened parameter type on an existing private method.
- `SignatureGenerator` is untouched — verified by an empty `git diff --stat src/Services/SignatureGenerator.php` (AC-14).
- The `holdTimeout` guard/injection invariant from ADR-0007 (single point of truth in `prepareTransactionData()`) is preserved without any new conditional logic in the new methods.

### Negative / risks accepted

- **BC-break (again, soon after ADR-0007's BC-break):** `WayForPayInterface` gains two more required methods. Any external implementation (test doubles, decorators) will fail to compile against it until updated. This is a second BC-break shortly after the 2.0 line; mitigated the same way as before — documented in a new "Upgrading from 2.x to 3.0" README section, and reflected in the semver bump (a git tag `3.0.0`, per this package's tag-based versioning; see the "Versioning" note below).
- **R-1 (documented, unverified): AUTH+recToken support is inferred, not confirmed by direct WayForPay documentation quote.** No sandbox run was performed in this pipeline. Consumers relying on `holdChargeWithToken()` in production should first verify the combination against a WayForPay sandbox/test merchant account, as called out in the README warning block for this section.
- **R-2 (pre-existing, out of scope): `generateForCharge()`'s card-field behavior does not match WayForPay's documented CHARGE signature fields on the card path.** This divergence pre-dates this change and is intentionally not corrected here — doing so would break currently-working card-based charges. It does not affect the token path: a token payload never sets `data['card']`, so it matches the documented signature exactly regardless of this pre-existing card-path divergence.
- `clientIpAddress` is omitted from cron/queue-triggered token charges unless the consumer explicitly attaches a `Domain\Client` with the IP captured at token-creation time; the package does not synthesize a fallback IP for this path.
- The package provides no token-revocation API and does not deduplicate charges; idempotency by `orderReference` and reconciliation via `checkStatus()` after a network timeout remain the consumer's responsibility (unchanged from the package's existing stateless design, ADR-0001).

### Versioning

`composer.json` does not carry a `version` field; this package's existing convention is versioning via git tags (`1.0.0` … `2.0.0`). Consistent with that convention, and consistent with ADR-0007's precedent for the prior interface BC-break, this change is released as git tag `3.0.0`; `composer.json` is intentionally left unchanged.
