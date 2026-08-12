<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Payload;
use Liberu\Ecommerce\MultiTenderPayments\Api\Support\Presenter;
use Liberu\Ecommerce\MultiTenderPayments\Queries\OutstandingBalance;

/**
 * The two read operations.
 *
 * Neither takes a body. Between them they answer the only two questions a
 * caller has about a plan — what is still owed, and what happened — and both
 * answers are computed. There is no status to read and no cached total to go
 * stale, because the domain stores neither.
 */
final class PlanController extends Controller
{
    /**
     * A plan: the payable total, the outstanding balance, and whether it is done.
     *
     * The total comes from the host's resolver on every call. If the host
     * re-prices the order — a discount applied, a line removed — the balance
     * moves, and that is correct: the ledger is the only other input and it does
     * not change retrospectively.
     */
    public function show(string $order): JsonResponse
    {
        $payable = $this->payableTotal($order);
        $plan = $this->plan($order);

        return new JsonResponse([
            'data' => Presenter::plan($order, $payable, $this->make(OutstandingBalance::class)->forPlan($plan)),
        ]);
    }

    /**
     * A page of the append-only ledger, oldest first.
     *
     * Ordered by id rather than by `occurred_at`: two entries can share a
     * timestamp, and a page boundary that is not total order silently drops or
     * repeats a row.
     */
    public function tenders(Request $request, string $order): JsonResponse
    {
        $request->validate(Payload::rules('plans.tenders.index'));

        $plan = $this->plan($order);
        $perPage = min((int) $request->integer('per_page', Payload::perPage()), Payload::maxPerPage());

        return new JsonResponse(Presenter::page(
            $plan->tenders()->orderBy('id')->paginate($perPage),
            $plan->zero(),
        ));
    }
}
