<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Liberu\PackageTestbench\TestUser;

/**
 * An actor that can answer `tokenCan()`.
 *
 * Sanctum's `HasApiTokens` is what supplies that method in an application, but
 * this package requires Sanctum nowhere: the contract the middleware enforces is
 * "the actor answers `tokenCan(string): bool`", so a host on any token stack
 * satisfies it. {@see TestUser} is used elsewhere in
 * these tests as the actor that *cannot* answer, and is refused for it.
 */
final class TokenUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    public array $abilities = [];

    public function tokenCan(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }
}
