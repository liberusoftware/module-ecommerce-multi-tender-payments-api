<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotAllocate;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\CannotReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\MixedCurrencyPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\OverAllocatedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderClaimInFlight;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderIdempotencyConflict;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\TenderLedgerIsAppendOnly;
use Throwable;

/**
 * The one place a refusal becomes a status code.
 *
 * Every arm below dispatches on **class**. Not one of them reads a message, and
 * the two that matter most are the reason why: `TenderIdempotencyConflict` and
 * `TenderClaimInFlight` are opposite instructions to a caller — *give up* and
 * *try again* — and the domain publishes them as two classes precisely so that
 * a boundary never has to guess. Wave 4 decoded a message to separate two
 * conditions and it is recorded as a defect; this is the shape that replaced it.
 *
 * | Refusal | Status | Meaning to a caller |
 * | --- | --- | --- |
 * | resolver unbound | 503 | the deployment is broken; not your request |
 * | `PayableTotalUnknown` | 422 | this order has no total; a fact about the order |
 * | `TenderIdempotencyConflict` | 409 | same key, different facts — permanent |
 * | `TenderClaimInFlight` | 423 | same key, still running — retry, `Retry-After` |
 * | `OverAllocatedPlan` | 422 | your plan exceeds the total; rebuild it |
 * | `MixedCurrencyPlan` | 422 | a capacity came back in another currency |
 * | `CannotAllocate` | 422 | the arithmetic has no exact answer |
 * | `CannotReverseTender` | 422 | not captured, already reversed, or no reason |
 * | `TenderLedgerIsAppendOnly` | 409 | the ledger cannot be edited, ever |
 *
 * 503 and 422 are deliberately *not* the same failure. An unbound resolver is a
 * deployment fault that affects every order; a null total is a fact about one
 * order. Collapsing them would tell an operator to check the wrong thing.
 */
final readonly class Problem
{
    /**
     * Seconds a caller should wait before retrying a transient claim.
     *
     * Matches the ten-second TTL `RecordTender` takes its lock for: the claim
     * cannot outlive the lock, so there is nothing to gain by waiting longer and
     * a shorter wait would just collide again.
     */
    public const RETRY_AFTER = 10;

    /** @param array<string, list<string>> $errors */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public ?int $retryAfter = null,
        public array $errors = [],
    ) {}

    /** The problem this throwable renders as, or null if this package owns no answer for it. */
    public static function for(Throwable $e): ?self
    {
        return match (true) {
            $e instanceof ApiRefusal => new self($e->status, $e->errorCode, $e->getMessage()),

            // Two classes, two opposite instructions, told apart by instanceof.
            $e instanceof TenderIdempotencyConflict => new self(409, 'idempotency_conflict', $e->getMessage()),
            $e instanceof TenderClaimInFlight => new self(423, 'tender_claim_in_flight', $e->getMessage(), self::RETRY_AFTER),

            $e instanceof PayableTotalUnknown => new self(422, 'payable_total_unknown', $e->getMessage()),
            $e instanceof OverAllocatedPlan => new self(422, 'over_allocated_plan', $e->getMessage()),
            $e instanceof MixedCurrencyPlan => new self(422, 'mixed_currency_plan', $e->getMessage()),
            $e instanceof CannotAllocate => new self(422, 'cannot_allocate', $e->getMessage()),
            $e instanceof CannotReverseTender => new self(422, 'cannot_reverse_tender', $e->getMessage()),
            $e instanceof TenderLedgerIsAppendOnly => new self(409, 'tender_ledger_is_append_only', $e->getMessage()),

            // A contract bound to something the container cannot build is the
            // same deployment fault as one bound to nothing, and the caller is
            // told the same thing — never what the container said.
            $e instanceof BindingResolutionException => new self(503, 'resolver_unbound', 'A published contract this operation needs could not be resolved.'),

            $e instanceof ValidationException => new self(422, 'validation_failed', 'The request body is not valid.', null, self::messages($e)),

            default => null,
        };
    }

    public function toResponse(): JsonResponse
    {
        $body = ['error' => array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'retry_after' => $this->retryAfter,
            'errors' => $this->errors === [] ? null : $this->errors,
        ], static fn (mixed $value): bool => $value !== null)];

        $headers = $this->retryAfter === null ? [] : ['Retry-After' => (string) $this->retryAfter];

        return new JsonResponse($body, $this->status, $headers);
    }

    /** @return array<string, list<string>> */
    private static function messages(ValidationException $e): array
    {
        /** @var array<string, list<string>> $messages */
        $messages = $e->errors();

        return $messages;
    }
}
