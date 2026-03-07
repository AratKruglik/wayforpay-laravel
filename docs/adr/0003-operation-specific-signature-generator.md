# ADR-0003: Operation-Specific Signature Methods in Dedicated Class

## Status

Accepted

## Context

WayForPay uses HMAC-MD5 signatures for request authentication. Each API operation requires a different ordered set of fields to be concatenated and signed. For example:

- **Purchase**: merchantAccount, merchantDomainName, orderReference, orderDate, amount, currency, productName[], productCount[], productPrice[]
- **Refund**: merchantAccount, orderReference, amount, currency
- **Check Status**: merchantAccount, orderReference

Field order is critical -- changing the order produces a different hash and the API rejects the request. Array fields (product data) must be individually concatenated before joining.

## Alternatives Considered

### 1. Single generic method with field-order configuration

- **Pros:** DRY; one method handles all operations.
- **Cons:** Field ordering would need to be passed as a parameter or looked up from a mapping, adding indirection. Array field handling (products) complicates a generic approach. Errors in field ordering are silent and hard to debug.

### 2. Signature logic inline in WayForPayService

- **Pros:** Co-locates signature generation with the API call.
- **Cons:** Mixes HTTP/business logic with cryptographic concerns; harder to test signature generation in isolation; duplicates the HMAC call across methods.

### 3. Dedicated SignatureGenerator with per-operation methods (chosen)

- **Pros:** Each method explicitly documents its field order; easy to test each operation's signature independently; single responsibility; the core `generate()` method handles HMAC-MD5 while per-operation methods handle field ordering.
- **Cons:** More methods to maintain; adding a new API operation requires a new method.

## Decision

Extract signature generation into a dedicated `SignatureGenerator` class with:

- A core `generate(array $params): string` method that concatenates with `;` and applies `hash_hmac('md5', ...)`.
- A `verify(array $params, string $signature): bool` method using `hash_equals`.
- Per-operation methods (`generateForPurchase`, `generateForRefund`, `generateForCheckStatus`, etc.) that extract and order the correct fields before delegating to `generate()`.

```php
class SignatureGenerator
{
    public function generate(array $params): string;
    public function verify(array $params, string $signature): bool;
    public function generateForPurchase(array $data): string;
    public function generateForRefund(array $data): string;
    public function generateForCheckStatus(array $data): string;
    // ... one method per operation
}
```

## Consequences

### Positive

- **Explicit field ordering**: each method is a readable specification of which fields are signed and in what order.
- **Isolated testability**: signature generation can be unit-tested without HTTP mocking.
- **Single responsibility**: `WayForPayService` handles API logic; `SignatureGenerator` handles cryptography.
- **Safe refactoring**: changing one operation's signature fields cannot accidentally break another.

### Negative

- **Method count grows linearly** with the number of API operations (currently 10 methods). This is acceptable given each method is 3-10 lines.
- **Duplication** of similar field lists across methods. This is intentional -- each WayForPay operation genuinely requires its specific field ordering, so abstracting it would obscure the specification.
