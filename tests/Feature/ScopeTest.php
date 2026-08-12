<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;
use Liberu\PackageTestbench\TestUser;

/*
 * Token capabilities, and the fail-closed default underneath them.
 *
 * These are transport capabilities and nothing more. This package makes no
 * business authorisation decision about a plan — the domain owns that, and at
 * 0.1.0 the domain publishes no policy, so there is nothing here to delegate to
 * and nothing here to invent in its place.
 *
 * The rule that matters most is the last one: an actor that cannot answer
 * `tokenCan()` is refused. Laravel's unanswered gate case is permissive, and a
 * missing method is how three separate surfaces in this fleet were left open.
 */

it('refuses an unauthenticated caller on every operation', function (string $method, string $uri) {
    bindHost(16_000);

    $this->json($method, $uri, ['tenders' => [['kind' => 'card', 'amount_minor' => 1]], 'position' => 0, 'reason' => 'x'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
})->with([
    ['GET', api('/plans/order-9f2c')],
    ['GET', api('/plans/order-9f2c/tenders')],
    ['POST', api('/plans/order-9f2c/admissions')],
    ['POST', api('/plans/order-9f2c/tenders')],
    ['POST', api('/plans/order-9f2c/tenders/1/reversals')],
]);

it('refuses a read scope on a write operation', function () {
    actingWithScopes('ecommerce:multi-tender-payments:read');
    bindHost(16_000);

    $this->postJson(api('/plans/order-9f2c/admissions'), ['tenders' => [['kind' => 'card', 'amount_minor' => 1]]])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'missing_scope');
});

it('refuses a write scope on a read operation', function () {
    actingWithScopes('ecommerce:multi-tender-payments:write');
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c'))->assertStatus(403);
});

it('admits a caller carrying the scope the operation declares', function () {
    actingWithScopes('ecommerce:multi-tender-payments:read');
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c'))->assertOk();
});

it('refuses an actor that cannot answer tokenCan at all', function () {
    // A missing method is not a pass. An unanswered authorisation question is
    // an exposure, and failing closed is the only default that is not one.
    $this->actingAs(new TestUser());
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'missing_scope');
});

it('puts a scope check on every route the package registers', function () {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), Api::CONFIG.'.')) {
            continue;
        }

        $guarded = array_filter(
            $route->gatherMiddleware(),
            static fn (mixed $middleware): bool => is_string($middleware) && str_contains($middleware, 'RequiresScope'),
        );

        expect($guarded)->not->toBeEmpty();
    }
});

it('names the scope for a group in configuration rather than in a route file', function () {
    Config::set(Api::CONFIG.'.scopes.read', 'renamed:read');

    actingWithScopes('renamed:read');
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c'))->assertOk();
});

it('refuses rather than admits when a group has no scope configured', function () {
    Config::set(Api::CONFIG.'.scopes.read', '');

    actingWithScopes('ecommerce:multi-tender-payments:read');
    bindHost(16_000);

    $this->getJson(api('/plans/order-9f2c'))
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'scope_not_configured');
});
