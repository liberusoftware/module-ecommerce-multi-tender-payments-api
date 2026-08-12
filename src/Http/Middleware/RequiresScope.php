<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token capability an operation needs, checked with `tokenCan()`.
 *
 * Two scopes and no more: reading a plan and its ledger, and changing it. They
 * are properties of the **token**, not of the plan — this package makes no
 * business authorisation decision about whose plan it is. That belongs to the
 * domain, and at 0.1.0 the domain publishes no policy, so there is nothing here
 * to delegate to and nothing here to invent in its place.
 *
 * An actor that cannot answer `tokenCan()` is **refused**, not admitted. An
 * unanswered authorisation question is an exposure: Laravel's unanswered gate
 * case is permissive, and a missing method has been the way three separate
 * surfaces in this fleet were left open. Requiring a positive answer is the
 * only default that fails closed.
 *
 * Sanctum's `HasApiTokens` supplies `tokenCan()`, but nothing here requires
 * Sanctum: any actor exposing `tokenCan(string): bool` satisfies it, which is
 * what keeps a transport package from dictating a host's auth stack.
 */
final class RequiresScope
{
    public function handle(Request $request, Closure $next, string $group): Response
    {
        $user = $request->user();

        if ($user === null) {
            return self::refuse(401, 'unauthenticated', 'This operation requires an authenticated actor.');
        }

        $scope = (string) Config::get(Api::CONFIG.'.scopes.'.$group, '');

        if ($scope === '') {
            return self::refuse(500, 'scope_not_configured', "No scope is configured for the [{$group}] group.");
        }

        // `is_callable()` on its own is not enough, and getting that wrong is
        // how this would fail open. An Eloquent model has `__call()`, so
        // `is_callable([$model, 'anything'])` is true and invoking it raises a
        // BadMethodCallException — a 500, not a refusal. `method_exists()` asks
        // the question that was meant: is there a real method to answer with?
        $answers = [$user, 'tokenCan'];

        if (! method_exists($user, 'tokenCan') || ! is_callable($answers) || $answers($scope) !== true) {
            return self::refuse(403, 'missing_scope', "This operation requires the [{$scope}] scope.");
        }

        return $next($request);
    }

    private static function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
