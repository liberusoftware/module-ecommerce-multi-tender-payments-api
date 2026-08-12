<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * Appending to the ledger, and the two-class idempotency scheme.
 *
 * There is no transaction across gateways, so a caller records one tender at a
 * time as each institution answers. A decline recorded here leaves every
 * earlier capture exactly where it was — the balance moves by nothing, because
 * nothing moved.
 *
 * The two idempotency failures are opposite instructions, and this file proves
 * they arrive as different statuses.
 */

beforeEach(function () {
    actingWithEveryScope();
    bindHost(16_000);
});

/**
 * The two-tender plan every test here records against, at one position.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recordBody(int $position = 0, array $overrides = []): array
{
    return [
        'tenders' => [
            ['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734'],
            ['kind' => 'card', 'amount_minor' => 6_000],
        ],
        'position' => $position,
        ...$overrides,
    ];
}

it('appends a tender and answers with the plan s new balance', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'k1'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'gift_card')
        ->assertJsonPath('data.effect', 'captured')
        ->assertJsonPath('data.position', 0)
        ->assertJsonPath('data.amount.minor', 10_000)
        ->assertJsonPath('data.partly_spent', false)
        ->assertJsonPath('plan.outstanding.minor', 6_000)
        ->assertJsonPath('plan.satisfied', false);
});

it('records a short tender as partly spent, at what it could actually give', function () {
    bindHost(16_000)->capacity(TenderKind::GiftCard, 'gc_7734', new Money(4_000, 'GBP'));

    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'k-short'])
        ->assertCreated()
        ->assertJsonPath('data.amount.minor', 4_000)
        ->assertJsonPath('data.requested.minor', 10_000)
        ->assertJsonPath('data.partly_spent', true)
        ->assertJsonPath('plan.outstanding.minor', 12_000);
});

it('records a decline without touching an earlier capture', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(0), ['Idempotency-Key' => 'captured'])->assertCreated();

    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(1, ['effect' => 'declined']), ['Idempotency-Key' => 'declined'])
        ->assertCreated()
        ->assertJsonPath('data.effect', 'declined')
        ->assertJsonPath('data.amount.minor', 0)
        ->assertJsonPath('data.requested.minor', 6_000)
        // The capture is untouched. No application-level rollback can un-happen
        // what already happened at another institution.
        ->assertJsonPath('plan.outstanding.minor', 6_000);
});

it('replays the identical request under the same key rather than recording twice', function () {
    $first = $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'same']);
    $second = $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'same']);

    expect($first->status())->toBe(201)
        ->and($second->status())->toBe(200)
        ->and($second->json('data.id'))->toBe($first->json('data.id'));

    $this->getJson(api('/plans/order-9f2c/tenders'))->assertJsonPath('meta.total', 1);
});

it('answers 409 for the same key with different facts, and never invites a retry', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(0, ['external_reference' => 'ch_a']), ['Idempotency-Key' => 'reused'])->assertCreated();

    $conflict = $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(0, ['external_reference' => 'ch_b']), ['Idempotency-Key' => 'reused']);

    $conflict->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

    expect($conflict->headers->has('Retry-After'))->toBeFalse();
});

it('answers 423 with Retry-After while the same key is still in flight', function () {
    holdClaim('in-flight');

    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'in-flight'])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'tender_claim_in_flight')
        ->assertJsonPath('error.retry_after', 10)
        ->assertHeader('Retry-After', '10');
});

it('tells the transient failure from the permanent one by class, not by message', function () {
    // Both come back from the same operation under a reused key. One says "give
    // up", the other says "try again", and a caller must never have to read a
    // sentence to find out which.
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'both'])->assertCreated();

    $permanent = $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(0, ['external_reference' => 'other']), ['Idempotency-Key' => 'both']);

    holdClaim('fresh');
    $transient = $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'fresh']);

    expect($permanent->status())->toBe(409)->and($transient->status())->toBe(423);
});

it('requires an idempotency key on every state-changing operation', function (string $uri) {
    $this->postJson($uri, ['tenders' => [['kind' => 'card', 'amount_minor' => 1]], 'position' => 0, 'reason' => 'because'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
})->with([
    'recording a tender' => [api('/plans/order-9f2c/tenders')],
    'reversing one' => [api('/plans/order-9f2c/tenders/1/reversals')],
]);

it('refuses an idempotency key wider than the column that holds it', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => str_repeat('k', 256)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
});

it('refuses a position no admitted tender occupies', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(7), ['Idempotency-Key' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'position_not_admitted');
});

it('refuses to record a reversal through the append operation', function () {
    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(0, ['effect' => 'reversed']), ['Idempotency-Key' => 'wrong-way'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('re-admits the plan against the total the host resolves now', function () {
    // The module stores no pending plan, so a total that moved between admitting
    // and recording is caught here rather than silently honoured.
    bindHost(8_000);

    $this->postJson(api('/plans/order-9f2c/tenders'), recordBody(), ['Idempotency-Key' => 'moved'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'over_allocated_plan');
});
