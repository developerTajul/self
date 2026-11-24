<?php
declare(strict_types=1);

namespace App\Providers;

use Core\Shared\Domain\Contracts\ClockContract;
use Core\Shared\Domain\Contracts\EventDispatcherContract;
use Core\Shared\Domain\Contracts\UuidGeneratorContract;
use Core\Shared\Infrastructure\Events\WpEventDispatcher;
use Core\Shared\Infrastructure\Utilities\WpClock;
use Core\Shared\Infrastructure\Utilities\WpUuidGenerator;
use DevLinkStudios\WPServiceContainer\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Identifiers are generated outside the Domain and passed in, so the
        // generator is a Shared port rather than a helper an Entity may call.
        $this->container->bind(
            UuidGeneratorContract::class,
            static fn (): UuidGeneratorContract => new WpUuidGenerator()
        );

        // The clock is injected for the same reason: a behaviour method is given
        // the instant, so no Entity reads the time itself.
        $this->container->bind(
            ClockContract::class,
            static fn (): ClockContract => new WpClock()
        );

        // Domain events leave Core through this port only. Singleton, because it
        // carries the action prefix and holds no per-request state.
        $this->container->singleton(
            EventDispatcherContract::class,
            static fn (): EventDispatcherContract => new WpEventDispatcher(
                (string) config('app.prefix', '')
            )
        );
    }

    public function boot(): void
    {
        // add_action('init', [$this, 'someMethod']);
    }
}
