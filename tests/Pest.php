<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Liberu\Ecommerce\MultiTenderPayments\Api\Tests\Fixtures\FakeHost;
use Liberu\Ecommerce\MultiTenderPayments\Api\Tests\Fixtures\TokenUser;
use Liberu\Ecommerce\MultiTenderPayments\Api\Tests\TestCase;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesPayableTotal;
use Liberu\Ecommerce\MultiTenderPayments\Contracts\ResolvesTenderCapacity;

// One test case per folder, bound from the start rather than retrofitted.
uses(TestCase::class)->in('Feature', 'Unit');

/** The default prefix, so a test names an operation the way a caller reaches it. */
function api(string $path = ''): string
{
    return '/api/v1/ecommerce/multi-tender-payments'.$path;
}

/**
 * Bind the two contracts the domain publishes and refuses to implement.
 *
 * A test that does not call this is testing a half-configured deployment, and
 * this package renders that as 503.
 */
function bindHost(int $total = 16_000, string $order = 'order-9f2c', string $currency = 'GBP', int $exponent = 2): FakeHost
{
    $host = new FakeHost();
    $host->total($order, $total, $currency, $exponent);

    app()->instance(ResolvesPayableTotal::class, $host);
    app()->instance(ResolvesTenderCapacity::class, $host);

    return $host;
}

/** An authenticated actor carrying the scopes named. */
function actingWithScopes(string ...$scopes): TokenUser
{
    $user = new TokenUser();
    $user->abilities = array_values($scopes);

    test()->actingAs($user);

    return $user;
}

/** The usual actor: authenticated, and able to do everything this API offers. */
function actingWithEveryScope(): TokenUser
{
    return actingWithScopes('ecommerce:multi-tender-payments:read', 'ecommerce:multi-tender-payments:write');
}

/** Hold the domain's idempotency claim, so the next attempt under it is in flight. */
function holdClaim(string $key): void
{
    app()->make(LockProvider::class)->lock('multi-tender-payments:idempotency:'.$key, 10)->get();
}

/**
 * Every PHP file this package ships, for the boundary rules to read.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Every money envelope in a decoded response body, keyed by its dotted path.
 *
 * Found by shape rather than by name, so a money value rendered under a key
 * nobody thought of is still walked.
 *
 * @param  array<array-key, mixed>  $data
 * @return array<string, array<string, mixed>>
 */
function moneyValues(array $data, string $prefix = ''): array
{
    $found = [];

    foreach ($data as $key => $value) {
        if (! is_array($value)) {
            continue;
        }

        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (array_keys($value) === ['minor', 'currency', 'exponent', 'decimal']) {
            $found[$path] = $value;

            continue;
        }

        $found += moneyValues($value, $path);
    }

    return $found;
}

/**
 * Every leaf value in a decoded response body, keyed by its dotted path.
 *
 * The exposure walks — the one over what comes out and the one over what goes
 * in — both need to look at every value rather than at the ones a reader
 * remembered to check.
 *
 * @param  array<array-key, mixed>  $data
 * @return array<string, mixed>
 */
function leaves(array $data, string $prefix = ''): array
{
    $found = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $found += leaves($value, $path);

            continue;
        }

        $found[$path] = $value;
    }

    return $found;
}
