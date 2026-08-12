<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Api\Support\Problem;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\OverAllocatedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderClaimInFlight;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderIdempotencyConflict;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderLedgerIsAppendOnly;

/*
 * The mapper, on its own.
 *
 * Every one of the domain's nine exceptions is here by class. That matters
 * twice over: it proves the mapping is exhaustive, and it proves the mapping is
 * made by `instanceof` — a test that constructed each class with the *same*
 * message and still got different statuses could not pass any other way.
 */

it('renders each domain refusal as its settled status', function (string $class, int $status, string $code) {
    /** @var Throwable $exception */
    $exception = new $class('every one of these carries an identical message');

    $problem = Problem::for($exception);

    expect($problem)->not->toBeNull()
        ->and($problem->status)->toBe($status)
        ->and($problem->code)->toBe($code)
        ->and($problem->message)->toBe('every one of these carries an identical message');
})->with([
    'a null total for an order that exists is a fact about that order' => [PayableTotalUnknown::class, 422, 'payable_total_unknown'],
    'the same key with different facts is permanent' => [TenderIdempotencyConflict::class, 409, 'idempotency_conflict'],
    'the same key still running is transient' => [TenderClaimInFlight::class, 423, 'tender_claim_in_flight'],
    'a plan exceeding the total is refused, never clamped' => [OverAllocatedPlan::class, 422, 'over_allocated_plan'],
    'a currency that disagrees with the order is refused' => [MixedCurrencyPlan::class, 422, 'mixed_currency_plan'],
    'an allocation with no exact answer' => [CannotAllocate::class, 422, 'cannot_allocate'],
    'a reversal that is refused' => [CannotReverseTender::class, 422, 'cannot_reverse_tender'],
    'the ledger cannot be edited' => [TenderLedgerIsAppendOnly::class, 409, 'tender_ledger_is_append_only'],
]);

it('separates the two idempotency failures without reading either message', function () {
    // One message, two classes, two opposite instructions: give up, and try
    // again. Wave 4 separated a pair like this with str_contains and it is
    // recorded as a defect. If this mapping ever decodes a message, both of
    // these become the same answer and this test fails.
    $message = 'Idempotency key [k] could not be used.';

    $permanent = Problem::for(new TenderIdempotencyConflict($message));
    $transient = Problem::for(new TenderClaimInFlight($message));

    expect($permanent?->status)->toBe(409)
        ->and($permanent?->retryAfter)->toBeNull()
        ->and($transient?->status)->toBe(423)
        ->and($transient?->retryAfter)->toBe(Problem::RETRY_AFTER);
});

it('puts Retry-After on the transient claim and on nothing else', function () {
    $transient = Problem::for(new TenderClaimInFlight('in flight'))?->toResponse();
    $permanent = Problem::for(new TenderIdempotencyConflict('conflict'))?->toResponse();

    expect($transient?->headers->get('Retry-After'))->toBe((string) Problem::RETRY_AFTER)
        ->and($transient?->getData(true)['error']['retry_after'])->toBe(Problem::RETRY_AFTER)
        ->and($permanent?->headers->has('Retry-After'))->toBeFalse()
        ->and($permanent?->getData(true)['error'])->not->toHaveKey('retry_after');
});

it('renders a transport refusal from the status it carries, not from its message', function () {
    $refusal = ApiRefusal::tenderNotFound('order-9f2c', 77);
    $problem = Problem::for($refusal);

    expect($problem?->status)->toBe($refusal->status)
        ->and($problem?->code)->toBe($refusal->errorCode)
        ->and($problem?->status)->toBe(404);
});

it('never tells a caller what the container said', function () {
    $problem = Problem::for(new BindingResolutionException('Target [Some\Internal\Class] is not instantiable while building [App\Foo].'));

    expect($problem?->status)->toBe(503)
        ->and($problem?->code)->toBe('resolver_unbound')
        ->and($problem?->message)->not->toContain('Some\Internal\Class');
});

it('keeps an unbound resolver and an unknown total as different failures', function () {
    // One is a deployment fault affecting every order; the other is a fact about
    // one order. An operator told the wrong one checks the wrong thing.
    expect(Problem::for(ApiRefusal::resolverUnbound('X'))?->status)->toBe(503)
        ->and(Problem::for(new PayableTotalUnknown('no total'))?->status)->toBe(422);
});

it('renders a validation failure with its field messages', function () {
    $problem = Problem::for(ValidationException::withMessages(['tenders.0.kind' => ['The kind is invalid.']]));
    $body = $problem?->toResponse()->getData(true);

    expect($problem?->status)->toBe(422)
        ->and($problem?->code)->toBe('validation_failed')
        ->and($body['error']['errors'])->toHaveKey('tenders.0.kind');
});

it('declines to answer for a throwable this package owns no mapping for', function () {
    // Rethrown, so the application's own handler renders it. Swallowing an
    // unknown failure as a tidy 500 is how a bug becomes invisible.
    expect(Problem::for(new RuntimeException('something else entirely')))->toBeNull();
});
