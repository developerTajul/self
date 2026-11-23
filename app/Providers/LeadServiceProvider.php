<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Lead\Application\Contracts\LeadCommandServiceContract;
use Core\Lead\Application\Contracts\LeadMapperContract;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\Contracts\LeadQueryServiceContract;
use Core\Lead\Application\Contracts\LeadResponseMapperContract;
use Core\Lead\Application\Mappers\LeadResponseMapper;
use Core\Lead\Application\Services\LeadCommandService;
use Core\Lead\Application\Services\LeadQueryService;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use Core\Lead\Infrastructure\Repositories\WpLeadReadRepository;
use Core\Lead\Infrastructure\Repositories\WpLeadWriteRepository;
use DevLinkStudios\WPServiceContainer\ServiceProvider;

/**
 * Container bindings for the lead module.
 *
 * Every binding the module owns lives here rather than in
 * {@see AppServiceProvider}, which holds shared-kernel bindings only. Each
 * contract has exactly one implementation: the `Wp*` classes under
 * `core/Lead/Infrastructure/Repositories/`, which use `$wpdb`.
 */
final class LeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerInfrastructure();
        $this->registerServices();
    }

    /**
     * The module's adapters and mappers.
     */
    private function registerInfrastructure(): void
    {
        // The entity mapper is stateless and holds no handle, so one instance
        // serves every repository and every request.
        $this->container->singleton(
            LeadEntityMapper::class,
            static fn (): LeadEntityMapper => new LeadEntityMapper()
        );

        // Bound by concrete class so the mapper arrives by injection. The $wpdb
        // handle is not passed: both repositories resolve the global lazily on
        // first use, which is what lets these bindings be registered before
        // WordPress has loaded the database.
        $this->container->bind(
            WpLeadWriteRepository::class,
            fn ($container): WpLeadWriteRepository => new WpLeadWriteRepository(
                $container->resolve(LeadEntityMapper::class)
            )
        );

        // The read repository is bound separately from the write one: the two
        // implement different contracts and neither knows about the other.
        $this->container->bind(
            WpLeadReadRepository::class,
            fn ($container): WpLeadReadRepository => new WpLeadReadRepository(
                $container->resolve(LeadEntityMapper::class)
            )
        );

        // Bound by interface as well, because the services type-hint the
        // contracts rather than the concrete classes.
        $this->container->bind(
            LeadRepositoryContract::class,
            fn ($container): LeadRepositoryContract => $container->resolve(
                WpLeadWriteRepository::class
            )
        );

        $this->container->bind(
            LeadQueryContract::class,
            fn ($container): LeadQueryContract => $container->resolve(
                WpLeadReadRepository::class
            )
        );
    }

    /**
     * The module's mappers and services, bound by their inbound contracts.
     */
    private function registerServices(): void
    {
        // The DTO-to-entity mapper and the entity-to-response mapper are both
        // stateless, so a shared instance is safe.
        $this->container->singleton(
            LeadMapperContract::class,
            static fn (): LeadMapperContract => new LeadResponseMapper()
        );

        $this->container->singleton(
            LeadResponseMapperContract::class,
            static fn (): LeadResponseMapperContract => new LeadResponseMapper()
        );

        $this->container->bind(
            LeadCommandServiceContract::class,
            fn ($container): LeadCommandServiceContract => new LeadCommandService(
                $container->resolve(LeadRepositoryContract::class),
                $container->resolve(LeadResponseMapperContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\TransactionManagerContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\TranslatorContract::class),
                $container->resolve(\Psr\Log\LoggerInterface::class),
                $container->resolve(\Core\Shared\Infrastructure\TransactionManagerContract::class)
            )
        );

        $this->container->bind(
            LeadQueryServiceContract::class,
            fn ($container): LeadQueryServiceContract => new LeadQueryService(
                $container->resolve(LeadQueryContract::class),
                $container->resolve(LeadResponseMapperContract::class),
                $container->resolve(\Psr\Log\LoggerInterface::class)
            )
        );
    }
}