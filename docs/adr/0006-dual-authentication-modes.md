# ADR-0006: Dual Authentication Modes (HMAC vs merchantPassword)

## Status

Accepted

## Context

WayForPay's API uses two distinct authentication mechanisms depending on the operation:

1. **HMAC-MD5 signature** (`merchantSignature`): Used by most API operations (purchase, charge, refund, settle, check status, invoices, p2p credit, verify). The merchant signs specific fields with their secret key and includes the signature in the request.

2. **Plain secret key as password** (`merchantPassword`): Used exclusively by the Regular (recurring subscription) API (`/regularApi` endpoint). Instead of computing a signature, the secret key is sent directly as `merchantPassword`.

This duality is dictated by the WayForPay API specification -- the package must support both modes.

## Alternatives Considered

### 1. Unify into a single authentication strategy

- **Pros:** Simpler internal API; one code path.
- **Cons:** Impossible -- WayForPay's Regular API does not accept HMAC signatures; it requires `merchantPassword`. The two endpoints have fundamentally different authentication protocols.

### 2. Separate service classes per authentication mode

- **Pros:** Clean separation; each class handles one auth mode.
- **Cons:** Splits related functionality across classes; recurring operations are conceptually part of the same payment service; more complex DI setup.

### 3. Single service with internal branching (chosen)

- **Pros:** Unified API surface; the developer uses one service for all operations; authentication mode is an implementation detail.
- **Cons:** The service internally handles two different auth patterns.

## Decision

`WayForPayService` handles both authentication modes internally:

- **HMAC operations** use `SignatureGenerator::generateFor*()` to compute signatures, then send to `https://api.wayforpay.com/api`.
- **Regular API operations** (`suspendRecurring`, `resumeRecurring`, `removeRecurring`) use a private `sendRegularRequest()` method that includes `merchantPassword` (the secret key) directly in the payload, then sends to `https://api.wayforpay.com/regularApi`.

```php
// HMAC-authenticated operation
$data['merchantSignature'] = $this->signatureGenerator->generateForRefund($data);
return $this->sendRequest($data); // -> api.wayforpay.com/api

// Password-authenticated operation (Regular API)
$data = [
    'merchantPassword' => $this->secretKey,
    // ...
];
$response = $this->http->post('https://api.wayforpay.com/regularApi', $data);
```

## Consequences

### Positive

- **Unified API surface**: consumers use one service instance for all operations without needing to know about authentication internals.
- **Correct protocol compliance**: each endpoint receives the authentication it expects.
- **Secret key reuse**: the same `secret_key` config value serves as both the HMAC key and the `merchantPassword`, matching WayForPay's design.

### Negative

- **Two code paths**: the service has `sendRequest()` (for HMAC) and `sendRegularRequest()` (for password auth), creating two slightly different flows.
- **Security consideration**: `sendRegularRequest()` transmits the secret key in plaintext within the JSON payload. This is WayForPay's required protocol and is mitigated by HTTPS transport encryption.
- **Tight coupling to WayForPay's API design**: if WayForPay changes their Regular API to use HMAC, the `sendRegularRequest()` method must be updated.
