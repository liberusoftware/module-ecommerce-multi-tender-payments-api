<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;

/*
 * The wave-8 boundary, asserted by name from the presentation side.
 *
 * This package presents exactly one module. The four modules that live around
 * Multi-Tender Payments are not imported here any more than they are imported
 * there — presenting a domain must not quietly widen it.
 *
 * The fleet's shared module boundary suite runs alongside this from
 * vendor/liberusoftware/package-testbench and covers the rules every module
 * shares, including the `-api` rule that a transport must not import a domain
 * model. These are the ones specific to this package.
 */

it('imports none of the four neighbouring modules', function (string $namespace) {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toContain($namespace);
    }
})->with([
    'payment operations owns authorising and capturing' => ['Liberu\\Ecommerce\\PaymentOperations\\'],
    'gift cards and store credit owns a redeemable balance' => ['Liberu\\Ecommerce\\GiftCardsAndStoreCredit\\'],
    'refunds owns what is owed back' => ['Liberu\\Ecommerce\\Refunds\\'],
    'orders owns the order and its total' => ['Liberu\\Ecommerce\\Orders\\'],
]);

it('requires none of the four neighbouring packages', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer)->toBeArray();

    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach ([
        'liberusoftware/ecommerce-payment-operations',
        'liberusoftware/ecommerce-gift-cards-and-store-credit',
        'liberusoftware/ecommerce-refunds',
        'liberusoftware/ecommerce-orders',
    ] as $package) {
        expect($required)->not->toContain($package);
    }
});

it('presents exactly one module, and requires it', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    $siblings = array_filter(array_keys($composer['require']), static fn (string $p): bool => str_starts_with($p, 'liberusoftware/'));

    expect(array_values($siblings))->toBe(['liberusoftware/ecommerce-multi-tender-payments']);
});

it('reaches the domain through its actions, queries and contracts only', function () {
    // The shared suite greps for a Models import; this says what the package
    // does instead, so the rule reads as a design rather than as a prohibition.
    $imported = [];

    foreach (sourceFiles() as $file) {
        preg_match_all('/^use (Liberu\\\\Ecommerce\\\\MultiTenderPayments\\\\(?!Api\\\\)[^;]+);$/m', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $import) {
            $imported[] = explode('\\', (string) $import)[3];
        }
    }

    expect(array_values(array_unique($imported)))->not->toBeEmpty();

    foreach (array_unique($imported) as $segment) {
        expect(['Actions', 'Contracts', 'Enums', 'Exceptions', 'Plans', 'Queries', 'Support'])->toContain($segment);
    }
});

it('never reaches for the host application', function () {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
    }
});

it('keeps a UI framework out of a transport package', function (string $namespace) {
    foreach (sourceFiles() as $file) {
        expect(file_get_contents($file))->not->toContain($namespace);
    }
})->with([['Filament\\'], ['Livewire\\']]);

it('declares itself an adapter', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true);

    expect($manifest['category'])->toBe('adapter');
});

it('defaults every route middleware group to an array and never to null', function (string $group) {
    // A null here registers routes with no middleware at all, and reads exactly
    // like an empty array right up until a host loses its guard by writing one.
    $configured = Config::get(Api::CONFIG.'.middleware.'.$group);

    expect($configured)->toBeArray()->toBe([]);
})->with([['all'], ['read'], ['write']]);

it('binds neither published contract, so a half-configured deployment fails loudly', function (string $contract) {
    expect(app()->bound($contract))->toBeFalse();
})->with([
    ['Liberu\\Ecommerce\\MultiTenderPayments\\Contracts\\ResolvesPayableTotal'],
    ['Liberu\\Ecommerce\\MultiTenderPayments\\Contracts\\ResolvesTenderCapacity'],
]);
