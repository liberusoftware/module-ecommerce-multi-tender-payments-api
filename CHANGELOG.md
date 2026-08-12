# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-12

First release. Five HTTP operations over
`liberusoftware/ecommerce-multi-tender-payments`, which remains the authority on
every rule this package renders.

### Added

- `getPaymentPlan` and `listPlanTenders` — a plan with its server-resolved
  payable total and computed outstanding balance, and a page of its append-only
  ledger. No status field, because the domain stores none.
- `admitTenderPlan` — the module's arithmetic, exposed as pure arithmetic: a
  short tender comes back partly spent, an over-allocated plan is refused
  outright, an under-allocated plan is valid and its shortfall is the balance.
  Nothing is stored.
- `recordTender` and `reverseTender` — the two state-changing operations, both
  requiring an `Idempotency-Key`.
- Exception mapping in the base controller's `callAction()`, dispatching on
  class: `TenderIdempotencyConflict` as 409 and `TenderClaimInFlight` as 423
  with `Retry-After`, told apart by `instanceof` and never by a message.
- An unbound published contract as 503, checked with `bound()` before the
  action, and kept distinct from a 422 `PayableTotalUnknown`.
- The input walk: no route accepts a total, a balance, a capacity or a currency
  in its body, its query or its headers, proven structurally and behaviourally
  across all five routes.
- Scope enforcement through `tokenCan()`, failing closed for an actor that
  cannot answer it. No business authorisation in this layer.
- Money rendered as `{minor, currency, exponent, decimal}` with `decimal` a
  string, and an output walk asserting no float and no idempotency bookkeeping
  ever leaves the package.
- An OpenAPI 3.1 fragment with stable operation ids, schemas, examples, scopes,
  errors, pagination and idempotency metadata, in parity with the router both
  ways.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/releases/tag/0.1.0
