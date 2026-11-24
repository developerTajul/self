<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadCaptureDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles lead write use cases.
 *
 * Delivery injects this contract, never the concrete Service, so the transport
 * cannot reach past the module's declared surface. Every method returns a
 * `Result` carrying a ResponseDTO: a use case has more than one ordinary
 * outcome — a capture may create, enrich or merge — and the type says so rather
 * than hiding the alternatives in prose.
 */
interface LeadCommandServiceContract
{
    /**
     * Captures a lead submission, creating, enriching or merging as needed.
     *
     * The decision between the three is the use case's whole content: a
     * submission matching no stored lead creates one, matching exactly one
     * enriches it, and matching two that share a contact key merges them (S5).
     *
     * @param LeadCaptureDTO $leadCaptureDTO
     *
     * @return Result Carries a `LeadResponseDTO`.
     */
    public function captureLead(LeadCaptureDTO $leadCaptureDTO): Result;
}
