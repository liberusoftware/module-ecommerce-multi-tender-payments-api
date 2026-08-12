<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;

/*
 * Reading a plan and its ledger.
 *
 * There is no status column and no cached balance in the domain, so everything
 * here is computed on every call. The two failure directions are the point of
 * the file: an unbound resolver is 503 and affects every order; a null total is
 * 422 and is a fact about one order. Collapsing them would send an operator to
 * check the wrong thing.
 */

beforeEach(function () {
    actingWithEveryScope();
});

it('reads a plan nobody has tendered against as wholly unsatisfied', function () {
    bindHost(16_000);

    $response = $this->getJson(api('/plans/order-9f2c'));

    $response->assertOk()->assertJsonPath('data.order_reference', 'order-9f2c');

    expect($response->json('data.payable_total'))->toBe(['minor' => 16_000, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '160.00'])
        ->and($response->json('data.outstanding'))->toBe(['minor' => 16_000, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '160.00'])
        ->and($response->json('data.satisfied'))->toBeFalse();
});

it('renders money as the settled envelope with decimal a string', function () {
    bindHost(1_999);

    $money = $this->getJson(api('/plans/order-9f2c'))->json('data.payable_total');

    expect($money)->toHaveKeys(['minor', 'currency', 'exponent', 'decimal'])
        ->and($money['minor'])->toBeInt()
        ->and($money['decimal'])->toBeString()
        ->and($money['decimal'])->toBe('19.99');
});

it('renders a zero-exponent currency without inventing a point', function () {
    bindHost(16_000, currency: 'JPY', exponent: 0);

    expect($this->getJson(api('/plans/order-9f2c'))->json('data.payable_total'))
        ->toBe(['minor' => 16_000, 'currency' => 'JPY', 'exponent' => 0, 'decimal' => '16000']);
});

it('answers 503 when the payable-total resolver is unbound', function () {
    // Nothing is bound. A half-configured deployment cannot resolve a figure
    // for any order, which is a deployment fault and not a request fault.
    expect(app()->bound(ResolvesPayableTotal::class))->toBeFalse();

    $this->getJson(api('/plans/order-9f2c'))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'resolver_unbound');
});

it('answers 422 when the resolver is bound and knows nothing about this order', function () {
    bindHost(16_000, order: 'order-9f2c');

    $this->getJson(api('/plans/order-does-not-exist'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payable_total_unknown');
});

it('keeps an unbound resolver and an unknown total as two different answers', function () {
    bindHost(16_000);

    $unknown = $this->getJson(api('/plans/order-nobody-prices'));

    app()->forgetInstance(ResolvesPayableTotal::class);
    app()->forgetInstance(ResolvesTenderCapacity::class);

    $unbound = $this->getJson(api('/plans/order-9f2c'));

    expect($unknown->status())->toBe(422)->and($unbound->status())->toBe(503);
});

it('pages a plan s ledger, oldest first, with the meta a client needs', function () {
    $host = bindHost(16_000);

    foreach ([1, 2, 3] as $index) {
        $this->postJson(api('/plans/order-9f2c/tenders'), [
            'tenders' => [['kind' => 'card', 'amount_minor' => 1_000]],
            'position' => 0,
            'external_reference' => 'ch_'.$index,
        ], ['Idempotency-Key' => 'key-'.$index])->assertCreated();
    }

    expect($host->totals)->toHaveKey('order-9f2c');

    $page = $this->getJson(api('/plans/order-9f2c/tenders?per_page=2'));

    $page->assertOk()
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);

    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('data.0.external_reference'))->toBe('ch_1');

    $this->getJson(api('/plans/order-9f2c/tenders?per_page=2&page=2'))
        ->assertOk()
        ->assertJsonPath('meta.page', 2)
        ->assertJsonPath('data.0.external_reference', 'ch_3');
});

it('refuses a page size beyond the configured cap', function () {
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c/tenders?per_page=5000'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('reports a satisfied plan as the fold reaching zero, with no status column anywhere', function () {
    bindHost(10_000);

    $this->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 10_000]],
        'position' => 0,
    ], ['Idempotency-Key' => 'settle'])->assertCreated();

    $plan = $this->getJson(api('/plans/order-9f2c'))->json('data');

    expect($plan['satisfied'])->toBeTrue()
        ->and($plan['outstanding']['minor'])->toBe(0)
        ->and($plan)->not->toHaveKey('status');
});
