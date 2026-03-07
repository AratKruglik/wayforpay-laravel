# ADR-0004: Constructor-Validated Readonly Domain Objects

## Status

Accepted

## Context

The package needs to represent payment domain concepts (transactions, products, clients, card details) as data structures passed to service methods. These objects carry data that will be sent to an external payment API, so invalid data must be caught early -- before it reaches WayForPay's servers.

PHP 8.2 introduced `readonly` classes and promoted properties, enabling immutable value objects with minimal boilerplate.

## Alternatives Considered

### 1. Plain arrays

- **Pros:** No classes needed; flexible; familiar PHP pattern.
- **Cons:** No type safety; validation must happen elsewhere; easy to pass wrong keys; no IDE autocompletion; array shape is implicit.

### 2. Laravel Form Requests for validation

- **Pros:** Laravel-native validation; declarative rules.
- **Cons:** Form Requests are tied to HTTP layer; this package needs to validate domain objects that may be constructed programmatically, not just from HTTP input.

### 3. Validated DTOs with setters and builder pattern

- **Pros:** Fluent API; validation can run on `build()`.
- **Cons:** More complex; mutable state between construction and build; more boilerplate.

### 4. Constructor-validated readonly classes (chosen)

- **Pros:** Immutable after construction; validation runs exactly once; PHP 8.2 `readonly` prevents accidental mutation; promoted properties reduce boilerplate; invalid objects cannot exist.
- **Cons:** Constructor parameter lists can be long; no partial construction.

## Decision

Domain objects (`Transaction`, `Product`, `Client`, `Card`) are readonly classes (or classes with readonly properties) that validate all invariants in their constructors. If validation fails, an `InvalidArgumentException` is thrown immediately.

Validation rules enforced at construction time:

| Class | Validations |
|-------|------------|
| `Transaction` | orderReference non-empty and max 64 chars; amount > 0; currency in whitelist (UAH, USD, EUR, PLN, GBP); orderDate > 0; optional fields validated when present |
| `Product` | name non-empty and max 255 chars; price >= 0; count >= 1 |
| `Client` | email format (FILTER_VALIDATE_EMAIL); phone regex; name lengths max 100; country ISO 2-3 letter code |
| `Card` | card number 13-19 digits with Luhn check; expMonth 01-12; expYear 2 digits; CVV 3-4 digits; holder name max 100 chars |

## Consequences

### Positive

- **Invalid objects cannot exist**: if you have a `Transaction` instance, it is guaranteed valid.
- **Fail-fast**: validation errors surface at the point of construction, not when the API call is made.
- **Immutability**: objects cannot be modified after creation, preventing state corruption.
- **Self-documenting**: constructor parameters serve as the complete specification of required and optional fields.

### Negative

- **Long constructor signatures**: `Transaction` has 15 parameters. Mitigated by named arguments in PHP 8.x.
- **No partial construction**: cannot build a Transaction incrementally (except for products via `addProduct()`/`setProducts()`).
- **Strict validation may reject edge cases**: for example, the Luhn check on Card may reject valid test card numbers that don't pass Luhn. This is intentional for production safety.
