<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Liberu\Ecommerce\MultiTenderPayments\Actions\OpenPaymentPlan;
use Liberu\Ecommerce\MultiTenderPayments\Api\Exceptions\ApiRefusal;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Middleware\RequiresScope;
use Liberu\Ecommerce\MultiTenderPayments\Api\Support\Problem;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Exceptions\PayableTotalUnknown;
use Liberu\Ecommerce\MultiTenderPayments\Support\Money;
use Throwable;

/**
 * Where a domain refusal becomes a status code.
 *
 * **In `callAction()`, and not in middleware.** `Illuminate\Routing\Pipeline`
 * catches a throwable raised inside a route, hands it to the application's
 * exception handler and returns the rendered response *before* the surrounding
 * middleware resumes — so a middleware wrapped around the route never sees the
 * exception at all. A mapper written as middleware works in a unit test and
 * silently does nothing in an application. This is the seam that actually runs.
 *
 * Two other things happen here, both before the action:
 *
 * 1. **The unbound check.** A published contract with no binding is a
 *    deployment fault, not a request fault, and it is a 503. It is checked with
 *    `bound()` rather than by catching whatever the container throws, so the
 *    503 is a decision this package made rather than a message it recognised.
 * 2. **Nothing else.** There is no business authorisation here. That belongs to
 *    the domain, and at 0.1.0 the domain publishes no policy — so this package
 *    delegates none and, more importantly, invents none. Scopes are enforced in
 *    {@see RequiresScope}
 *    and are a property of the token, not of the plan.
 */
abstract class Controller extends BaseController
{
    /**
     * The published contracts this controller's actions cannot work without.
     *
     * Declared per controller rather than globally, because the failure
     * directions differ: reading a plan needs a payable total to fold against,
     * and only the operations that admit tenders need a capacity resolver.
     *
     * @return list<class-string>
     */
    protected function requiredResolvers(): array
    {
        return [ResolvesPayableTotal::class];
    }

    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     * @return mixed
     */
    public function callAction($method, $parameters)
    {
        try {
            foreach ($this->requiredResolvers() as $contract) {
                if (! Container::getInstance()->bound($contract)) {
                    throw ApiRefusal::resolverUnbound($contract);
                }
            }

            return parent::callAction($method, $parameters);
        } catch (Throwable $e) {
            $problem = Problem::for($e);

            if ($problem === null) {
                throw $e;
            }

            return $problem->toResponse();
        }
    }

    /**
     * Resolve something out of the container, keeping its type.
     *
     * @template TClass of object
     *
     * @param  class-string<TClass>  $abstract
     * @return TClass
     */
    protected function make(string $abstract): object
    {
        /** @var TClass */
        return Container::getInstance()->make($abstract);
    }

    /**
     * What the order costs, answered by the host.
     *
     * The controller needs this before it can read a caller's minor units at
     * all, because the currency and the exponent those units are denominated in
     * come from here and from nowhere else. A null answer for an order is
     * `PayableTotalUnknown` — a 422 about this one order, and a different thing
     * entirely from the 503 the unbound check above raises.
     */
    protected function payableTotal(string $order): Money
    {
        return $this->make(ResolvesPayableTotal::class)->payableTotalFor($order)
            ?? throw new PayableTotalUnknown("No payable total is known for order [{$order}].");
    }

    /**
     * The plan an order's tenders hang off.
     *
     * `OpenPaymentPlan` is the domain's only way in, and it is `firstOrCreate`.
     * A read therefore materialises the plan row if it is not there yet — see
     * `docs/domain.md` for why that is harmless and why it is a decision rather
     * than an accident.
     *
     * The return type is left to inference on purpose: naming it would mean
     * importing a domain model, which is exactly what an `-api` adapter must
     * not do and what the boundary suite greps for.
     */
    protected function plan(string $order)
    {
        return $this->make(OpenPaymentPlan::class)($order);
    }

    /**
     * Every state-changing operation carries one, so a repeat is a repeat.
     *
     * The bound is the ledger's own column width. A key longer than the column
     * would fail as a database error rather than as an answer.
     */
    protected function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || mb_strlen($key) > 255) {
            throw ApiRefusal::idempotencyKeyRequired();
        }

        return $key;
    }
}
