<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Framework\Http\Requests\FormRequest;

/**
 * REST company creation.
 *
 * Holds only the rules. A rejected submission answers as a `WP_Error` because
 * a REST dispatch is marked as such by the adapter, so the client receives
 * WordPress's own 422 with the per-field error map rather than a redirect.
 *
 * The rule set is the API's contract for a company, so it mirrors the column
 * limits the entity enforces: a value that passes here must not then be
 * rejected by the domain, or a client sees a rule it could have obeyed
 * reported as a system failure.
 */
final class StoreCompanyRequest extends FormRequest
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
            'name'     => 'required|string|max:255',
            // The scheme list is spelled out: bare `url` also accepts
            // `ftp://` and `mailto:`, which the entity refuses, so a payload
            // relying on the default would validate and then fail the whole
            // write at the domain boundary.
            'website'  => 'nullable|url:http,https',
            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:50',
            'address'  => 'nullable|string',
        ];
    }
}
