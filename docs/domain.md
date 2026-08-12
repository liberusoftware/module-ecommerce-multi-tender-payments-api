# Domain

This package presents
[`liberusoftware/ecommerce-multi-tender-payments`](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments)
and owns no rule of its own. Where this document and the domain module's
`docs/domain.md` appear to disagree, the domain module is right.

## What the transport adds

Three things, and nothing else:

1. **A status code for every refusal**, decided by class.
2. **A refusal to be told three figures** — a total, a balance, a capacity —
   and a currency along with them.
3. **An idempotency contract** on every operation that changes anything.

## The operations

| Operation id | Method and path | Domain it calls |
| --- | --- | --- |
| `getPaymentPlan` | `GET /plans/{order}` | `OpenPaymentPlan`, `OutstandingBalance` |
| `listPlanTenders` | `GET /plans/{order}/tenders` | `OpenPaymentPlan`, the plan's `tenders` relation |
| `admitTenderPlan` | `POST /plans/{order}/admissions` | `AdmitTenderPlan` |
| `recordTender` | `POST /plans/{order}/tenders` | `AdmitTenderPlan`, `OpenPaymentPlan`, `RecordTender` |
| `reverseTender` | `POST /plans/{order}/tenders/{tender}/reversals` | `OpenPaymentPlan`, `ReverseTender` |

The operation ids are stable and each one names its route in the OpenAPI
fragment as `x-route-name`. `tests/Unit/OpenApiTest.php` checks that in both
directions: no route without an operation, no operation without a route, and the
same path and method on each side.

## What never crosses the wire inbound

A caller declares an **offer**: a tender kind, and either `amount_minor` or a
relative `share`. It declares no total, no balance, no capacity and no currency.

The currency point is worth stating plainly, because it is what makes the rest
enforceable. `PlannedTender` needs a `Money`, and this package builds every one
with `$payable->withMinor(...)` where `$payable` is the total the host's
`ResolvesPayableTotal` just answered with. A caller sending `10000` is not
sending pence — it is sending a number that the *server* denominates. A
mixed-currency plan therefore cannot be constructed from a request body at all.

`tests/Feature/InputWalkTest.php` proves this two ways. Structurally: the
accepted keys are enumerated in `Payload::rules()` and none of them names a
forbidden figure, and the package's source contains no `->input()`, `->all()`,
`->query()`, `->json()`, `->merge()`, `$_POST` or `$_GET` — the only reader is
the array `validate()` returns, and the only header read anywhere is
`Idempotency-Key`. Behaviourally: every route is driven with every forbidden key
in its body, its query string *and* its headers, and the server's own figures
come back unmoved.

## The two seams, and their two different failures

The domain publishes `ResolvesPayableTotal` and `ResolvesTenderCapacity`, binds
neither, and this package binds neither either.

- **Unbound** is a deployment fault. The base controller checks `bound()` before
  the action and answers `503`. It is a `bound()` check rather than a caught
  container exception so that the 503 is a decision this package made, not a
  message it recognised — and the container's own message, which names internal
  classes, is never shown to a caller.
- **Bound and answering null** for an order is `PayableTotalUnknown`, and it is
  `422`. A fact about one order, not an outage.

Reading a plan needs a payable total to fold against, so both read operations
require `ResolvesPayableTotal`. Admitting and recording ask the host what each
tender can give, so those require `ResolvesTenderCapacity` as well. The
requirement is declared per controller rather than globally, because the two
failure directions are genuinely different.

## Idempotency

`recordTender` passes the caller's key straight to the domain's `RecordTender`,
which owns the whole mechanism: a lock for the claim, a payload hash for the
replay, and two exception classes for the two failures.

| Outcome | Status | Meaning |
| --- | --- | --- |
| appended | `201` | the entry is new |
| replayed | `200` | the same key, the same facts; the existing entry is returned |
| `TenderIdempotencyConflict` | `409` | the same key, different facts — permanent |
| `TenderClaimInFlight` | `423` | the first attempt is still running — transient |

409 and 423 are opposite instructions. They are separated by `instanceof` in
`Problem::for()`, and `tests/Unit/ProblemTest.php` constructs both classes with
an **identical message** and asserts they still render differently — a mapping
that decoded messages could not pass that test.

`reverseTender` is idempotent through the ledger rather than through a key,
because a tender can be reversed exactly once and the reversal already records
what it reverses. An identical replay returns the existing reversal (`200`); a
second reversal under a different reason is the same permanent conflict shape
and gets the same `409`. The header is still required, so every state-changing
operation in this package has one contract rather than two.

## A read opens the plan, and why that is a decision

`OpenPaymentPlan` is the domain's only way to reach a `PaymentPlan`, and it is
`firstOrCreate`. There is no published finder, and this package must not import
a domain model — the `-api` boundary rule exists precisely to stop a transport
coupling itself to somebody else's storage.

So `GET /plans/{order}` materialises the plan row if it is not there. That is
acceptable here for one specific reason: the row carries an order reference, a
currency and a currency exponent, and nothing else. No status, no
`amount_paid`, no cached balance. The response is byte-identical whether the row
existed beforehand, and every figure in it is computed. A deployment that wants
a read path that writes nothing needs a finder published by the domain; that is
a `0.2.0` conversation there, not a workaround here.

The read still fails correctly for an order nobody prices: `OpenPaymentPlan`
resolves the total first, so an unknown order is `422` and nothing is created.

## Authorisation

Two scopes, checked with `tokenCan()`:
`ecommerce:multi-tender-payments:read` and
`ecommerce:multi-tender-payments:write`. They are capabilities of a token.

There is no business authorisation in this package. The domain owns that, and at
`0.1.0` it publishes no policy — so there is nothing here to delegate to, and
inventing one here would put a rule about somebody's money in a transport
package where no other surface would see it.

The one thing this layer does insist on is failing closed: an actor that does
not expose `tokenCan()` is **refused**. Laravel's unanswered gate case is
permissive, and a missing method is how three separate surfaces in this fleet
were left open.

## Money

Integer minor units, and the settled envelope on the way out:

```json
{"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}
```

`decimal` is a string. `19.99` as a JSON number is a double in every client that
reads it, and `(int) (19.99 * 100)` is 1998. The presentation is derived from the
integer by the domain's string arithmetic, never the other way round, and
`tests/Feature/OutputExposureTest.php` walks every response asserting no leaf
value anywhere is a float.

## What is not rendered

`idempotency_key` and `payload_hash` are on every ledger row and appear in no
response. They are the module's record of what it already answered; emitting a
key would let one caller replay another's.
