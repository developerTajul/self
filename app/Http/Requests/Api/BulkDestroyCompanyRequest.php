<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Framework\Http\Requests\FormRequest;

/**
 * REST bulk company deletion.
 *
 * The ids travel in the body as a JSON array, which is the one shape a REST
 * client can send and WordPress decodes for us. The rule is on `ids` itself
 * rather than on `ids.*`: the wildcard names a set of paths and not a value,
 * so a rule keyed `ids.*` never appears in {@see FormRequest::validated()}
 * while the parent `ids` key does - the array arrives whole or not at all.
 *
 * A rule on the parent is also what keeps a scalar out: `{"ids": 7}` fails the
 * `array` check rather than being silently read as a one-element list, so a
 * client that got the shape wrong is told so instead of having a deletion it
 * did not describe performed on its behalf.
 */
final class BulkDestroyCompanyRequest extends FormRequest
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
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|min:1',
        ];
    }
}
