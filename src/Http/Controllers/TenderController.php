<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Ecommerce\MultiTenderPayments\Actions\AdmitTenderPlan;
use Liberu\Ecommerce\MultiTenderPayments\Actions\RecordTender;
use Liberu\Ecommerce\MultiTenderPayments\Actions\ReverseTender;
use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Payload;
use Liberu\Ecommerce\MultiTenderPayments\Api\Support\Presenter;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderEffect;
use Liberu\Ecommerce\MultiTenderPayments\Queries\OutstandingBalance;

/**
 * The three operations that offer, record and undo a tender.
 *
 * There is no transaction across gateways, and this controller does not pretend
 * otherwise: a caller admits a plan, then records **one** tender at a time as
 * each institution answers. A decline recorded here leaves every earlier
 * capture exactly where it was, because that is what actually happened.
 */
final class TenderController extends Controller
{
    /**
     * Both contracts, because admission asks the host what each tender can give.
     *
     * @return list<class-string>
     */
    protected function requiredResolvers(): array
    {
        return [ResolvesPayableTotal::class, ResolvesTenderCapacity::class];
    }

    /**
     * Would this plan be admitted, and what would each tender contribute?
     *
     * Pure arithmetic. Nothing is stored, nothing moves, and therefore no
     * idempotency key is required — there is nothing a repeat could duplicate.
     *
     * A tender short of what was asked of it comes back **partly spent**, with
     * both figures rendered. Over-allocation is refused outright with a 422 and
     * is never clamped.
     */
    public function admit(Request $request, string $order): JsonResponse
    {
        $validated = $request->validate(Payload::rules('plans.admissions.store'));
        $payable = $this->payableTotal($order);

        $admitted = $this->make(AdmitTenderPlan::class)(
            $order,
            Payload::plannedTenders(self::tenders($validated), $payable),
        );

        return new JsonResponse(['data' => Presenter::admission($admitted)]);
    }

    /**
     * Append one tender to the ledger.
     *
     * The plan is re-admitted against the total the host resolves *now*, and the
     * caller names which position it is recording. That is deliberate: the
     * module stores no pending plan, so a total that moved between admitting and
     * recording is caught here rather than silently honoured.
     *
     * 201 when the entry was appended, 200 when the idempotency key replayed one
     * that already existed. The two failures are opposite instructions and are
     * separate classes: 409 for the same key with different facts, 423 with
     * `Retry-After` while the first attempt is still running.
     */
    public function record(Request $request, string $order): JsonResponse
    {
        $validated = $request->validate(Payload::rules('plans.tenders.store'));
        $key = $this->idempotencyKey($request);
        $payable = $this->payableTotal($order);

        $admitted = $this->make(AdmitTenderPlan::class)(
            $order,
            Payload::plannedTenders(self::tenders($validated), $payable),
        );

        $position = (int) $validated['position'];
        $tender = $admitted->tenders[$position] ?? throw ApiRefusal::positionNotAdmitted($position);

        $plan = $this->plan($order);

        $entry = $this->make(RecordTender::class)(
            $plan,
            $tender,
            $key,
            TenderEffect::from((string) ($validated['effect'] ?? TenderEffect::Captured->value)),
            self::nullableString($validated['external_reference'] ?? null),
        );

        return $this->entryResponse($order, $plan, $entry, $entry->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Undo a captured tender by appending a reversal that carries its reason.
     *
     * A reversal is **not** a refund. It records that a movement of money was
     * undone in this ledger; deciding that money is owed back to a customer is
     * `ecommerce-refunds` and nothing here creates anything there.
     *
     * Idempotency for this operation is carried by the ledger itself, because a
     * tender can be reversed exactly once and the reversal already records what
     * it reverses. Replaying the identical reversal returns the entry that
     * exists (200); a second reversal under a *different* reason is the same
     * permanent conflict the domain's `TenderIdempotencyConflict` describes, and
     * gets the same 409. The `Idempotency-Key` header is required all the same,
     * so that every state-changing operation in this package has one contract.
     */
    public function reverse(Request $request, string $order, string $tender): JsonResponse
    {
        $validated = $request->validate(Payload::rules('plans.tenders.reversals.store'));
        $this->idempotencyKey($request);

        $reason = trim((string) $validated['reason']);
        $plan = $this->plan($order);

        $entry = $plan->tenders()->whereKey($tender)->first()
            ?? throw ApiRefusal::tenderNotFound($order, $tender);

        $existing = $entry->reversal;

        if ($existing !== null) {
            if ((string) $existing->reason !== $reason) {
                throw ApiRefusal::reversalConflict($tender);
            }

            return $this->entryResponse($order, $plan, $existing, 200);
        }

        return $this->entryResponse($order, $plan, $this->make(ReverseTender::class)($entry, $reason), 201);
    }

    /** The entry that was written, and what the plan owes now that it exists. */
    private function entryResponse(string $order, mixed $plan, mixed $entry, int $status): JsonResponse
    {
        return new JsonResponse([
            'data' => Presenter::tender($entry, $plan->zero()),
            'plan' => Presenter::plan(
                $order,
                $this->payableTotal($order),
                $this->make(OutstandingBalance::class)->forPlan($plan),
            ),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private static function tenders(array $validated): array
    {
        /** @var list<array<string, mixed>> $tenders */
        $tenders = array_values((array) ($validated['tenders'] ?? []));

        return $tenders;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
