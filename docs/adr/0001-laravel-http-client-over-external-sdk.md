# ADR-0001: Use Laravel HTTP Client Instead of External SDK

## Status

Accepted

## Context

The WayForPay payment gateway provides a PHP SDK (`wayforpay/php-sdk`), but it carries its own HTTP layer and does not integrate with Laravel's ecosystem. Our package targets Laravel 11.x/12.x applications and needs to make HTTP requests to the WayForPay API.

Key considerations:

- Laravel ships with `Illuminate\Http\Client\Factory`, a fluent wrapper around Guzzle with built-in testing support via `Http::fake()`.
- The official WayForPay PHP SDK bundles its own HTTP transport, adding a redundant Guzzle dependency and bypassing Laravel's HTTP pipeline (middleware, logging, retries).
- Laravel developers expect packages to participate in the framework's service container and testing conventions.

## Alternatives Considered

### 1. Use the official `wayforpay/php-sdk`

- **Pros:** Maintained by WayForPay; covers all API operations out of the box.
- **Cons:** Brings its own Guzzle instance; no `Http::fake()` support; cannot leverage Laravel middleware/retry; adds an external dependency; version conflicts possible.

### 2. Use raw Guzzle directly

- **Pros:** Full control over HTTP layer.
- **Cons:** Loses Laravel's testing helpers (`Http::fake()`, `Http::assertSent()`); requires manual JSON handling; doesn't benefit from Laravel's timeout/retry configuration.

### 3. Use Laravel HTTP Client (chosen)

- **Pros:** Zero additional dependencies; native `Http::fake()` for testing; fluent API; respects Laravel's service container and configuration; automatic JSON encoding/decoding.
- **Cons:** Couples the package to Laravel (acceptable since this is a Laravel-specific package).

## Decision

Use `Illuminate\Http\Client\Factory` (injected via constructor) for all HTTP communication with the WayForPay API. The HTTP factory is resolved from the service container, allowing full testability via `Http::fake()`.

```php
public function __construct(
    private readonly SignatureGenerator $signatureGenerator,
    private readonly HttpFactory $http
) {}
```

All API calls use `$this->http->asJson()->timeout($this->timeout)->post(...)`, keeping the HTTP layer consistent and testable.

## Consequences

### Positive

- **Zero external dependencies** beyond Laravel's own packages (`illuminate/support`, `illuminate/http`).
- **First-class testing**: all HTTP calls can be faked with `Http::fake()` in tests, matching Laravel conventions.
- **Familiar API**: Laravel developers immediately understand the HTTP layer.
- **Middleware compatibility**: requests pass through Laravel's HTTP client middleware pipeline.

### Negative

- **Laravel-only**: this package cannot be used outside Laravel. This is acceptable given the project's explicit scope.
- **Manual API coverage**: every WayForPay API operation must be implemented manually rather than delegating to an SDK. This gives full control but requires maintenance when the API changes.
