<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyResponseDTO;
use Framework\Support\JsonResource;

/**
 * Public shape of a company.
 *
 * Fields are named one at a time, so a column added to the table stays out of
 * responses until somebody decides it belongs. That is the whole reason this
 * class exists rather than serializing the DTO: the DTO is the application's
 * idea of a company, and the API's idea of one is a separate, deliberate
 * statement.
 *
 * One resource serves both the list and the detail read. The two responses are
 * the same object with the same fields filled in; a second resource class for
 * the profile would be a second place for the shared fields to drift.
 *
 * Wire casing is `snake_case` throughout, matching the DTO properties: a field
 * that reaches the client unconverted keeps its own name, and a payload that
 * mixed the two casings would make a client carry a map from key to key.
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    protected function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'website'    => $this->website,
            'industry'   => $this->industry,
            'phone'      => $this->phone,
            'address'    => $this->address,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
