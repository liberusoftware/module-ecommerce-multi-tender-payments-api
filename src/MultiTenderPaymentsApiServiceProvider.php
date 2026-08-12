<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\MultiTenderPayments\Api;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the HTTP surface, and nothing else.
 *
 * Note what is **not** here. Neither `ResolvesPayableTotal` nor
 * `ResolvesTenderCapacity` is bound: this package presents the domain, it does
 * not configure it, and a default binding here would be the same defect it is
 * in the domain — a half-configured deployment quietly treating an order total
 * as zero. Unbound surfaces as 503 at the boundary, which is the whole point.
 *
 * The package also declares no `extra.laravel.providers`, so Composer
 * installing it registers nothing. The host enables the module by name.
 */
final class MultiTenderPaymentsApiServiceProvider extends ServiceProvider
{
    public const CONFIG = 'multi-tender-payments-api';

    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), self::CONFIG);
    }

    public function boot(): void
    {
        $this->publishes([self::configPath() => $this->app->configPath(self::CONFIG.'.php')], 'multi-tender-payments-api-config');

        $this->publishes([
            self::openApiPath() => $this->app->basePath('resources/openapi/'.self::CONFIG.'.json'),
        ], 'multi-tender-payments-api-openapi');

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }
    }

    /** The OpenAPI 3.1 fragment this package publishes, as a decoded array. */
    public static function openApi(): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents(self::openApiPath()), true, flags: JSON_THROW_ON_ERROR);

        return $document;
    }

    public static function openApiPath(): string
    {
        return dirname(__DIR__).'/resources/openapi/'.self::CONFIG.'.json';
    }

    private static function configPath(): string
    {
        return dirname(__DIR__).'/config/'.self::CONFIG.'.php';
    }

    private function registerRoutes(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->app->make('config')->get(self::CONFIG, []);

        Route::group([
            'prefix' => $config['prefix'] ?? '',
            'domain' => $config['domain'] ?? null,
            // Never null. A group whose middleware is null registers routes with
            // no middleware at all, which looks identical to an empty array right
            // up until a host writes `'middleware' => null` and loses its guard.
            'middleware' => (array) ($config['middleware']['all'] ?? []),
            'as' => self::CONFIG.'.',
        ], fn () => $this->loadRoutesFrom(dirname(__DIR__).'/routes/api.php'));
    }
}
