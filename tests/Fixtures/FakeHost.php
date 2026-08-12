<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Tests\Fixtures;

use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;
use Liberu\Ecommerce\MultiTenderPayments\Enums\TenderKind;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;

/**
 * What a host binds. Neither the domain nor this package ships an
 * implementation, on purpose — a default binding would let a half-configured
 * deployment quietly treat an order total as zero.
 *
 * Every money figure in every test comes from here rather than from a request
 * body, which is the point the whole package is arranged around.
 */
final class FakeHost implements ResolvesPayableTotal, ResolvesTenderCapacity
{
    /** @var array<string, Money> */
    public array $totals = [];

    /** @var array<string, Money|null> */
    public array $capacities = [];

    /** @var list<string> */
    public array $asked = [];

    public function payableTotalFor(string $orderReference): ?Money
    {
        $this->asked[] = $orderReference;

        return $this->totals[$orderReference] ?? null;
    }

    public function capacityFor(TenderKind $kind, ?string $reference): ?Money
    {
        return $this->capacities[$kind->value.':'.($reference ?? '')] ?? null;
    }

    public function total(string $orderReference, int $minor, string $currency = 'GBP', int $exponent = 2): self
    {
        $this->totals[$orderReference] = new Money($minor, $currency, $exponent);

        return $this;
    }

    /** A ceiling for one tender. Null means "no ceiling known" and is not zero. */
    public function capacity(TenderKind $kind, ?string $reference, ?Money $money): self
    {
        $this->capacities[$kind->value.':'.($reference ?? '')] = $money;

        return $this;
    }
}
