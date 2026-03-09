# Architecture Decision Records

This directory contains the Architecture Decision Records (ADRs) for the `aratkruglik/wayforpay-laravel` package.

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-0001](0001-laravel-http-client-over-external-sdk.md) | Use Laravel HTTP Client instead of external SDK | Accepted |
| [ADR-0002](0002-purchase-returns-html-form.md) | purchase() returns HTML form, not a URL | Accepted |
| [ADR-0003](0003-operation-specific-signature-generator.md) | Operation-specific signature methods in dedicated class | Accepted |
| [ADR-0004](0004-constructor-validated-readonly-dtos.md) | Constructor-validated readonly domain objects | Accepted |
| [ADR-0005](0005-event-driven-webhook-processing.md) | Event-driven webhook processing with signed acknowledgment | Accepted |
| [ADR-0006](0006-dual-authentication-modes.md) | Dual authentication modes (HMAC vs merchantPassword) | Accepted |

## ADR Format

Each ADR follows this structure:

- **Title** - Short descriptive name
- **Status** - Proposed, Accepted, Deprecated, or Superseded
- **Context** - Forces at play, including technical, business, and social
- **Alternatives Considered** - Options evaluated before making the decision
- **Decision** - The change being proposed or accepted
- **Consequences** - Resulting context after applying the decision
