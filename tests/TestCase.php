<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\PackageTestbench\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;

    /**
     * Overriding this means calling the parent, or the application key the
     * testbench sets is lost and anything rendering dies on it instead of on
     * whatever the test was about.
     *
     * The array cache store is chosen because the domain's idempotency claim
     * takes a lock through `LockProvider`, and the array store is the one every
     * environment has. It is also what lets a test hold the claim itself and
     * watch this package render 423.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }
}
