<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as Router;
use Liberu\Ecommerce\MultiTenderPayments\Api\MultiTenderPaymentsApiServiceProvider as Api;

/*
 * The published OpenAPI 3.1 fragment, checked against the router in **both**
 * directions.
 *
 * One direction alone is half a test. Documenting an operation nobody can call
 * is a lie to a client generator; shipping a route nobody documented is a
 * surface no reviewer will ever look at. Both are asserted here, and every
 * operation is checked for the metadata a caller actually needs: a stable id,
 * its scope, its errors, its examples, and — for the two operations that change
 * anything — its idempotency contract.
 */

/** @return array<string, mixed> */
function openApiDocument(): array
{
    return Api::openApi();
}

/** @return array<string, Route> the package's routes, keyed by name */
function apiRoutes(): array
{
    $routes = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, Api::CONFIG.'.')) {
            $routes[$name] = $route;
        }
    }

    return $routes;
}

/** @return list<array{name: string, method: string, path: string, operation: array<string, mixed>}> */
function apiOperations(): array
{
    $found = [];
    $document = openApiDocument();

    /** @var string $server */
    $server = $document['servers'][0]['url'];

    foreach ($document['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            $found[] = [
                'name' => (string) $operation['x-route-name'],
                'method' => mb_strtoupper($method),
                'path' => ltrim($server.$path, '/'),
                'operation' => $operation,
            ];
        }
    }

    return $found;
}

it('documents every route the package registers', function () {
    $documented = array_column(apiOperations(), 'name');

    foreach (array_keys(apiRoutes()) as $name) {
        expect($documented)->toContain($name);
    }
});

it('registers every operation the document claims, at the same path and method', function () {
    $routes = apiRoutes();

    foreach (apiOperations() as $operation) {
        expect($routes)->toHaveKey($operation['name']);
        expect($routes[$operation['name']]->uri())->toBe($operation['path']);
        expect($routes[$operation['name']]->methods())->toContain($operation['method']);
    }
});

it('counts the same number of operations as routes', function () {
    expect(count(apiOperations()))->toBe(count(apiRoutes()))->and(count(apiOperations()))->toBe(5);
});

it('serves the prefix the configuration declares', function () {
    expect(openApiDocument()['servers'][0]['url'])->toBe('/'.Config::get(Api::CONFIG.'.prefix'));
});

it('gives every operation a stable, unique identifier', function () {
    $ids = array_map(static fn (array $o): string => (string) $o['operation']['operationId'], apiOperations());

    expect($ids)->toBe(array_unique($ids));

    foreach ($ids as $id) {
        expect($id)->toMatch('/^[a-z][A-Za-z]+$/');
    }
});

it('declares the scope each operation needs, and the scope the middleware enforces', function () {
    /** @var array<string, string> $scopes */
    $scopes = (array) Config::get(Api::CONFIG.'.scopes');

    foreach (apiOperations() as $operation) {
        $declared = $operation['operation']['security'][0]['apiToken'];
        $expected = $operation['method'] === 'GET' ? $scopes['read'] : $scopes['write'];

        expect($declared)->toBe([$expected]);
    }
});

it('names both scopes in the security scheme', function () {
    $scheme = openApiDocument()['components']['securitySchemes']['apiToken']['flows']['clientCredentials']['scopes'];

    foreach ((array) Config::get(Api::CONFIG.'.scopes') as $scope) {
        expect($scheme)->toHaveKey($scope);
    }
});

it('requires an idempotency key on exactly the operations that change something', function () {
    $stateChanging = [Api::CONFIG.'.plans.tenders.store', Api::CONFIG.'.plans.tenders.reversals.store'];

    foreach (apiOperations() as $operation) {
        $expected = in_array($operation['name'], $stateChanging, true) ? 'required' : 'none';

        expect($operation['operation']['x-idempotency-key'])->toBe($expected);

        $parameters = array_column($operation['operation']['parameters'] ?? [], '$ref');

        expect(in_array('#/components/parameters/IdempotencyKey', $parameters, true))->toBe($expected === 'required');
    }
});

it('documents the transient claim with its Retry-After header', function () {
    $response = openApiDocument()['components']['responses']['ClaimInFlight'];

    expect($response['headers'])->toHaveKey('Retry-After')
        ->and($response['headers']['Retry-After']['required'])->toBeTrue();
});

it('documents the errors every operation can actually produce', function () {
    foreach (apiOperations() as $operation) {
        foreach (['401', '403', '422', '503'] as $status) {
            expect($operation['operation']['responses'])->toHaveKey($status);
        }
    }
});

it('documents the two idempotency failures as different statuses on the operations that can raise them', function () {
    foreach (apiOperations() as $operation) {
        if ($operation['operation']['x-idempotency-key'] !== 'required') {
            continue;
        }

        expect($operation['operation']['responses'])->toHaveKey('409');
    }

    // Only recording a tender takes the domain's lock, so only it can be
    // in flight. A 423 documented anywhere else would be a promise nothing
    // keeps.
    $record = current(array_filter(apiOperations(), static fn (array $o): bool => $o['operation']['operationId'] === 'recordTender'));

    expect($record['operation']['responses'])->toHaveKey('423');
});

it('gives every operation at least one example', function () {
    foreach (apiOperations() as $operation) {
        $rendered = json_encode($operation['operation']);

        expect($rendered)->toContain('"example"');
    }
});

it('documents pagination on the operation that pages', function () {
    $list = current(array_filter(apiOperations(), static fn (array $o): bool => $o['operation']['operationId'] === 'listPlanTenders'));
    $parameters = array_column($list['operation']['parameters'], '$ref');

    expect($parameters)->toContain('#/components/parameters/Page');
    expect($parameters)->toContain('#/components/parameters/PerPage');
    expect(openApiDocument()['components']['schemas']['PageMeta']['required'])->toBe(['page', 'per_page', 'total', 'last_page']);
});

it('caps per_page at the figure the configuration caps it at', function () {
    expect(openApiDocument()['components']['parameters']['PerPage']['schema']['maximum'])
        ->toBe((int) Config::get(Api::CONFIG.'.pagination.max_per_page'))
        ->and(openApiDocument()['components']['parameters']['PerPage']['schema']['default'])
        ->toBe((int) Config::get(Api::CONFIG.'.pagination.per_page'));
});

it('declares the settled money envelope and nothing looser', function () {
    $money = openApiDocument()['components']['schemas']['Money'];

    expect($money['required'])->toBe(['minor', 'currency', 'exponent', 'decimal'])
        ->and($money['additionalProperties'])->toBeFalse()
        ->and($money['properties']['minor']['type'])->toBe('integer')
        ->and($money['properties']['decimal']['type'])->toBe('string');
});

it('describes no money value anywhere as a number', function () {
    // `19.99` in JSON is a double in every client, and a double is the thing
    // wave 3 removed from this fleet. A schema that says `number` invites one
    // back in through a generated client.
    foreach (leaves(openApiDocument()) as $path => $value) {
        if (preg_match('/(^|\.)type(\.\d+)?$/', $path) === 1) {
            expect($value)->not->toBe('number');
        }
    }
});

it('carries every decimal it documents as a string', function () {
    foreach (leaves(openApiDocument()) as $path => $value) {
        if (str_ends_with($path, '.decimal')) {
            expect($value)->toBeString();
        }
    }
});

it('never names a total, a balance or a capacity in anything a caller sends', function () {
    $names = [];

    foreach (openApiDocument()['components']['parameters'] as $parameter) {
        $names[] = (string) $parameter['name'];
    }

    foreach (['AdmitTenderPlanRequest', 'RecordTenderRequest', 'ReverseTenderRequest', 'PlannedTender'] as $schema) {
        foreach (array_keys(openApiDocument()['components']['schemas'][$schema]['properties']) as $property) {
            $names[] = (string) $property;
        }
    }

    foreach ($names as $name) {
        expect($name)->not->toMatch('/total|balance|capacit|payable|outstanding/i');
    }
});
