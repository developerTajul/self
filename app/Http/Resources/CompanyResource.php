<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Core\Company\Application\DTOs\Responses\CompanyActivityResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyContactResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyDealResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyDealStageResponseDTO;
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
 * the same object with different amounts of it filled in - a list item has no
 * contacts - and {@see toArray()} emits the related collections only when the
 * DTO actually carries them. A second resource class for the profile would be
 * a second place for the shared fields to drift.
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
            // Both are absent on a list item, whose DTO has no relations to
            // carry. `null` rather than omitted would claim the company has no
            // contacts, which is a different and untrue statement.
            'contacts' => $this->whenPropertyExists('contacts', fn (array $contacts): array => $this->mapContacts($contacts)),
            'deals'    => $this->whenPropertyExists('deals', fn (array $deals): array => $this->mapDeals($deals)),
        ];
    }

    /**
     * A related collection, mapped only when the wrapped DTO actually carries
     * one.
     *
     * The whole value is built inside the callback rather than passed to
     * `when()` as an argument, because PHP evaluates every argument before the
     * call: `when($this->has('contacts'), $this->mapContacts($this->contacts))`
     * would read a missing property and throw on every list item, which is the
     * resource failing on the common case to describe the rare one.
     *
     * @param callable(mixed): mixed $mapper
     */
    private function whenPropertyExists(string $property, callable $mapper): mixed
    {
        if (!$this->has($property)) {
            return $this->when(false, null);
        }

        return $this->when(true, $mapper($this->__get($property)));
    }

    /**
     * @param array<int, CompanyContactResponseDTO> $contacts
     * @return list<array<string, mixed>>
     */
    private function mapContacts(array $contacts): array
    {
        return array_values(array_map(
            static fn (CompanyContactResponseDTO $contact): array => [
                'id'         => $contact->id,
                'name'       => $contact->name,
                'email'      => $contact->email,
                'phone'      => $contact->phone,
                'job_title'  => $contact->job_title,
                'is_primary' => $contact->is_primary,
            ],
            $contacts,
        ));
    }

    /**
     * @param array<int, CompanyDealResponseDTO> $deals
     * @return list<array<string, mixed>>
     */
    private function mapDeals(array $deals): array
    {
        return array_values(array_map(
            fn (CompanyDealResponseDTO $deal): array => [
                'id'                  => $deal->id,
                'title'               => $deal->title,
                'amount'              => $deal->amount,
                'currency'            => $deal->currency,
                'expected_close_date' => $deal->expected_close_date,
                'actual_close_date'   => $deal->actual_close_date,
                'stage'               => $deal->stage === null ? null : $this->mapStage($deal->stage),
                'activities'          => array_values(array_map(
                    static fn (CompanyActivityResponseDTO $activity): array => [
                        'id'          => $activity->id,
                        'type'        => $activity->type,
                        'subject'     => $activity->subject,
                        'description' => $activity->description,
                        'due_date'    => $activity->due_date,
                        'completed_at' => $activity->completed_at,
                        'user_name'   => $activity->user_name,
                    ],
                    $deal->activities,
                )),
            ],
            $deals,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapStage(CompanyDealStageResponseDTO $stage): array
    {
        return [
            'id'             => $stage->id,
            'name'           => $stage->name,
            'order_position' => $stage->order_position,
            'probability'    => $stage->probability,
        ];
    }
}
