<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * The output walk, kept from wave 7.
 *
 * Every field every operation emits is enumerated rather than spot-checked. Two
 * things are proved of all of them: the idempotency bookkeeping never leaves
 * the module, and every money value is the settled envelope with `decimal` a
 * string — because `19.99` in JSON is a double in every client that reads it.
 */

beforeEach(function () {
    actingWithEveryScope();
    bindHost(16_000)->capacity(TenderKind::GiftCard, 'gc_7734', new Money(4_000, 'GBP'));
});

/** @return array<string, array<string, mixed>> every response body this API can produce, keyed */
function everyResponse(): array
{
    $bodies = [];

    $bodies['admission'] = test()->postJson(api('/plans/order-9f2c/admissions'), [
        'tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']],
    ])->json();

    $bodies['recorded'] = test()->postJson(api('/plans/order-9f2c/tenders'), [
        'tenders' => [['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']],
        'position' => 0,
        'external_reference' => 'gc_redemption_88121',
    ], ['Idempotency-Key' => 'walk-out'])->json();

    $id = (int) $bodies['recorded']['data']['id'];

    $bodies['reversed'] = test()->postJson(api('/plans/order-9f2c/tenders/'.$id.'/reversals'), [
        'reason' => 'issuer declined after capture',
    ], ['Idempotency-Key' => 'walk-rev'])->json();

    $bodies['plan'] = test()->getJson(api('/plans/order-9f2c'))->json();
    $bodies['ledger'] = test()->getJson(api('/plans/order-9f2c/tenders'))->json();

    return $bodies;
}

it('never emits the idempotency bookkeeping the ledger keeps', function () {
    // `idempotency_key` and `payload_hash` are the module's own record of what
    // it already answered. Emitting a key lets one caller replay another's.
    foreach (everyResponse() as $name => $body) {
        foreach (array_keys(leaves($body)) as $path) {
            expect($path)->not->toContain('idempotency_key');
            expect($path)->not->toContain('payload_hash');
        }

        expect(json_encode($body))->not->toContain('walk-out');
        expect($name)->toBeString();
    }
});

it('emits every money value as the settled envelope', function () {
    foreach (everyResponse() as $body) {
        foreach (moneyValues($body) as $path => $money) {
            expect(array_keys($money))->toBe(['minor', 'currency', 'exponent', 'decimal'])
                ->and($money['minor'])->toBeInt()
                ->and($money['exponent'])->toBeInt()
                ->and($money['currency'])->toBeString()
                ->and($money['decimal'])->toBeString()
                ->and($path)->toBeString();
        }
    }
});

it('emits no floating-point number anywhere at all', function () {
    foreach (everyResponse() as $body) {
        foreach (leaves($body) as $path => $value) {
            expect(is_float($value))->toBeFalse("[{$path}] came back as a float.");
        }
    }
});

it('emits a decimal that agrees with its minor units, by string arithmetic', function () {
    // `(int) (19.99 * 100)` is 1998. The presentation is derived from the
    // integer, never the other way round, and this pins the reason.
    bindHost(1_999);

    $money = $this->getJson(api('/plans/order-9f2c'))->json('data.payable_total');

    expect($money['decimal'])->toBe('19.99')
        ->and((int) str_replace('.', '', $money['decimal']))->toBe($money['minor']);
});

it('emits the same shape for a currency with no minor units', function () {
    bindHost(16_000, currency: 'JPY', exponent: 0);

    $money = $this->getJson(api('/plans/order-9f2c'))->json('data.outstanding');

    expect($money)->toBe(['minor' => 16_000, 'currency' => 'JPY', 'exponent' => 0, 'decimal' => '16000']);
});

it('emits no status field for a plan, because the domain stores none', function () {
    $responses = everyResponse();

    foreach (['plan', 'recorded'] as $name) {
        $body = $responses[$name];
        $plan = $body['plan'] ?? $body['data'];

        expect($plan)->not->toHaveKey('status')
            ->and($plan)->not->toHaveKey('amount_paid');
    }
});

it('emits the allocation record the host application never had', function () {
    // Which tender covered which portion of which plan, in which declared
    // position, with which external reference. The host asserted a status
    // string instead and could not compute a balance at all.
    $entry = everyResponse()['recorded']['data'];

    expect($entry)->toHaveKeys(['position', 'kind', 'amount', 'requested', 'partly_spent', 'external_reference'])
        ->and($entry['external_reference'])->toBe('gc_redemption_88121')
        ->and($entry['position'])->toBe(0);
});
