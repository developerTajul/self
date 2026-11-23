<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Framework\Http\Requests\FormRequest;

/**
 * REST company update.
 *
 * A partial update has three states per optional field, and the difference is
 * carried by whether the key is present at all: an absent key leaves the
 * stored value alone, an explicit `null` clears it, and a value replaces it.
 * {@see FormRequest::validated()} walks the rule list and skips a key the
 * payload does not carry, so a sparse body arrives sparse - which is exactly
 * what {@see \Core\Company\Application\Mappers\CompanyMapper::mapToUpdateDTO()}
 * reads to tell "unchanged" from "clear".
 *
 * `name` is deliberately not clearable: a company is identified by its name,
 * and the entity has no clear flag for it. An update that omits `name` keeps
 * the stored one.
 *
 * `id` is not a rule here. It is a route placeholder, and a rule naming it
 * would put the record's address into the body's reach - letting a client
 * name one company in the URL and another in the payload.
 */
final class UpdateCompanyRequest extends FormRequest
{
    protected function authorize(): bool|string
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'name'     => 'sometimes|string|max:255',
            'website'  => 'nullable|url:http,https',
            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:50',
            'address'  => 'nullable|string',
        ];
    }
}
