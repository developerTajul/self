<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

/**
 * Query service for lead read operations.
 *
 * Final class implementing LeadQueryServiceContract.
 * Constructor injection only: repository contract, response mapper, logger.
 * No transaction manager, event dispatcher, slug, or upload ports.
 * @implements \Core\Lead\Application\Contracts\LeadQueryServiceContract
 */
final class LeadQueryService
{
    /**
     * @var \Core\Lead\Domain\Contracts\LeadRepositoryContract
     */
    private $repository;

    /**
     * @var \Core\Lead\Application\Mappers\LeadResponseMapper
     */
    private $responseMapper;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * LeadQueryService constructor.
     *
     * @param \Core\Lead\Domain\Contracts\LeadRepositoryContract $repository
     * @param \Core\Lead\Application\Mappers\LeadResponseMapper $responseMapper
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Core\Lead\Domain\Contracts\LeadRepositoryContract $repository,
        \Core\Lead\Application\Mappers\LeadResponseMapper $responseMapper,
        \Psr\Log\LoggerInterface $logger,
    ) {
        $this->repository = $repository;
        $this->responseMapper = $responseMapper;
        $this->logger = $logger;
    }
}