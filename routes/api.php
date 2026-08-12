<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Controllers\PlanController;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Controllers\TenderController;
use Liberu\Ecommerce\MultiTenderPayments\Api\Http\Middleware\RequiresScope;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;

/*
 * Five operations, and not one of them accepts a total, a balance or a
 * capacity — in its body or in a header. Those three figures are resolved
 * server-side through the domain's two contracts, and
 * tests/Feature/InputWalkTest.php walks every route proving it.
 *
 * The operation ids in resources/openapi/multi-tender-payments-api.json are the
 * route names below without their prefix, and a parity test asserts that in
 * both directions: no route without an operation, no operation without a route.
 */

/** @var array<string, mixed> $config */
$config = (array) Config::get(Api::CONFIG, []);

$read = (array) ($config['middleware']['read'] ?? []);
$write = (array) ($config['middleware']['write'] ?? []);

Route::middleware([...$read, RequiresScope::class.':read'])->group(function (): void {
    Route::get('plans/{order}', [PlanController::class, 'show'])->name('plans.show');
    Route::get('plans/{order}/tenders', [PlanController::class, 'tenders'])->name('plans.tenders.index');
});

Route::middleware([...$write, RequiresScope::class.':write'])->group(function (): void {
    // Pure arithmetic. It stores nothing, moves nothing and therefore takes no
    // idempotency key — there is nothing for a repeat to duplicate.
    Route::post('plans/{order}/admissions', [TenderController::class, 'admit'])->name('plans.admissions.store');

    Route::post('plans/{order}/tenders', [TenderController::class, 'record'])->name('plans.tenders.store');
    Route::post('plans/{order}/tenders/{tender}/reversals', [TenderController::class, 'reverse'])
        ->whereNumber('tender')
        ->name('plans.tenders.reversals.store');
});
