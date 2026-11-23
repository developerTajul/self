<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

/**
 * WordPress write repository for leads.
 *
 * Final class extending BaseWriteRepository and implementing LeadRepositoryContract.
 * No methods - inherits all from BaseWriteRepository.
 *
 * @implements \Core\Lead\Domain\Contracts\LeadRepositoryContract
 */
final class WpLeadWriteRepository extends \Core\Shared\Infrastructure\BaseWriteRepository
{
    /**
     * Private constant for the table suffix.
     */
    private const TABLE_SUFFIX = 'lead';

    /**
     * WpLeadWriteRepository constructor.
     *
     * @param \Core\Lead\Infrastructure\Mappers\LeadEntityMapper $entityMapper
     * @param ?\wpdb $wpdb
     */
    public function __construct(\Core\Lead\Infrastructure\Mappers\LeadEntityMapper $entityMapper, ?\wpdb $wpdb = null)
    {
        parent::__construct($entityMapper, $wpdb);
    }
}