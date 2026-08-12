<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Http;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Plans\PlannedTender;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * Everything this package will accept from a caller, in one readable place.
 *
 * The rules are here rather than in three form-request classes so that the
 * whole accepted surface can be *enumerated* — `tests/Feature/InputWalkTest.php`
 * reads {@see self::ALL} and asserts that no operation anywhere declares a key
 * matching a total, a balance or a capacity. A rule list spread across form
 * requests can only be checked by remembering to look.
 *
 * What is deliberately absent:
 *
 * - **a total.** Resolved through `ResolvesPayableTotal`, server-side, always.
 * - **a balance.** A fold over the ledger, computed on every read.
 * - **a capacity.** Resolved through `ResolvesTenderCapacity`, per tender kind.
 * - **a currency.** It has one origin: the resolved payable total. A caller
 *   naming a currency could make a plan disagree with its order, and
 *   {@see self::plannedTenders()} builds every amount from the server's Money.
 * - **a tenant id.** Derived from the actor, never from a body.
 *
 * What a caller *does* declare is what it is offering: a kind, and either an
 * amount in minor units or a relative share. That is not one of the three
 * figures above — it is the offer the module measures against them, and the
 * module refuses it outright if the tenders exceed the total.
 */
final class Payload
{
    /** Every operation with an accepted body or query, by route-name suffix. */
    public const ALL = ['plans.tenders.index', 'plans.admissions.store', 'plans.tenders.store', 'plans.tenders.reversals.store'];

    /**
     * The validation rules for one operation.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $operation): array
    {
        return match ($operation) {
            'plans.tenders.index' => [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            ],
            'plans.admissions.store' => self::tenderRules(),
            'plans.tenders.store' => [
                ...self::tenderRules(),
                'position' => ['required', 'integer', 'min:0'],
                // A reversal is recorded against the entry it reverses, through
                // its own operation. The domain refuses it here too.
                'effect' => ['sometimes', 'string', Rule::in(['captured', 'declined'])],
                'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'plans.tenders.reversals.store' => [
                'reason' => ['required', 'string', 'min:1', 'max:255'],
            ],
            default => [],
        };
    }

    /**
     * The tenders a caller is offering, turned into the domain's own value
     * objects.
     *
     * Every amount is built with `$payable->withMinor()`, so the currency and
     * the exponent come from the total the host resolved and from nowhere else.
     * That is what makes "all tenders share the order's currency" true here by
     * construction rather than by validation.
     *
     * @param  list<array<string, mixed>>  $tenders
     * @return list<PlannedTender>
     */
    public static function plannedTenders(array $tenders, Money $payable): array
    {
        $planned = [];

        foreach ($tenders as $index => $tender) {
            $amount = $tender['amount_minor'] ?? null;
            $share = $tender['share'] ?? null;

            if ($amount !== null && $share !== null) {
                throw ApiRefusal::tenderDeclarationAmbiguous($index);
            }

            $kind = TenderKind::from((string) $tender['kind']);
            $reference = self::string($tender['reference'] ?? null);
            $instalment = self::string($tender['instalment_reference'] ?? null);

            $planned[] = $share !== null
                ? PlannedTender::share($kind, (int) $share, $reference, $instalment)
                : PlannedTender::of($kind, $payable->withMinor((int) $amount), $reference, $instalment);
        }

        return $planned;
    }

    public static function maxPerPage(): int
    {
        return (int) Config::get(Api::CONFIG.'.pagination.max_per_page', 100);
    }

    public static function perPage(): int
    {
        return (int) Config::get(Api::CONFIG.'.pagination.per_page', 25);
    }

    /** @return array<string, list<mixed>> */
    private static function tenderRules(): array
    {
        return [
            'tenders' => ['required', 'array', 'min:1', 'max:20'],
            'tenders.*.kind' => ['required', 'string', Rule::in(array_column(TenderKind::cases(), 'value'))],
            'tenders.*.amount_minor' => ['required_without:tenders.*.share', 'nullable', 'integer', 'min:0'],
            'tenders.*.share' => ['required_without:tenders.*.amount_minor', 'nullable', 'integer', 'min:0'],
            'tenders.*.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tenders.*.instalment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
