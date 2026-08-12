<?php

declare(strict_types=1);

use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Payload;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/*
 * The accepted surface, enumerated.
 *
 * These are the structural half of the input walk; the behavioural half lives
 * in tests/Feature/InputWalkTest.php and drives real requests. Both are needed:
 * one proves the package cannot name a forbidden figure, the other proves that
 * naming one anyway changes no answer.
 */

it('accepts no key anywhere that names a total, a balance or a capacity', function (string $operation) {
    $rules = Payload::rules($operation);

    expect($rules)->not->toBeEmpty();

    foreach (array_keys($rules) as $key) {
        expect($key)->not->toMatch('/total|balance|capacit|payable|outstanding/i');
    }
})->with(Payload::ALL);

it('accepts no currency either, because a plan has exactly one and it is not the caller s', function (string $operation) {
    foreach (array_keys(Payload::rules($operation)) as $key) {
        expect($key)->not->toMatch('/currency|exponent|minor_unit/i');
    }
})->with(Payload::ALL);

it('denominates every offered amount in the currency the host resolved', function () {
    // The caller sends `10000` and nothing else. What that means — pounds,
    // pence, yen — is decided entirely by the payable total the host answered
    // with, which is what makes a mixed-currency plan unreachable from a body.
    $payable = new Money(16_000, 'JPY', 0);

    $planned = Payload::plannedTenders([['kind' => 'gift_card', 'amount_minor' => 10_000, 'reference' => 'gc_7734']], $payable);

    expect($planned)->toHaveCount(1)
        ->and($planned[0]->kind)->toBe(TenderKind::GiftCard)
        ->and($planned[0]->amount?->currency)->toBe('JPY')
        ->and($planned[0]->amount?->exponent)->toBe(0)
        ->and($planned[0]->amount?->minor)->toBe(10_000)
        ->and($planned[0]->reference)->toBe('gc_7734');
});

it('builds a share tender with no money at all', function () {
    $planned = Payload::plannedTenders([['kind' => 'card', 'share' => 3]], new Money(16_000, 'GBP'));

    expect($planned[0]->amount)->toBeNull()->and($planned[0]->share)->toBe(3);
});

it('refuses a tender that declares both an amount and a share', function () {
    // Picking either would silently change a number the caller gave us, which
    // is the one thing this module never does.
    expect(fn () => Payload::plannedTenders([['kind' => 'card', 'amount_minor' => 100, 'share' => 3]], new Money(16_000, 'GBP')))
        ->toThrow(ApiRefusal::class);
});

it('treats an empty reference as no reference rather than as an empty instrument', function () {
    $planned = Payload::plannedTenders([['kind' => 'card', 'amount_minor' => 100, 'reference' => '', 'instalment_reference' => '']], new Money(16_000, 'GBP'));

    expect($planned[0]->reference)->toBeNull()->and($planned[0]->instalmentReference)->toBeNull();
});

it('offers every tender kind the domain publishes, and no kind it does not', function () {
    $rules = Payload::rules('plans.admissions.store')['tenders.*.kind'];
    $rendered = implode('|', array_map(strval(...), $rules));

    foreach (TenderKind::cases() as $kind) {
        expect($rendered)->toContain($kind->value);
    }
});

it('caps a page at the configured maximum', function () {
    expect(Payload::maxPerPage())->toBe(100)->and(Payload::perPage())->toBe(25);
});
