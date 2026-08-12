# Adoption

## 1. Install

Neither this package nor the domain module it presents is on Packagist, and
Composer honours `repositories` **only from the root manifest** — so the host
must declare both itself. This package carries the domain module's entry for its
own CI, where it is root; that does nothing for a consumer.

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-multi-tender-payments" },
  { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-multi-tender-payments-api" }
]
```

```bash
composer require liberusoftware/ecommerce-multi-tender-payments-api
```

Installing boots nothing. The package declares no `extra.laravel.providers`; the
host's `ModuleManagerServiceProvider` globs `config('modules.paths')` for
`*/module.json` and registers only modules named in `MODULES_ENABLED`. Enable
both — the domain module is what actually does the work.

```dotenv
MODULES_ENABLED="…,ecommerce-multi-tender-payments,ecommerce-multi-tender-payments-api"
```

## 2. Bind the two contracts — this is not optional

Until the host binds both, every operation here answers `503`. There is no
default binding and there will never be one: a default would mean a
half-configured deployment quietly treating an order total as zero, or a gift
card as bottomless.

```php
// A service provider in the host.
$this->app->bind(ResolvesPayableTotal::class, OrderPayableTotal::class);
$this->app->bind(ResolvesTenderCapacity::class, TenderCapacityRouter::class);
```

`ResolvesPayableTotal` must resolve the total **server-side**, from the order.
Never from a request — this package will not pass one on even if a caller sends
one, but a host resolver that reads `request()->input('total')` reopens the hole
from the other side.

`ResolvesTenderCapacity` returns `null` for "no ceiling known", which is the
ordinary answer for a card. It does not mean zero. See the domain module's
`docs/adoption.md` for the usual routing per kind.

## 3. Configuration

```bash
php artisan vendor:publish --tag=multi-tender-payments-api-config
```

```php
return [
    'prefix' => 'api/v1/ecommerce/multi-tender-payments',
    'domain' => null,

    // Three groups. Each defaults to [] and each is opted into separately.
    // Never null — a null registers routes with no middleware at all and reads
    // exactly like an empty array right up until a host loses its guard.
    'middleware' => [
        'all' => ['auth:sanctum', 'throttle:api'],
        'read' => [],
        'write' => [],
    ],

    'scopes' => [
        'read' => 'ecommerce:multi-tender-payments:read',
        'write' => 'ecommerce:multi-tender-payments:write',
    ],

    'pagination' => ['per_page' => 25, 'max_per_page' => 100],
];
```

**This package ships no authentication.** It names no guard and requires no
token package. Put the guard in `middleware.all`; the scope check is separate,
is always applied, and is what makes an unauthenticated request a `401`.

**The actor must answer `tokenCan(string): bool`.** Sanctum's `HasApiTokens`
supplies it. Any other token stack that exposes the same method satisfies the
middleware. An actor that does not is refused — a missing method is not a pass.

## 4. The OpenAPI fragment

```bash
php artisan vendor:publish --tag=multi-tender-payments-api-openapi
```

It lands at `resources/openapi/multi-tender-payments-api.json`. It is a
**fragment**: paths are relative and the prefix lives in `servers[0].url`, so a
host merges it under whatever prefix it configured. If the host changes
`prefix`, change `servers[0].url` in its published copy to match — this
package's own test asserts the two agree for the shipped default.

Operation ids are stable across releases. Treat a change to one as breaking.

## 5. Calling it

```http
POST /api/v1/ecommerce/multi-tender-payments/plans/order-9f2c/admissions
Authorization: Bearer …

{"tenders":[{"kind":"gift_card","amount_minor":10000,"reference":"gc_7734"},
            {"kind":"card","amount_minor":6000}]}
```

Admission stores nothing. It answers what each tender would contribute, given
the total and the capacities the host resolved. Then record each tender as its
institution answers, one at a time — there is no transaction across gateways and
nothing here pretends otherwise:

```http
POST /api/v1/ecommerce/multi-tender-payments/plans/order-9f2c/tenders
Idempotency-Key: 5b0f2f4e-0e0f-4d5c-9a7a-1b2c3d4e5f60

{"tenders":[…same plan…],"position":0,"external_reference":"gc_redemption_88121"}
```

Mint the key once, when the step is entered, and send the same value on every
retry. A key minted at click time defeats the mechanism entirely.

Handle the two idempotency outcomes by **status**, never by message:

| Status | Do |
| --- | --- |
| `409` | stop. A tender already exists under that key with different facts. Minting a fresh key would record a second tender for money that moved once. |
| `423` | wait `Retry-After` seconds and send the identical request with the **same** key. |

## 6. Migrations

This package ships none. Both tables belong to the domain module and both are
invented by it; no host table is adopted. See the domain module's
`docs/adoption.md` for the migration path off `orders.payment_method`,
`orders.transaction_id`, the two `payment_status` columns and
`orders.total_amount`.

## 7. What this package will not do for you

- It will not authenticate anybody, and it will not authorise anything beyond a
  token scope.
- It will not accept a total, a balance, a capacity or a currency from a caller,
  and configuring it to is not possible.
- It will not move money, redeem a gift card, create a refund or run an
  instalment schedule. Those belong to `ecommerce-payment-operations`,
  `ecommerce-gift-cards-and-store-credit`, `ecommerce-refunds` and nobody
  respectively — and this package imports none of them.
