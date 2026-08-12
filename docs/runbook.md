# Runbook

Every symptom here is a status code, because that is all this package produces.
For anything about the arithmetic itself, read the domain module's runbook.

## Symptom: every operation answers 503 `resolver_unbound`

The deployment has not bound `ResolvesPayableTotal`, `ResolvesTenderCapacity`,
or both. This is the module failing the way it is designed to fail; there is no
default binding to fall back on.

```bash
php artisan tinker --execute="var_dump(app()->bound(\
  Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal::class));"
```

Bind them (see `docs/adoption.md` §2). Note that the read operations need only
the total resolver and the write operations need both, so "reads work, writes
503" means exactly one of them is missing.

The message names the contract and nothing else. If you want the container's own
explanation, resolve the contract in tinker — it is deliberately not sent to a
caller, because it names internal classes and paths.

## Symptom: 422 `payable_total_unknown` for one order but not others

Different failure, different cause. The resolver is bound and answered `null`
for that order. Either the order does not exist as far as the host's resolver is
concerned, or the resolver has a gap. This is never an outage — do not restart
anything.

## Symptom: 409 `idempotency_conflict`

The same `Idempotency-Key` arrived with different facts. Permanent: retrying
will get the same answer forever.

**Do not mint a fresh key.** In this domain a conflict means a tender already
exists under that key, so a fresh key records a *second* tender for money that
moved once. Find the entry the key already wrote —
`GET /plans/{order}/tenders` — and decide from there.

If the caller believes the facts are identical, they are not: the hash covers
the plan, the position, the kind, the effect, both amounts and both references.
An `external_reference` that changed between attempts is the usual culprit.

## Symptom: 423 `tender_claim_in_flight` that never clears

Transient by design. The domain holds the claim for ten seconds and releases it
in a `finally`, so a stuck claim means the process holding it died between
acquiring and releasing. It clears on its own within the TTL.

If it does not, the cache store's lock implementation is the suspect — check
what `Illuminate\Contracts\Cache\LockProvider` resolves to. Retry the identical
request with the **same** key; `Retry-After` says how long to wait.

## Symptom: 422 `over_allocated_plan` on a plan an operator believes is correct

The tenders sum to more than the payable total. This module refuses rather than
clamping, because clamping changes a number the caller supplied.

Re-check the payable total the host resolves *now*: recording re-admits the plan
against a fresh total, so a discount applied or a line removed between admitting
and recording surfaces here. The plan must be rebuilt, not trimmed.

## Symptom: 422 `mixed_currency_plan`

A caller cannot cause this — no route accepts a currency. It means the host's
`ResolvesTenderCapacity` answered in a currency or exponent that disagrees with
the payable total for the same order. Fix the resolver; nothing here converts.

## Symptom: 422 `cannot_reverse_tender`

Three cases, and the message says which: the entry is not `captured`, it has
already been reversed, or no reason was given. A declined tender has nothing to
undo.

If the entry is already reversed and the reason is the same, you get `200` and
the existing reversal instead — that is the replay path, not a failure.

## Symptom: 409 `reversal_conflict`

The tender is already reversed under a *different* reason. Permanent, and the
same shape as an idempotency conflict: the facts differ, so retrying will not
help. Read the existing reversal from the ledger before deciding anything.

## Symptom: 422 `idempotency_key_required`

A state-changing operation arrived with no `Idempotency-Key` header, an empty
one, or one longer than 255 characters. The bound is the ledger column's own
width; a longer key would fail as a database error rather than as an answer.

## Symptom: 403 `missing_scope` for a caller that is definitely authenticated

Two causes, and they look identical from outside:

1. the token does not carry the scope this operation needs;
2. the authenticated actor does not expose `tokenCan()` at all.

The second is the one that surprises people. This package refuses an actor that
cannot answer the authorisation question, because an unanswered question is an
exposure. Add `Laravel\Sanctum\HasApiTokens` — or the equivalent for whatever
token stack the host runs — to the actor model.

## Symptom: 500 `scope_not_configured`

`config('multi-tender-payments-api.scopes.read')` or `.write` is empty. The
middleware refuses rather than admitting, because an unnamed scope cannot be
checked. Restore the value or clear the config cache.

## Symptom: 401 on every request, including from a valid token

The host has not put its guard in `config('multi-tender-payments-api.middleware.all')`,
so `$request->user()` is null and the scope middleware answers `401`. This
package names no guard and ships no authentication of its own.

## Symptom: the outstanding balance looks wrong

There is no cached balance in this package or in the domain, so the only inputs
are the ledger and the payable total the host resolves. Read the ledger:

```
GET /api/v1/ecommerce/multi-tender-payments/plans/{order}/tenders?per_page=100
```

Fold it by hand: start at `payable_total`, subtract every `captured` amount, add
back every `reversed` amount, ignore every `declined`. If that disagrees with
`outstanding`, the host's resolver has changed the total since the tenders were
recorded. There is no other input.

## Routine: checking the document matches the routes

`tests/Unit/OpenApiTest.php` asserts it in both directions on every run, so a
route added without an operation — or an operation added without a route — fails
CI rather than shipping.

If a host re-prefixes the routes, its published copy of the fragment must have
`servers[0].url` changed to match. This package's test only pins the shipped
default.

## Routine: releasing this package

`Tests` runs on push and pull request against `main`. `Install` and
`Compatibility` run on a tag matching `[0-9]+.[0-9]+.[0-9]+` only.
`composer.json` `version` and `module.json` `version` must be equal and must be
pushed *before* the tag, or the boundary suite fails on the tag.

The domain module must already be tagged at a version this package's constraint
allows, or the VCS repository entry resolves to nothing and every workflow fails
at `composer update`.
