# ADR-0005: Event-Driven Webhook Processing with Signed Acknowledgment

## Status

Accepted

## Context

WayForPay sends payment status updates (callbacks) to the merchant's `serviceUrl` via HTTP POST. The merchant must:

1. Validate that the callback is authentic (signature verification).
2. Process the payment status update (update orders, trigger business logic).
3. Respond with a signed acknowledgment so WayForPay knows the callback was received.

If the merchant does not respond correctly, WayForPay will retry the callback. The package needs to provide a webhook handling mechanism that is both secure and flexible enough for any application's business logic.

## Alternatives Considered

### 1. Callback/closure registration

- **Pros:** Simple; the developer registers a closure that receives webhook data.
- **Cons:** Only one handler per registration; no built-in async processing; doesn't leverage Laravel's event system; harder to test.

### 2. Abstract handler class to extend

- **Pros:** Explicit contract; type safety.
- **Cons:** Rigid; only one handler class per application; requires inheritance.

### 3. Laravel Event dispatching (chosen)

- **Pros:** Multiple listeners via `EventServiceProvider`; supports queued listeners for async processing; well-understood Laravel pattern; decouples the package from application logic.
- **Cons:** Slightly more indirect than a direct callback; requires event/listener registration.

## Decision

The webhook flow is:

1. `WebhookController` (invokable) receives the POST request and delegates to `WayForPayService::handleWebhook()`.
2. `handleWebhook()` validates required fields (`merchantAccount`, `orderReference`, `transactionStatus`, `merchantSignature`).
3. `handleWebhook()` verifies the HMAC-MD5 signature using `SignatureGenerator::generateForServiceUrl()` and `hash_equals()`.
4. On success, dispatches `WayForPayCallbackReceived` event with the full callback data.
5. Returns a signed acknowledgment response: `{ orderReference, status: "accept", time, signature }`.

```php
// In application's EventServiceProvider or listener
Event::listen(WayForPayCallbackReceived::class, function ($event) {
    // Process $event->data (update order, send notification, etc.)
});
```

The controller catches `SignatureMismatchException` (403) and `WayForPayException` (400), returning appropriate error responses.

## Consequences

### Positive

- **Decoupled**: the package handles security (signature verification) and protocol (signed response); application handles business logic via event listeners.
- **Multiple listeners**: different parts of the application can react independently (e.g., update order status, send email, log analytics).
- **Queued processing**: listeners can implement `ShouldQueue` for async handling without blocking the webhook response.
- **Signed response**: WayForPay receives a valid acknowledgment immediately, preventing retries.

### Negative

- **Event discovery**: developers must know to listen for `WayForPayCallbackReceived`. Documented in README.
- **No built-in retry on listener failure**: if a listener throws, the webhook is already acknowledged. Application-level error handling is required.
- **Raw array data**: the event carries a raw array rather than a typed DTO. This keeps the package flexible but pushes data interpretation to the consumer.
