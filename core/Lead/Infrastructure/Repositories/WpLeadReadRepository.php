<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

/**
 * WordPress read repository for leads.
 *
 * Final class extending BaseReadRepository and implementing LeadQueryContract.
 * No write methods - the CQRS boundary holds by inheritance.
 *
 * @implements \Core\Lead\Application\Contracts\LeadQueryContract
 */
final class WpLeadReadRepository extends \Core\Shared\Infrastructure\BaseReadRepository
{
    /**
     * Private constant for the table suffix.
     */
    private const TABLE_SUFFIX = 'lead';

    /**
     * WpLeadReadRepository constructor.
     *
     * @param \Core\Lead\Infrastructure\Mappers\LeadEntityMapper $entityMapper
     * @param ?\wpdb $wpdb
     */
    public function __construct(\Core\Lead\Infrastructure\Mappers\LeadEntityMapper $entityMapper, ?\wpdb $wpdb = null)
    {
        parent::__construct($entityMapper, $wpdb);
    }
}