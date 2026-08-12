<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Api\Tests\Fixtures\FakeHost;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * The wave-8 arithmetic, rendered.
 *
 * The question wave 7 deferred — a gift card worth less than the order total —
 * is settled here in the direction §5 settled it: **partly spent, not refused**.
 * Wave 7 refused a short card only because it had no published total to measure
 * against, and this module supplies one.
 *
 * Nothing in this file writes anything. Admission is pure arithmetic.
 */

beforeEach(function () {
    actingWithEveryScope();
});

it('partly spends a tender short of what was asked of it, and says so', function () {
    $host = bindHost(16_000);
    $host->capacity(TenderKind::GiftCard, 'gc_7734', new Money(4_000, 'GBP'));

    $response = $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [
            ['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734'],
            ['kind' => 'card', 'amount_minor' => 6_000],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.tenders.0.requested.minor', 10_000)
        ->assertJsonPath('data.tenders.0.admitted.minor', 4_000)
        ->assertJsonPath('data.tenders.0.partly_spent', true)
        ->assertJsonPath('data.tenders.1.admitted.minor', 6_000)
        ->assertJsonPath('data.tenders.1.partly_spent', false)
        ->assertJsonPath('data.allocated.minor', 10_000)
        ->assertJsonPath('data.outstanding.minor', 6_000);
});

it('treats a null capacity as no ceiling known, and never as zero', function () {
    // The ordinary answer for a card, whose limit lives at the issuer. A host
    // returning zero here would find every card tender admitted at nothing.
    bindHost(16_000)->capacity(TenderKind::Card, null, null);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 16_000]],
    ])->assertOk()->assertJsonPath('data.tenders.0.admitted.minor', 16_000);
});

it('refuses an over-allocated plan outright rather than clamping it', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [
            ['kind' => 'card', 'amount_minor' => 10_000],
            ['kind' => 'bank_transfer', 'amount_minor' => 10_000],
        ],
    ])->assertStatus(422)->assertJsonPath('error.code', 'over_allocated_plan');
});

it('permits an under-allocated plan and calls the shortfall the outstanding balance', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'deposit', 'amount_minor' => 5_000]],
    ])->assertOk()
        ->assertJsonPath('data.allocated.minor', 5_000)
        ->assertJsonPath('data.outstanding.minor', 11_000);
});

it('splits a total across shares exactly, with no residue', function () {
    bindHost(10_000);

    $response = $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [
            ['kind' => 'gift_card', 'share' => 1],
            ['kind' => 'store_credit', 'share' => 1],
            ['kind' => 'card', 'share' => 1],
        ],
    ]);

    $parts = array_column($response->json('data.tenders'), 'admitted');
    $minors = array_column($parts, 'minor');

    expect(array_sum($minors))->toBe(10_000)
        ->and($minors)->toBe([3_334, 3_333, 3_333]);
});

it('keeps the caller s declared order and gives no kind a priority', function () {
    bindHost(9_000);

    $tenders = $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [
            ['kind' => 'card', 'amount_minor' => 1_000],
            ['kind' => 'gift_card', 'amount_minor' => 2_000],
            ['kind' => 'store_credit', 'amount_minor' => 3_000],
        ],
    ])->json('data.tenders');

    expect(array_column($tenders, 'kind'))->toBe(['card', 'gift_card', 'store_credit'])
        ->and(array_column($tenders, 'position'))->toBe([0, 1, 2]);
});

it('refuses a capacity that comes back in another currency', function () {
    // The caller never names a currency, so this can only arrive from the host's
    // own resolver — and it is still refused rather than converted.
    bindHost(16_000)->capacity(TenderKind::GiftCard, 'gc_7734', new Money(4_000, 'EUR'));

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']],
    ])->assertStatus(422)->assertJsonPath('error.code', 'mixed_currency_plan');
});

it('refuses a plan that mixes declared amounts with declared shares', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [
            ['kind' => 'card', 'amount_minor' => 1_000],
            ['kind' => 'gift_card', 'share' => 1],
        ],
    ])->assertStatus(422)->assertJsonPath('error.code', 'cannot_allocate');
});

it('refuses one tender that declares both an amount and a share', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 1_000, 'share' => 2]],
    ])->assertStatus(422)->assertJsonPath('error.code', 'tender_declaration_ambiguous');
});

it('refuses a tender kind the domain does not publish', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'crypto', 'amount_minor' => 1_000]],
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('stores nothing at all', function () {
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 1_000]],
    ])->assertOk();

    $this->getJson(api('/plans/order-9f2c/tenders'))->assertOk()->assertJsonPath('meta.total', 0);
});

it('needs a capacity resolver as well as a total resolver', function () {
    // Reading a plan needs only a total. Admitting one asks the host what each
    // tender can give, so it needs both — and says so as a 503 rather than
    // resolving a capacity to zero.
    app()->instance(ResolvesPayableTotal::class, new FakeHost());

    $this->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 1_000]],
    ])->assertStatus(503)->assertJsonPath('error.code', 'resolver_unbound');
});
