# Ecommerce: Multi-Tender Payments API

> This optional API presentation package exposes approved HTTP operations for the Multi-Tender Payments domain module. It presents exactly one independent module, delegates all authoritative behavior to that module's public actions/queries/policies, and contains no other module's API logic.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-multi-tender-payments-api?sort=semver)](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/actions/workflows/tests.yml)

## What this package is

Five HTTP operations over
[`liberusoftware/ecommerce-multi-tender-payments`](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments),
which is the authority on every rule below. This package decides nothing about
money. It decides status codes.

> **Multi-Tender Payments owns the plan and the arithmetic. It never moves money
> and it never holds a balance.**

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- **No route accepts a total, a balance or a capacity** — in a body, a query or
  a header. Every one is resolved server-side through a contract the domain
  publishes, and `tests/Feature/InputWalkTest.php` walks all five routes proving
  it.
- Idempotency required on every state-changing operation, with the domain's two
  exception classes rendered as **409** (permanent) and **423 + `Retry-After`**
  (transient), told apart by `instanceof` and never by decoding a message.
- An unbound resolver is **503**; a null total for an order that exists is
  **422**. Two different failures, and they never collapse into one.
- Money is the settled envelope `{"minor","currency","exponent","decimal"}` with
  `decimal` a **string**. Nothing here emits a floating-point money value.
- An OpenAPI 3.1 fragment with stable operation ids, kept in parity with the
  router in **both** directions by a test.
- Imports exactly one module. The boundary suite asserts the absence of
  `ecommerce-payment-operations`, `ecommerce-gift-cards-and-store-credit`,
  `ecommerce-refunds` and `ecommerce-orders` by name.

## The operations

| Operation | Method and path | Scope | `Idempotency-Key` |
| --- | --- | --- | --- |
| `getPaymentPlan` | `GET /plans/{order}` | read | — |
| `listPlanTenders` | `GET /plans/{order}/tenders` | read | — |
| `admitTenderPlan` | `POST /plans/{order}/admissions` | write | — (stores nothing) |
| `recordTender` | `POST /plans/{order}/tenders` | write | required |
| `reverseTender` | `POST /plans/{order}/tenders/{tender}/reversals` | write | required |

Default prefix `/api/v1/ecommerce/multi-tender-payments`.

## What a caller may say, and what it may not

A caller declares what it is **offering**: a tender kind, and either an amount
in minor units or a relative share. That is the offer, and the module measures
it against figures the caller never supplies.

Three figures are never accepted, anywhere:

| Figure | Where it comes from instead |
| --- | --- |
| the payable total | `ResolvesPayableTotal`, bound by the host, resolved per request |
| the outstanding balance | a fold over the append-only ledger, computed on every read |
| a tender's capacity | `ResolvesTenderCapacity`, bound by the host, keyed by tender kind |

A **currency** is not accepted either. It has exactly one origin — the resolved
payable total — so every amount a caller sends is denominated by the server. A
mixed-currency plan is therefore unreachable from a request body; the only way
one can arise is a host capacity resolver answering in another currency, and
that is refused with `mixed_currency_plan` rather than converted.

Accepting a money figure in a body is a hole of exactly the same shape as
accepting a tenant id. The payable total is the worst of them, because it is the
figure every other number in the module is measured against.

## The status codes this package renders

| Refusal | Status | What a caller should do |
| --- | --- | --- |
| resolver unbound | `503` | nothing; the deployment is half-configured |
| `PayableTotalUnknown` | `422` | check the order reference |
| `TenderIdempotencyConflict` | `409` | give up — **do not** mint a fresh key |
| `TenderClaimInFlight` | `423` | retry the identical request after `Retry-After` |
| `OverAllocatedPlan` | `422` | rebuild the plan; it is never clamped |
| `MixedCurrencyPlan` | `422` | fix the host's capacity resolver |
| `CannotAllocate` | `422` | the arithmetic has no exact answer |
| `CannotReverseTender` | `422` | not captured, already reversed, or no reason |
| `TenderLedgerIsAppendOnly` | `409` | the ledger cannot be edited, ever |

The mapping lives in the base controller's `callAction()`, **not** in middleware.
`Illuminate\Routing\Pipeline` catches a route's throwable and renders it through
the application handler before the surrounding middleware resumes, so a
middleware-based mapper never sees the exception at all.

## What this replaces

The host application at `liberu-ecommerce/ecommerce-laravel` has **no**
multi-tender payment surface of any kind — the nine schema faults behind that,
from `orders.payment_method` being a single nullable string to deposits and
instalments not existing at all, are named and replaced by the domain module and
are listed in [its README](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments#what-this-replaces).

What this package replaces is the transport habit those faults produced: an
endpoint that takes a total, a status and an amount from whoever is calling it.
Every one of those is refused here, and every refusal has a test.

## Idempotency

Mint the key **once, when the step is entered**, and send the same value on
every retry. A key minted at click time defeats the mechanism entirely.

On a `409`, do **not** mint a fresh key. In this domain a conflict means a
tender already exists under that key with different facts, so a fresh key would
record a **second** tender for money that moved once. That is the opposite of
the Checkout answer, where nothing was committed under the key and a fresh one
is safe — which is why the rule is stated here rather than assumed from
elsewhere.

Reversal is idempotent through the ledger itself: a tender can be reversed
exactly once. Replaying the identical reversal returns the entry that already
exists (`200`); a second reversal under a *different* reason is a permanent
conflict (`409`). The header is required all the same, so that every
state-changing operation in this package has one contract.

## What this package deliberately does not do

- **No business authorisation.** Authorisation belongs to the domain's policies;
  at `0.1.0` the domain publishes none, so this package delegates to none and —
  more to the point — invents none. Scopes are token capabilities, checked with
  `tokenCan()`, and an actor that cannot answer `tokenCan()` is refused rather
  than admitted.
- **No authentication.** The host puts its guard in the `middleware.all` group.
  This package requires no token package and names no guard.
- **No refunds.** A reversal here is a ledger entry in the domain module.
  Deciding money is owed back to a customer is `ecommerce-refunds`, and nothing
  here creates anything there.
- **No instalment schedule.** An instalment reference is a string. Nothing here
  knows what is due when.
- **No tenant id in a body, ever.** Derive it from the actor.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

Neither this package nor the domain module is on Packagist, so the host adds two
VCS repository entries first — Composer honours `repositories` only from the
root manifest.

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-multi-tender-payments" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api" }
]
```

```bash
composer require liberusoftware/ecommerce-multi-tender-payments-api
```

Installing boots nothing: there is no `extra.laravel.providers`. Enable it by
name, and bind the two contracts — until the host does, this API answers `503`.

```dotenv
MODULES_ENABLED="…,ecommerce-multi-tender-payments,ecommerce-multi-tender-payments-api"
```

```php
$this->app->bind(ResolvesPayableTotal::class, OrderPayableTotal::class);
$this->app->bind(ResolvesTenderCapacity::class, TenderCapacityRouter::class);
```

```http
POST /api/v1/ecommerce/multi-tender-payments/plans/order-9f2c/tenders
Idempotency-Key: 5b0f2f4e-0e0f-4d5c-9a7a-1b2c3d4e5f60

{"tenders":[{"kind":"gift_card","amount_minor":10000,"reference":"gc_7734"},
            {"kind":"card","amount_minor":6000}],
 "position":0}
```

The gift card is worth 4000, so it is partly spent and the remainder becomes the
outstanding balance. Nothing here asked what the card was worth.

## Documentation

- [docs/domain.md](docs/domain.md) — the operations, the seams, the exception mapping
- [docs/adoption.md](docs/adoption.md) — installing, binding, routing, publishing the fragment
- [docs/runbook.md](docs/runbook.md) — what each status means and what to do about it
- [resources/openapi/multi-tender-payments-api.json](resources/openapi/multi-tender-payments-api.json) — the OpenAPI 3.1 fragment
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-multi-tender-payments-api" alt="Contributors to liberusoftware/module-ecommerce-multi-tender-payments-api">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api/graphs/contributors).
