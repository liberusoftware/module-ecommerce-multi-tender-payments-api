<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Payload;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * The input walk.
 *
 * Wave 7 walked every field a surface *emitted* and proved nothing sensitive
 * came out. This is the same technique turned around: every route's body **and
 * its headers** are walked, and the three figures this module refuses to be
 * told — a total, a balance, a capacity — are proven absent from all of them.
 *
 * Accepting a money figure in a body is a hole of exactly the same shape as
 * accepting a tenant id: both let a caller decide something the server is the
 * only honest source of. The payable total is worse than most, because it is
 * the figure every other number in the module is measured against.
 *
 * Two halves, and both are needed:
 *
 * - **structural** — the package cannot name a forbidden figure. Its accepted
 *   keys are enumerated, and its source is checked for any way of reading a
 *   request other than the one validated array.
 * - **behavioural** — naming one anyway changes no answer. Every route is
 *   driven with every forbidden key in its body, its query and its headers, and
 *   the server's own figures come back unmoved.
 */

const FORBIDDEN = '/total|balance|capacit|payable|outstanding|currency|exponent/i';

/** @return array<string, mixed> a body carrying every figure this API refuses to be told */
function injectedBody(array $legitimate = []): array
{
    return [
        ...$legitimate,
        'total' => 1,
        'total_minor' => 1,
        'payable_total' => ['minor' => 1, 'currency' => 'XXX', 'exponent' => 2, 'decimal' => '0.01'],
        'payable_total_minor' => 1,
        'balance' => 0,
        'balance_minor' => 0,
        'outstanding' => 0,
        'outstanding_minor' => 0,
        'capacity' => 999_999_999,
        'capacity_minor' => 999_999_999,
        'currency' => 'XXX',
        'exponent' => 5,
        'tenant_id' => 'another-storefront',
    ];
}

/** @return array<string, string> headers carrying the same figures */
function injectedHeaders(array $legitimate = []): array
{
    return [
        ...$legitimate,
        'X-Payable-Total' => '1',
        'X-Total-Minor' => '1',
        'X-Outstanding-Balance' => '0',
        'X-Tender-Capacity' => '999999999',
        'X-Currency' => 'XXX',
        'X-Tenant-Id' => 'another-storefront',
    ];
}

beforeEach(function () {
    actingWithEveryScope();
    bindHost(16_000)->capacity(TenderKind::GiftCard, 'gc_7734', new Money(4_000, 'GBP'));
});

it('declares no accepted key naming a total, a balance or a capacity', function (string $operation) {
    foreach (array_keys(Payload::rules($operation)) as $key) {
        expect($key)->not->toMatch(FORBIDDEN);
    }
})->with(Payload::ALL);

it('enumerates a rule set for every route it registers', function () {
    // A route with no entry in Payload::ALL is a route the structural half of
    // this walk never looks at. The two lists must not drift apart.
    $registered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (str_starts_with($name, Api::CONFIG.'.')) {
            $registered[] = mb_substr($name, mb_strlen(Api::CONFIG) + 1);
        }
    }

    // `plans.show` takes nothing at all, which is why it is the one route with
    // no rules: an operation that reads no input cannot be told anything.
    expect(array_values(array_diff($registered, Payload::ALL)))->toBe(['plans.show']);
});

it('reads a request through the validated payload and through nothing else', function () {
    // `validate()` returns only the keys the rules named, so anything else a
    // caller sends is structurally unreachable. These are the escape hatches
    // that would make that untrue.
    foreach (sourceFiles() as $file) {
        $source = (string) file_get_contents($file);

        foreach (['->input(', '->all(', '->post(', '->json(', '->query(', '->merge(', '->only(', '->except(', '$_POST', '$_GET', '$_REQUEST'] as $reader) {
            expect($source)->not->toContain($reader);
        }
    }
});

it('reads exactly one header, and it is the idempotency key', function () {
    $headers = [];

    foreach (sourceFiles() as $file) {
        preg_match_all("/->header\(\s*'([^']+)'/", (string) file_get_contents($file), $matches);

        $headers = [...$headers, ...$matches[1]];
    }

    expect(array_values(array_unique($headers)))->toBe(['Idempotency-Key']);
});

it('ignores every injected figure when it reads a plan', function () {
    $response = $this->json('GET', api('/plans/order-9f2c?total_minor=1&balance=0&capacity=5&currency=XXX'), injectedBody(), injectedHeaders());

    $response->assertOk()
        ->assertJsonPath('data.payable_total.minor', 16_000)
        ->assertJsonPath('data.payable_total.currency', 'GBP')
        ->assertJsonPath('data.outstanding.minor', 16_000);
});

it('ignores every injected figure when it admits a plan', function () {
    $response = $this->json(
        'POST',
        api('/plans/order-9f2c/admissions?capacity=999999999'),
        injectedBody(['tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']]]),
        injectedHeaders(),
    );

    // The capacity the host resolved wins over the one the caller shouted from
    // four different places.
    $response->assertOk()
        ->assertJsonPath('data.payable_total.minor', 16_000)
        ->assertJsonPath('data.tenders.0.admitted.minor', 4_000)
        ->assertJsonPath('data.tenders.0.admitted.currency', 'GBP')
        ->assertJsonPath('data.outstanding.minor', 12_000);
});

it('ignores every injected figure when it records a tender', function () {
    $response = $this->json(
        'POST',
        api('/plans/order-9f2c/tenders'),
        injectedBody([
            'tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']],
            'position' => 0,
        ]),
        injectedHeaders(['Idempotency-Key' => 'walk']),
    );

    $response->assertCreated()
        ->assertJsonPath('data.amount.minor', 4_000)
        ->assertJsonPath('data.amount.currency', 'GBP')
        ->assertJsonPath('data.amount.exponent', 2)
        ->assertJsonPath('plan.payable_total.minor', 16_000)
        ->assertJsonPath('plan.outstanding.minor', 12_000);
});

it('ignores every injected figure when it pages a ledger', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 1_000]],
        'position' => 0,
    ], ['Idempotency-Key' => 'one'])->assertCreated();

    $this->json('GET', api('/plans/order-9f2c/tenders?total_minor=1&capacity=9'), injectedBody(), injectedHeaders())
        ->assertOk()
        ->assertJsonPath('data.0.amount.minor', 1_000)
        ->assertJsonPath('data.0.amount.currency', 'GBP');
});

it('ignores every injected figure when it reverses a tender', function () {
    $id = (int) $this->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 1_000]],
        'position' => 0,
    ], ['Idempotency-Key' => 'one'])->json('data.id');

    $this->json(
        'POST',
        api('/plans/order-9f2c/tenders/'.$id.'/reversals'),
        injectedBody(['reason' => 'issuer declined']),
        injectedHeaders(['Idempotency-Key' => 'rev']),
    )
        ->assertCreated()
        ->assertJsonPath('data.amount.minor', 1_000)
        ->assertJsonPath('plan.payable_total.minor', 16_000)
        ->assertJsonPath('plan.outstanding.minor', 16_000);
});

it('cannot be told a currency, so a mixed-currency plan is unreachable from a body', function () {
    // The plan's currency has one origin: the total the host resolved. A caller
    // naming one could make a tender disagree with its order, so no route reads
    // one at all — this asks for JPY four ways and gets GBP.
    $response = $this->json(
        'POST',
        api('/plans/order-9f2c/admissions?currency=JPY'),
        injectedBody(['tenders' => [['kind' => 'card', 'amount_minor' => 1_000]], 'currency' => 'JPY', 'exponent' => 0]),
        injectedHeaders(['X-Currency' => 'JPY']),
    );

    $response->assertOk()
        ->assertJsonPath('data.currency', 'GBP')
        ->assertJsonPath('data.tenders.0.admitted.exponent', 2);
});

it('never lets a caller decide what an order is worth, whatever it sends', function () {
    // The server-side figure is the same one whether the request is bare or
    // carrying every forbidden key this test knows how to spell.
    $bare = $this->getJson(api('/plans/order-9f2c'))->json('data.payable_total');
    $loud = $this->json('GET', api('/plans/order-9f2c'), injectedBody(), injectedHeaders())->json('data.payable_total');

    expect($loud)->toBe($bare)->and($bare['minor'])->toBe(16_000);
});
