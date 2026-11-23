<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

/**
 * Command service for lead write operations.
 *
 * Final class implementing LeadCommandServiceContract.
 * Constructor injection only; no business logic at scaffold time.
 * @implements \Core\Lead\Application\Contracts\LeadCommandServiceContract
 */
final class LeadCommandService
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
     * @var \Core\Shared\Domain\Exceptions\Result
     */
    private $result;

    /**
     * @var \Core\Shared\Infrastructure\EventDispatcherContract
     */
    private $eventDispatcher;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Core\Shared\Infrastructure\TransactionManagerContract
     */
    private $transactionManager;

    /**
     * LeadCommandService constructor.
     *
     * @param \Core\Lead\Domain\Contracts\LeadRepositoryContract $repository
     * @param \Core\Lead\Application\Mappers\LeadResponseMapper $responseMapper
     * @param \Core\Shared\Domain\Exceptions\Result $result
     * @param \Core\Shared\Infrastructure\EventDispatcherContract $eventDispatcher
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Core\Shared\Infrastructure\TransactionManagerContract $transactionManager
     */
    public function __construct(
        \Core\Lead\Domain\Contracts\LeadRepositoryContract $repository,
        \Core\Lead\Application\Mappers\LeadResponseMapper $responseMapper,
        \Core\Shared\Domain\Exceptions\Result $result,
        \Core\Shared\Infrastructure\EventDispatcherContract $eventDispatcher,
        \Psr\Log\LoggerInterface $logger,
        \Core\Shared\Infrastructure\TransactionManagerContract $transactionManager,
    ) {
        $this->repository = $repository;
        $this->responseMapper = $responseMapper;
        $this->result = $result;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->transactionManager = $transactionManager;
    }
}