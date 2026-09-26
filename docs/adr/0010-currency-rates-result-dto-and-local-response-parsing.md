# ADR-0010: Currency Rates — Result DTO and Local Response Parsing

## Status

Accepted

## Context

WayForPay's `CURRENCY_RATES` operation (wiki: <https://wiki.wayforpay.com/uk/view/1737715>) has a response shape that is unusual compared to every other operation this package handles:

1. **UPPERCASE keys.** The response carries `REASONCODE`, `REASON`, `RATESDATE`, `RATES` — not the camelCase `reasonCode`/`reason` used everywhere else. The shared `HandlesApiResponse::parseResponse()` only ever inspects `$json['reasonCode']`; fed a `CURRENCY_RATES` response, it would silently treat a genuine failure (e.g. `REASONCODE: 1124`) as success, because the camelCase key it checks is simply absent.
2. **Unknown/absent codes read as success.** `parseResponse()`'s `isset($json['reasonCode'])` guard, combined with `ReasonCode::tryFrom()` returning `null` for a code it doesn't recognize, means any response without a recognized camelCase code — including a `CURRENCY_RATES` response, which never has one — passes through unchecked.
3. **Non-JSON 200 bodies would throw `TypeError`, not `WayForPayException`.** `Response::json()` returns `null` for a non-JSON body; `parseResponse()`'s declared return type is `array|string`, so returning `null` under `strict_types=1` is a `TypeError`, not the exception type consumers of this package already expect to catch.
4. **Mixed int/float values.** The example response mixes `26.45` (float) with `192278` (int, for BTC) in the same `RATES` map. A result type should not force API consumers to defensively check `is_int()`/`is_float()` themselves.
5. `RATESDATE` (the actual date the rates apply to) can differ from the requested `orderDate` — WayForPay returns the nearest available rates, not necessarily rates for the exact date asked.

The brief explicitly ruled out modifying the shared `HandlesApiResponse::parseResponse()` to accommodate this one operation, since that trait is shared by every other `WayForPayService` method and `MmsService`.

## Alternatives Considered

### 1. Extend `parseResponse()` to also check UPPERCASE keys

- **Pros:** one code path for all operations.
- **Cons:** couples an internal helper's contract to one operation's non-standard response casing; every other operation would pay a redundant `isset($json['REASONCODE'])` check on every call. The brief explicitly rules this out.
- **Decision:** rejected.

### 2. Return the raw response array from `getCurrencyRates()`

- **Pros:** simplest possible implementation; no new DTO.
- **Cons:** leaks WayForPay's UPPERCASE keys and mixed int/float typing directly to consumers, which is exactly the inconsistency the brief asked to normalize. Every consumer would have to defensively re-implement the type coercion this package should do once.
- **Decision:** rejected.

### 3. Static factory `CurrencyRates::fromApiResponse()` on the DTO

- **Pros:** keeps parsing logic next to the DTO it produces.
- **Cons:** introduces a new pattern — no existing DTO in `src/Domain/` owns API-response parsing; every DTO in this package (ADR-0004) is constructor-validated from already-normalized values, with response handling living in the service layer. Adding a parsing responsibility to a value object would be an unjustified departure for a single operation.
- **Decision:** rejected.

## Decision

- New readonly result-DTO `Domain\CurrencyRates` (`int $ratesDate`, `array $rates` — `array<string, float>`), constructor-validated only (ADR-0004 style: `ratesDate > 0`, each `rates` key matches `/^[A-Z]{3}$/D`, each value is `float`). This is the package's **first outbound** result DTO; every prior DTO (`Product`, `Client`, `Card`, `CardToken`, `AccountTransfer`) is an inbound request builder.
- `WayForPayService::getCurrencyRates(int $orderDate, ?string $currency = null): CurrencyRates` does **not** call `sendRequest()`/`parseResponse()`. It validates input, builds the signed payload, and parses the response entirely locally via two private helpers (`parseCurrencyRatesResponse()`, `buildCurrencyRates()`), reading both `REASONCODE`/`REASON` (preferred) and `reasonCode`/`reason` (defensive fallback) explicitly.
- Because the DTO is `readonly`, normalization (uppercasing keys, casting `int` rates to `float`, filtering to the requested currency) happens in the service **before** constructing the DTO; the constructor only asserts invariants, never mutates.
- Edge-case table (binding, from the approved plan):

  | Case | Behavior |
  |---|---|
  | `currency` requested but absent from `RATES` | Empty `rates` map, no exception |
  | `RATES` absent or `null` | Empty `rates` map |
  | `RATES` present but not an object/array | `WayForPayException` |
  | `RATESDATE` absent, non-numeric, or `<= 0` | `WayForPayException` (the DTO cannot be constructed without it) |
  | Any `REASONCODE`/`reasonCode` other than `1100`, or absent entirely | `WayForPayException` |
  | 200 response with a non-JSON body | `WayForPayException` (not `TypeError`) |
  | `currency` input in any letter case (e.g. `'usd'`) | Accepted, normalized to uppercase before send and before filtering |
  | `currency` input not exactly 3 letters (including trailing newline) | `InvalidArgumentException`, no HTTP request sent |
- `SignatureGenerator::generateForCurrencyRates()` signs `[merchantAccount, orderDate]` only — `currency` is never part of the signature, matching the wiki's documented signature string.
- The response itself is **not signed** by WayForPay (no `merchantSignature` in the `CURRENCY_RATES` response). Trust in the payload rests on TLS alone; this is documented in the README so consumers are not surprised by the absence of signature verification for this one operation.
- `getCurrencyRates()` is added to `WayForPayInterface`, released as part of **3.2.0**, and documented as a breaking change for any external implementation of the interface in `UPGRADE.md`. No implementation inside this package other than `WayForPayService` exists (`grep "implements WayForPayInterface"` confirms this).

## Consequences

### Positive

- `HandlesApiResponse::parseResponse()` and every operation that depends on it are completely untouched (`git diff --stat src/Services/Concerns/HandlesApiResponse.php` is empty) — zero regression risk to the rest of the package.
- Consumers get a single, uniformly-typed `float` rates map regardless of whether WayForPay serialized a given rate as a JSON int or float.
- Malformed/non-JSON responses fail with the same `WayForPayException` type consumers already catch for every other operation, instead of an unexpected `TypeError`.

### Negative / risks accepted

- **BC-break for external `WayForPayInterface` implementations.** Documented in `UPGRADE.md` under "3.1 → 3.2". No internal implementation is affected.
- **Unsigned response.** WayForPay does not sign the `CURRENCY_RATES` response, so this package cannot verify server authenticity beyond TLS for this one operation — unlike webhook payloads, which are HMAC-verified. Documented in the README.
- **Undocumented error-response format.** WayForPay's wiki page does not specify exactly which fields appear in a non-1100 `CURRENCY_RATES` response. Both UPPERCASE and camelCase reason-code/message keys are read defensively, but this has not been verified against a live sandbox call in this pipeline. Consumers relying on specific error messages should verify against a WayForPay sandbox/test merchant account first.
- Caching of rates (e.g. for repeated historical-date lookups) is explicitly out of scope; left to the consuming application, per the BA's non-functional requirements.
