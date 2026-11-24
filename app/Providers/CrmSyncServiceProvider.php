<?php

declare(strict_types=1);

namespace App\Providers;

use Core\CrmSync\Application\Contracts\CrmMappingQueryContract;
use Core\CrmSync\Application\Contracts\CrmContactGatewayContract;
use Core\CrmSync\Application\Contracts\DeadLetterQueryContract;
use Core\CrmSync\Application\Contracts\SyncCommandServiceContract;
use Core\CrmSync\Application\Contracts\SyncEventQueryContract;
use Core\CrmSync\Application\Contracts\SyncMapperContract;
use Core\CrmSync\Application\Contracts\SyncQueryServiceContract;
use Core\CrmSync\Application\Contracts\WebhookSignatureVerifierContract;
use Core\CrmSync\Application\Mappers\SyncMapper;
use Core\CrmSync\Application\Mappers\SyncResponseMapper;
use Core\CrmSync\Application\Services\SyncCommandService;
use Core\CrmSync\Application\Services\SyncQueryService;
use Core\CrmSync\Domain\Contracts\CrmMappingRepositoryContract;
use Core\CrmSync\Domain\Contracts\DeadLetterRepositoryContract;
use Core\CrmSync\Domain\Contracts\SyncEventRepositoryContract;
use Core\CrmSync\Infrastructure\Adapters\HubSpotAdapter;
use Core\CrmSync\Infrastructure\Adapters\HubSpotProviderRegistry;
use Core\CrmSync\Infrastructure\Adapters\HubSpotSignatureVerifier;
use Core\CrmSync\Infrastructure\Adapters\LeadCaptureOutboxAdapter;
use Core\CrmSync\Infrastructure\Adapters\LeadMappingRelinkAdapter;
use Core\CrmSync\Infrastructure\Mappers\CrmMappingEntityMapper;
use Core\CrmSync\Infrastructure\Mappers\DeadLetterEntityMapper;
use Core\CrmSync\Infrastructure\Mappers\SyncEventEntityMapper;
use Core\CrmSync\Infrastructure\Repositories\WpCrmMappingReadRepository;
use Core\CrmSync\Infrastructure\Repositories\WpCrmMappingWriteRepository;
use Core\CrmSync\Infrastructure\Repositories\WpDeadLetterReadRepository;
use Core\CrmSync\Infrastructure\Repositories\WpDeadLetterWriteRepository;
use Core\CrmSync\Infrastructure\Repositories\WpSyncEventReadRepository;
use Core\CrmSync\Infrastructure\Repositories\WpSyncEventWriteRepository;
use Core\Lead\Application\Contracts\LeadCaptureOutboxContract;
use Core\Lead\Application\Contracts\LeadMappingRelinkContract;
use Core\Shared\Domain\Contracts\ClockContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Container bindings for the CRM sync module.
 *
 * Every binding the module owns lives here rather than in
 * {@see AppServiceProvider}, which holds shared-kernel bindings only. Each
 * contract has exactly one implementation: the `Wp*` classes under
 * `core/CrmSync/Infrastructure/Repositories/`, which use `$wpdb`.
 *
 * This provider also binds the lead module's two cross-module ports
 * (`LeadCaptureOutboxContract`, `LeadMappingRelinkContract`), because their
 * adapters live in `core/CrmSync/Infrastructure/Adapters/` — the lead module
 * declares what it needs and never names a CrmSync class.
 */
final class CrmSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerInfrastructure();
        $this->registerCrossModulePorts();
        $this->registerProviderAdapters();
        $this->registerServices();
    }

    /**
     * The first release's CRM provider adapters (DP-8: HubSpot only).
     *
     * The gateway and the verifier are bound to their contracts so the worker and
     * the webhook controller type-hint the ports rather than these classes. Both
     * take their secrets as scalars by contextual binding (D2, D8): Core and
     * Infrastructure never receive the `Config` object, and a token must not be
     * passed through a class that could serialise it.
     *
     * `HubSpotSignatureVerifier` is given the exact URI HubSpot signed. The
     * signature covers the URL as the provider saw it, so this must match what
     * the request actually carried — a reverse proxy or a differing scheme makes
     * it differ, and the admin URL is WordPress's own idea of this site's address
     * rather than the request's. `rest_url()` is used so the value is at least
     * the canonical REST address rather than an arbitrary guess.
     */
    private function registerProviderAdapters(): void
    {
        $this->container->bind(
            CrmContactGatewayContract::class,
            fn (): CrmContactGatewayContract => new HubSpotAdapter(
                accessToken: (string) config('app.crm.hubspot.access_token', ''),
                baseUrl: (string) config('app.crm.hubspot.base_url', 'https://api.hubapi.com'),
                logger: $this->container->resolve(LoggerInterface::class),
            )
        );

        $this->container->bind(
            WebhookSignatureVerifierContract::class,
            fn (): WebhookSignatureVerifierContract => new HubSpotSignatureVerifier(
                clientSecret: (string) config('app.crm.hubspot.client_secret', ''),
                requestUri: (string) rest_url('leadbridge/v1/webhooks/hubspot'),
                logger: $this->container->resolve(LoggerInterface::class),
            )
        );
    }

    /**
     * The module's adapters and mappers.
     */
    private function registerInfrastructure(): void
    {
        // The entity mappers are stateless and hold no handle, so one instance
        // of each serves every repository and every request.
        $this->container->singleton(
            SyncEventEntityMapper::class,
            static fn (): SyncEventEntityMapper => new SyncEventEntityMapper()
        );

        $this->container->singleton(
            CrmMappingEntityMapper::class,
            static fn (): CrmMappingEntityMapper => new CrmMappingEntityMapper()
        );

        $this->container->singleton(
            DeadLetterEntityMapper::class,
            static fn (): DeadLetterEntityMapper => new DeadLetterEntityMapper()
        );

        // Bound by concrete class so each mapper arrives by injection. The $wpdb
        // handle is not passed: every repository resolves the global lazily on
        // first use, which is what lets these bindings be registered before
        // WordPress has loaded the database.
        $this->container->bind(
            WpSyncEventWriteRepository::class,
            fn ($container): WpSyncEventWriteRepository => new WpSyncEventWriteRepository(
                $container->resolve(SyncEventEntityMapper::class)
            )
        );

        $this->container->bind(
            WpSyncEventReadRepository::class,
            fn ($container): WpSyncEventReadRepository => new WpSyncEventReadRepository(
                $container->resolve(SyncEventEntityMapper::class)
            )
        );

        $this->container->bind(
            WpCrmMappingWriteRepository::class,
            fn ($container): WpCrmMappingWriteRepository => new WpCrmMappingWriteRepository(
                $container->resolve(CrmMappingEntityMapper::class)
            )
        );

        $this->container->bind(
            WpCrmMappingReadRepository::class,
            fn ($container): WpCrmMappingReadRepository => new WpCrmMappingReadRepository(
                $container->resolve(CrmMappingEntityMapper::class)
            )
        );

        $this->container->bind(
            WpDeadLetterWriteRepository::class,
            fn ($container): WpDeadLetterWriteRepository => new WpDeadLetterWriteRepository(
                $container->resolve(DeadLetterEntityMapper::class)
            )
        );

        $this->container->bind(
            WpDeadLetterReadRepository::class,
            fn ($container): WpDeadLetterReadRepository => new WpDeadLetterReadRepository(
                $container->resolve(DeadLetterEntityMapper::class)
            )
        );

        // Bound by interface as well, because the services type-hint the
        // contracts rather than the concrete classes. The write and read
        // adapters of each table are bound separately: they implement
        // different contracts and neither knows about the other.
        $this->container->bind(
            SyncEventRepositoryContract::class,
            fn ($container): SyncEventRepositoryContract => $container->resolve(
                WpSyncEventWriteRepository::class
            )
        );

        $this->container->bind(
            SyncEventQueryContract::class,
            fn ($container): SyncEventQueryContract => $container->resolve(
                WpSyncEventReadRepository::class
            )
        );

        $this->container->bind(
            CrmMappingRepositoryContract::class,
            fn ($container): CrmMappingRepositoryContract => $container->resolve(
                WpCrmMappingWriteRepository::class
            )
        );

        $this->container->bind(
            CrmMappingQueryContract::class,
            fn ($container): CrmMappingQueryContract => $container->resolve(
                WpCrmMappingReadRepository::class
            )
        );

        $this->container->bind(
            DeadLetterRepositoryContract::class,
            fn ($container): DeadLetterRepositoryContract => $container->resolve(
                WpDeadLetterWriteRepository::class
            )
        );

        $this->container->bind(
            DeadLetterQueryContract::class,
            fn ($container): DeadLetterQueryContract => $container->resolve(
                WpDeadLetterReadRepository::class
            )
        );
    }

    /**
     * The lead module's cross-module ports, implemented under this module.
     *
     * Declared by `Core\Lead\Application\Contracts\*` and implemented here, so
     * the lead module never names a CrmSync class. Bound by contract because
     * `LeadCommandService` type-hints the ports, never these adapters.
     */
    private function registerCrossModulePorts(): void
    {
        $this->container->bind(
            LeadCaptureOutboxContract::class,
            fn ($container): LeadCaptureOutboxContract => new LeadCaptureOutboxAdapter(
                $container->resolve(SyncEventRepositoryContract::class),
                $container->resolve(ClockContract::class)
            )
        );

        $this->container->bind(
            LeadMappingRelinkContract::class,
            fn ($container): LeadMappingRelinkContract => new LeadMappingRelinkAdapter(
                $container->resolve(CrmMappingRepositoryContract::class),
                $container->resolve(CrmMappingQueryContract::class),
                $container->resolve(ClockContract::class),
                $container->resolve(LoggerInterface::class)
            )
        );
    }

    /**
     * The module's mappers and services, bound by their inbound contracts.
     */
    private function registerServices(): void
    {
        // Both mappers are stateless, so a shared instance is safe.
        $this->container->singleton(
            SyncResponseMapper::class,
            static fn (): SyncResponseMapper => new SyncResponseMapper()
        );

        $this->container->singleton(
            SyncMapperContract::class,
            static fn (): SyncMapperContract => new SyncMapper()
        );

        $this->container->singleton(
            HubSpotProviderRegistry::class,
            fn ($container): HubSpotProviderRegistry => new HubSpotProviderRegistry(
                $container->resolve(CrmContactGatewayContract::class),
                $container->resolve(WebhookSignatureVerifierContract::class),
            )
        );

        $this->container->bind(
            \Core\CrmSync\Application\Contracts\CrmProviderRegistryContract::class,
            fn ($container): \Core\CrmSync\Application\Contracts\CrmProviderRegistryContract => $container->resolve(HubSpotProviderRegistry::class)
        );

        $this->container->bind(
            SyncCommandServiceContract::class,
            fn ($container): SyncCommandServiceContract => new SyncCommandService(
                $container->resolve(SyncEventRepositoryContract::class),
                $container->resolve(SyncEventQueryContract::class),
                $container->resolve(CrmMappingRepositoryContract::class),
                $container->resolve(CrmMappingQueryContract::class),
                $container->resolve(DeadLetterRepositoryContract::class),
                $container->resolve(\Core\Lead\Application\Contracts\LeadInboundEnrichmentContract::class),
                $container->resolve(\Core\CrmSync\Application\Contracts\CrmProviderRegistryContract::class),
                $container->resolve(TransactionManagerContract::class),
                $container->resolve(ClockContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\UuidGeneratorContract::class),
                $container->resolve(TranslatorContract::class),
                $container->resolve(LoggerInterface::class)
            )
        );

        $this->container->bind(
            SyncQueryServiceContract::class,
            fn ($container): SyncQueryServiceContract => new SyncQueryService(
                $container->resolve(SyncEventQueryContract::class),
                $container->resolve(DeadLetterQueryContract::class),
                $container->resolve(SyncMapperContract::class),
                $container->resolve(SyncResponseMapper::class),
                $container->resolve(TranslatorContract::class),
                $container->resolve(LoggerInterface::class)
            )
        );
    }
}
