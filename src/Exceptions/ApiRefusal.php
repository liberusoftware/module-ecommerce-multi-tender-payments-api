<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions;

use Liberu\Ecommerce\MultiTenderPayments\Api\Support\Problem;
use RuntimeException;

/**
 * A refusal that belongs to the transport, not to the domain.
 *
 * Four of them, and every one is a fact about the HTTP request rather than
 * about a payment plan: a resolver the deployment never bound, a tender that is
 * not in this plan, a missing idempotency key, a reversal replayed with a
 * different reason. None of these is a rule the domain owns, so none of them is
 * re-decided here.
 *
 * The status and the code are **carried on the object**, not encoded in the
 * message. Nothing anywhere in this package tells two refusals apart by reading
 * a string: {@see Problem}
 * dispatches on class and reads these properties, and the domain's own two
 * idempotency classes are separated by `instanceof`.
 */
final class ApiRefusal extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * A published contract has no binding at all.
     *
     * The deployment is half-configured. It can be told so, but it must not be
     * told what the container said — a container message names classes and
     * paths a caller has no business knowing.
     */
    public static function resolverUnbound(string $contract): self
    {
        return new self(503, 'resolver_unbound', "The deployment has not bound [{$contract}], so no money figure can be resolved.");
    }

    public static function tenderNotFound(string $order, int|string $tender): self
    {
        return new self(404, 'tender_not_found', "Tender [{$tender}] is not in the ledger of order [{$order}].");
    }

    public static function positionNotAdmitted(int $position): self
    {
        return new self(422, 'position_not_admitted', "Position [{$position}] is not one of the admitted tenders in this plan.");
    }

    /**
     * Every state-changing operation requires one, so that a repeat is a repeat
     * rather than a second movement of money.
     */
    public static function idempotencyKeyRequired(): self
    {
        return new self(422, 'idempotency_key_required', 'This operation requires an Idempotency-Key header of 1 to 255 characters.');
    }

    /**
     * One tender declared both an amount and a share.
     *
     * The domain refuses a plan that mixes the two across tenders; this is the
     * same ambiguity inside a single one. Picking either silently would change a
     * number the caller gave us, which is the thing this module never does.
     */
    public static function tenderDeclarationAmbiguous(int $index): self
    {
        return new self(422, 'tender_declaration_ambiguous', "Tender [{$index}] declares both an amount and a share; declare one.");
    }

    /**
     * The tender is already reversed, and under a different reason.
     *
     * The same shape of failure as the domain's `TenderIdempotencyConflict`, and
     * rendered the same way: permanent, 409, retrying will never help. Replaying
     * the identical reversal is not this — that returns the entry that already
     * exists.
     */
    public static function reversalConflict(int|string $tender): self
    {
        return new self(409, 'reversal_conflict', "Tender [{$tender}] is already reversed under a different reason.");
    }
}
