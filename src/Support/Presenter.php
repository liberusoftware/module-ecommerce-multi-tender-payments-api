<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Liberu\Ecommerce\MultiTenderPayments\Plans\AdmittedPlan;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * Domain values, rendered.
 *
 * Two rules govern everything here.
 *
 * **Money is the settled envelope** — `{minor, currency, exponent, decimal}`,
 * with `decimal` a string. Never a float, never a bare number a JSON parser
 * would hand back as one: `19.99` in JSON is a double in every client, and a
 * double is the thing this fleet spent wave 3 removing.
 *
 * **Only what the caller is owed.** A ledger row carries an `idempotency_key`
 * and a `payload_hash`; neither is anybody's business but the module's, and a
 * test walks every response asserting they never appear.
 */
final class Presenter
{
    /** @return array{minor: int, currency: string, exponent: int, decimal: string} */
    public static function money(Money $money): array
    {
        return $money->toArray();
    }

    /**
     * A plan: what the order costs, what is still owed, and whether it is done.
     *
     * There is no status field, because the domain has no status column and one
     * invented here would be a second copy of something the ledger already
     * knows. "Satisfied" is the fold reaching zero, computed on every read.
     *
     * @return array<string, mixed>
     */
    public static function plan(string $orderReference, Money $payable, Money $outstanding): array
    {
        return [
            'order_reference' => $orderReference,
            'currency' => $payable->currency,
            'payable_total' => self::money($payable),
            'outstanding' => self::money($outstanding),
            'satisfied' => $outstanding->minor <= 0,
        ];
    }

    /**
     * One ledger entry.
     *
     * `$zero` is the plan's currency, passed in rather than read off the entry's
     * `plan` relation: reading the relation per row is a query per row, and the
     * caller already holds the plan.
     *
     * @return array<string, mixed>
     */
    public static function tender(mixed $entry, Money $zero): array
    {
        return [
            'id' => $entry->id,
            'position' => $entry->position,
            'kind' => $entry->kind->value,
            'effect' => $entry->effect->value,
            'amount' => self::money($zero->withMinor((int) $entry->amount_minor)),
            'requested' => self::money($zero->withMinor((int) $entry->requested_minor)),
            'partly_spent' => (int) $entry->amount_minor < (int) $entry->requested_minor,
            'external_reference' => $entry->external_reference,
            'instalment_reference' => $entry->instalment_reference,
            'reverses_tender_id' => $entry->reverses_tender_id,
            'reason' => $entry->reason,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * An admission: arithmetic only, and nothing here has been stored.
     *
     * `partly_spent` is the wave-8 answer made visible. A gift card asked to
     * cover the whole total and worth 40% of it contributes 40%, and both
     * figures are rendered so nothing about the reduction is silent.
     *
     * @return array<string, mixed>
     */
    public static function admission(AdmittedPlan $plan): array
    {
        $tenders = [];

        foreach ($plan->tenders as $tender) {
            $tenders[] = [
                'position' => $tender->position,
                'kind' => $tender->kind->value,
                'requested' => self::money($tender->requested),
                'admitted' => self::money($tender->admitted),
                'partly_spent' => $tender->isPartlySpent(),
                'reference' => $tender->reference,
                'instalment_reference' => $tender->instalmentReference,
            ];
        }

        return [
            'order_reference' => $plan->orderReference,
            'currency' => $plan->payable->currency,
            'payable_total' => self::money($plan->payable),
            'allocated' => self::money($plan->allocated()),
            'outstanding' => self::money($plan->outstanding()),
            'tenders' => $tenders,
        ];
    }

    /**
     * A page of ledger entries.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, mixed>
     */
    public static function page(LengthAwarePaginator $page, Money $zero): array
    {
        $data = [];

        foreach ($page->items() as $entry) {
            $data[] = self::tender($entry, $zero);
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
