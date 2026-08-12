<?php

declare(strict_types=1);

/*
 * Undoing a tender, which is a new ledger entry and not a refund.
 *
 * The ledger has no update path and no delete path, because the capture a
 * reversal undoes happened at an institution this module does not control and
 * editing a row here would make the ledger disagree with the world.
 *
 * A reversal here creates nothing in `ecommerce-refunds`. Deciding that money
 * is owed back to a customer is that module's, and this package imports it
 * nowhere — see tests/Unit/BoundaryTest.php.
 */

beforeEach(function () {
    actingWithEveryScope();
    bindHost(16_000);

    $this->tender = (int) $this->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']],
        'position' => 0,
        'external_reference' => 'gc_redemption_88121',
    ], ['Idempotency-Key' => 'captured'])->assertCreated()->json('data.id');
});

it('appends a reversal carrying its reason and gives the balance back', function () {
    $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), [
        'reason' => 'Gift card redemption failed at the issuer after capture.',
    ], ['Idempotency-Key' => 'rev-1'])
        ->assertCreated()
        ->assertJsonPath('data.effect', 'reversed')
        ->assertJsonPath('data.reverses_tender_id', $this->tender)
        ->assertJsonPath('data.reason', 'Gift card redemption failed at the issuer after capture.')
        ->assertJsonPath('plan.outstanding.minor', 16_000);
});

it('leaves the capture in the ledger rather than editing or deleting it', function () {
    $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => 'issuer declined'], ['Idempotency-Key' => 'rev-1'])
        ->assertCreated();

    $ledger = $this->getJson(api('/plans/order-9f2c/tenders'))->json();

    expect($ledger['meta']['total'])->toBe(2)
        ->and($ledger['data'][0]['effect'])->toBe('captured')
        ->and($ledger['data'][0]['amount']['minor'])->toBe(10_000)
        ->and($ledger['data'][1]['effect'])->toBe('reversed');
});

it('replays an identical reversal instead of appending a second one', function () {
    $first = $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => 'issuer declined'], ['Idempotency-Key' => 'rev-1']);
    $second = $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => 'issuer declined'], ['Idempotency-Key' => 'rev-2']);

    expect($first->status())->toBe(201)
        ->and($second->status())->toBe(200)
        ->and($second->json('data.id'))->toBe($first->json('data.id'));

    $this->getJson(api('/plans/order-9f2c/tenders'))->assertJsonPath('meta.total', 2);
});

it('answers 409 for a second reversal under a different reason', function () {
    // The same permanent-conflict shape the domain's TenderIdempotencyConflict
    // describes: the facts differ, so retrying will never help.
    $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => 'issuer declined'], ['Idempotency-Key' => 'rev-1'])->assertCreated();

    $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => 'operator changed their mind'], ['Idempotency-Key' => 'rev-2'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'reversal_conflict');
});

it('refuses a reversal whose reason is only whitespace', function () {
    // The framework's TrimStrings and ConvertEmptyStringsToNull turn this into
    // a missing field before the domain ever sees it, so it lands as a
    // validation failure rather than as the domain's own refusal. Both are 422
    // and both refuse; the point of the test is that a blank reason is never
    // recorded.
    $this->postJson(api('/plans/order-9f2c/tenders/'.$this->tender.'/reversals'), ['reason' => '   '], ['Idempotency-Key' => 'rev-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->getJson(api('/plans/order-9f2c/tenders'))->assertJsonPath('meta.total', 1);
});

it('refuses to reverse a tender that was never captured', function () {
    $declined = (int) $this->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'card', 'amount_minor' => 6_000]],
        'position' => 0,
        'effect' => 'declined',
    ], ['Idempotency-Key' => 'declined'])->json('data.id');

    $this->postJson(api('/plans/order-9f2c/tenders/'.$declined.'/reversals'), ['reason' => 'nothing to undo'], ['Idempotency-Key' => 'rev-x'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cannot_reverse_tender');
});

it('answers 404 for a tender that is not in this plan s ledger', function () {
    // Scoped through the plan's own relation, so one order can never reverse
    // another order's tender by guessing an id.
    $this->postJson(api('/plans/order-somebody-else/tenders/'.$this->tender.'/reversals'), ['reason' => 'not mine'], ['Idempotency-Key' => 'rev-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payable_total_unknown');

    bindHost(16_000)->total('order-somebody-else', 5_000);

    $this->postJson(api('/plans/order-somebody-else/tenders/'.$this->tender.'/reversals'), ['reason' => 'not mine'], ['Idempotency-Key' => 'rev-1'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'tender_not_found');
});
