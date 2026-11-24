<?php

declare(strict_types=1);

namespace App\Providers;

use App\Cache\WpCacheStore;
use App\Database\AtomicDatabaseRateLimiter;
use App\Database\WpTransactionManager;
use Core\Shared\Domain\Contracts\CacheInterface;
use Core\Shared\Domain\Contracts\RateLimiterContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\Shared\Infrastructure\Localization\WpTranslator;
use Core\Shared\Infrastructure\Logging\WpLogger;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Config\Config;
use Psr\Log\LoggerInterface;

/**
 * Container bindings for the shared kernel's adapters.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance so the nesting depth counter is shared. A fresh
        // instance per resolve would treat each nested call as the outermost
        // transaction and issue START TRANSACTION inside the outer one.
        $this->container->singleton(
            TransactionManagerContract::class,
            static fn (): WpTransactionManager => new WpTransactionManager()
        );

        // Rate limiter adapter. Non‑shared, matching the repositories: it
        // holds a $wpdb handle and nothing else, so a per-request binding
        // avoids capturing one request's handle in a long-lived instance.
        $this->container->bind(
            RateLimiterContract::class,
            static fn (): AtomicDatabaseRateLimiter => new AtomicDatabaseRateLimiter()
        );

        $this->registerTranslator();
        $this->registerLogger();
        $this->registerCache();
    }

    /**
     * Bind the translator the Core services read wording through.
     *
     * `lang/` sits at the plugin root, so the path is the plugin directory
     * rather than the application's. The framework's `base_path()` helper is
     * not used because it may not be defined while providers register.
     */
    private function registerTranslator(): void
    {
        $this->container->singleton(
            TranslatorContract::class,
            static fn (): TranslatorContract => new WpTranslator(
                dirname(__DIR__, 2) . '/lang'
            )
        );
    }

    /**
     * Bind the PSR-3 logger Core services report failures through.
     *
     * The threshold comes from config rather than `env()`, because environment
     * variables are read once at config load and nowhere else.
     */
    private function registerLogger(): void
    {
        $this->container->singleton(
            LoggerInterface::class,
            static fn (): LoggerInterface => new WpLogger(
                (string) config('app.log_level', 'error')
            )
        );
    }

    /**
     * Bind the shared cache store.
     */
    private function registerCache(): void
    {
        // Reads app.prefix via config() because the framework requires
        // configuration to be read through Config and passed in. Falls back
        // to the framework's DEFAULT_PREFIX because WpCacheStore refuses an
        // empty prefix; passing '' would turn "config not yet loaded" into a
        // hard failure at resolve time.
        $this->container->bind(
            CacheInterface::class,
            static fn (): CacheInterface => new WpCacheStore(
                (string) config('app.prefix', Config::DEFAULT_PREFIX)
            )
        );
    }
}
