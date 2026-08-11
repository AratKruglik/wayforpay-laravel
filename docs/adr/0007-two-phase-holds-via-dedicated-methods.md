# ADR-0007: Two-Phase Holds via Dedicated hold*() Methods

## Status

Accepted

## Context

WayForPay supports two-phase payments: an AUTH step freezes funds on the customer's card (a "hold") without capturing them, followed by a SETTLE step that captures some or all of the frozen amount, or a REFUND that releases the hold. Before this change, the package could already finalize a hold (`settle()` existed), but could not create one: `charge()` hardcoded `merchantTransactionType=SALE`, and the widget path (`purchase()`/`getPurchaseFormData()`) never sent `merchantTransactionType` at all.

Both `merchantTransactionType` and the new `holdTimeout` field are additive: neither participates in any `SignatureGenerator::generateFor*` field list (verified by reading `generateForPurchase()`, `generateForCharge()`, and `generateForSettle()`), so adding them does not require touching `SignatureGenerator`.

`prepareTransactionData()` is the single method shared by `purchase()`, `getPurchaseFormData()`, `createInvoice()`, and `charge()`. Any field added there is visible to all four call sites, which raises the question of how a hold-only field (`holdTimeout`) and a hold-only marker (`merchantTransactionType=AUTH`) should be introduced without leaking into non-hold operations.

## Alternatives Considered

### 1. Enum parameter on existing `purchase()`/`charge()`

- **Pros:** No new public methods; one call site per operation.
- **Cons:** Every caller of `purchase()`/`charge()` would need to pass and reason about a mode parameter even when never creating a hold. It would also make the guard against stray `holdTimeout` values harder to reason about, since the same method signature would serve two semantically different operations. Rejected per stakeholder decision: dedicated methods make the hold-creation intent explicit at the call site and keep `purchase()`/`charge()` signatures unchanged.

### 2. Register 5100/5105/1131 as plain `ReasonCode` cases without `isPending()`

- **Pros:** Fewer moving parts — just add the cases.
- **Cons:** `HandlesApiResponse::parseResponse()` currently treats any reason code that isn't in the enum as implicitly non-error (the `if ($code && ...)` guard is false for `tryFrom()` returning `null`). Simply registering the new codes without a corresponding predicate would flip this existing pass-through into a hard `WayForPayException` for these three non-terminal, legitimately in-flight codes — a behavioral regression for exactly the states a hold consumer needs to observe. `isPending()` plus the `!$code->isPending()` guard preserves the existing pass-through behavior explicitly and names the intent.

### 3. Guard reads `resolveHoldTimeout()` result instead of the raw `Transaction::$holdTimeout` field

- **Pros:** Looks like a simplification — one method to consult.
- **Cons:** `resolveHoldTimeout()` also falls back to `wayforpay.default_hold_timeout`. If the guard consulted the resolved value, setting `WAYFORPAY_HOLD_TIMEOUT` in `.env` would make the resolved value non-null for every transaction, and the guard would then reject every `purchase()`/`charge()`/`createInvoice()` call in the application — a global outage triggered by an unrelated config change. Rejected; the guard must read the raw, per-transaction field.

## Decision

- Add four new public methods — `hold()`, `getHoldFormData()`, `holdCharge()`, `cancelHold()` — instead of parameterizing `purchase()`/`charge()`. `cancelHold()` is a thin alias for `refund()`, since cancelling a hold is the same `REFUND` operation.
- `prepareTransactionData(Transaction $transaction, bool $isHold = false)` gains an `$isHold` flag that is the single point where `holdTimeout` is both injected (when `true`) and guarded against (when `false`):
  ```php
  if ($isHold) {
      $optionalFields['holdTimeout'] = $this->resolveHoldTimeout($transaction);
  } elseif ($transaction->holdTimeout !== null) {
      throw new InvalidArgumentException(self::HOLD_TIMEOUT_NOT_ALLOWED_MESSAGE);
  }
  ```
  Because every non-hold caller (`purchase()`, `getPurchaseFormData()` via `buildPurchaseFormData()`, `createInvoice()`, `charge()` via `sendCharge()`) goes through this same method with the default `$isHold = false`, the guard applies uniformly without being duplicated at each call site.
- **The guard reads `$transaction->holdTimeout` directly, never `resolveHoldTimeout($transaction)`.** This is the P-1 invariant: `resolveHoldTimeout()` also consults `wayforpay.default_hold_timeout`, so consulting its *result* in the guard would make a global `.env` default reject every non-hold operation. Reading the raw field means the guard only fires when the caller explicitly set `holdTimeout` on a `Transaction` passed to the wrong method.
- `merchantTransactionType=AUTH` is added in `getHoldFormData()`/`sendCharge()` after signature generation, since it is not a signed field; no new `SignatureGenerator::generateFor*` method is needed.
- `getHoldFormData()` cannot delegate to the public `getPurchaseFormData()` (that would trip the guard), so the widget-path body was extracted into a private `buildPurchaseFormData(Transaction, ?string, ?string, bool $isHold)`, with `getPurchaseFormData()` and `getHoldFormData()` as thin wrappers. Likewise `charge()`/`holdCharge()` share a private `sendCharge(Transaction, Card, bool $isHold, ?string $serviceUrl)`.
- Three new `ReasonCode` cases (`TRANSACTION_IN_PROCESSING` = 1131, `WAIT_3DS_DATA` = 5100, `WAITING_AMOUNT_CONFIRM` = 5105) are added together with `isPending(): bool`, and `HandlesApiResponse::parseResponse()` is updated to `if ($code && !$code->isSuccess() && !$code->isPending())`. Registering cases without `isPending()` would have been a regression (see Alternative 2).
- `settle()` gains an optional 4th argument, `?array $products = null`, mapped to `productName[]`/`productPrice[]`/`productCount[]`. **The package does not validate that `sum(price * count)` equals `amount`, nor that `amount` does not exceed the original AUTH amount.** The package holds no local state about the original hold, so any such validation would either require persisting state (out of scope for a stateless HTTP-client wrapper) or duplicate logic WayForPay already performs server-side. A mismatch is rejected by WayForPay itself via a non-success `reasonCode`, surfaced as `WayForPayException`.

## Consequences

### Positive

- Hold creation and hold finalization are both explicit, discoverable methods (`hold`, `getHoldFormData`, `holdCharge`, `settle`, `cancelHold`), matching this package's existing style of one method per WayForPay operation.
- The guard and the injection point live in exactly one place (`prepareTransactionData()`), so there is no way for a caller to reach an inconsistent state where `holdTimeout` is injected without the guard also being active, or vice versa.
- `SignatureGenerator` remains untouched — verified by an empty `git diff` on that file — because neither `merchantTransactionType` nor `holdTimeout` is a signed field for any operation.

### Negative / risks accepted

- **BC-break**: `WayForPayInterface` now has more required methods. Any external implementation of the interface (test doubles, decorators) will fail to compile against 2.0 until updated. Documented in the README's "Upgrading from 1.x to 2.0" section; mitigated by giving `settle()`'s new argument a default so call-site compatibility is preserved.
- **Documented, unverified risk (R-1)**: the widget path's pre-existing `orderTimeout` override in `getPurchaseFormData()`/`buildPurchaseFormData()` (set to a fixed 49000-second window after `prepareTransactionData()` runs) could in principle interact with a longer `holdTimeout` on the widget path. This pre-existing behavior is intentionally left unchanged (fixing it is out of scope for this feature) and is called out in the README as a documented limitation rather than silently worked around.
- **Documented, unverified risk (R-2)**: the synchronous `transactionStatus` returned immediately after creating a hold is not guaranteed by WayForPay's documentation to be one specific value. The package deliberately does not assert on a single status; `TransactionStatus::isHold()` is a predicate, not a state machine, and the README instructs consumers accordingly.
- **No validation of `settle(products:)` against `amount`**: as noted in the Decision, this is intentional given the package's stateless design; a caller relying on client-side validation here would be relying on non-existent behavior.
