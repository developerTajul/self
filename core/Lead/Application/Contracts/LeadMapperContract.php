<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadCaptureDTO;
use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;

/**
 * PORT: turns a plain submission array into a lead request DTO.
 *
 * Receives plain arrays so listeners and HTTP delivery use the same mapper.
 */
interface LeadMapperContract
{
    /**
     * Builds a capture DTO and normalizes blank contact fields to null.
     *
     * @param array<string, mixed> $input
     *
     * @return LeadCaptureDTO
     */
    public function mapToCaptureDTO(array $input): LeadCaptureDTO;

    /**
     * Builds clamped lead-list criteria from a plain request array.
     *
     * @param array<string, mixed> $input
     *
     * @return LeadFilterDTO
     */
    public function mapToFilterDTO(array $input): LeadFilterDTO;
}
