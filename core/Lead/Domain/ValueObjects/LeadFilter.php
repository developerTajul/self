<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ValueObjects;

use Core\Lead\Domain\Enums\LeadSource;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadFilterException;
use Core\Lead\Domain\Rules\LeadRules;

/**
 * Filter criteria the lead list is read with.
 *
 * Validates itself, so the criteria reaching Infrastructure are always usable:
 * a page size outside its bounds is refused here rather than turned into an
 * unbounded read, and the status and source are Domain enums rather than raw
 * strings, so the adapter can bind their values straight to the indexed columns.
 */
final readonly class LeadFilter
{
    /**
     * The page to read, 1-based.
     *
     * Clamped rather than rejected: a page below the first is someone editing a
     * URL, and the ceiling cannot be settled here at all because it depends on
     * how many rows match, which only the count read knows.
     */
    public int $page;

    /**
     * Free text matched against name and email; blank reads as absent.
     *
     * Assigned in the constructor rather than promoted, because normalizing a
     * promoted readonly property by reassigning it is a fatal error.
     */
    public ?string $search;

    /**
     * @param int               $perPage
     * @param int               $page     1-based; anything lower is read as the first page.
     * @param LeadStatus|null   $status   Narrows the list to one status.
     * @param LeadSource|null   $source   Narrows the list to one source.
     * @param string|null       $search   Free text matched against name and email.
     *
     * @throws InvalidLeadFilterException When the page size falls outside its bounds.
     */
    public function __construct(
        public int $perPage = LeadRules::DEFAULT_PER_PAGE,
        int $page = LeadRules::MIN_PAGE,
        public ?LeadStatus $status = null,
        public ?LeadSource $source = null,
        ?string $search = null,
    ) {
        // A blank search is absence, not a term that matches everything: leaving
        // it as '' would put a `LIKE '%%'` on the query that scans every row to
        // filter nothing.
        $trimmed = $search === null ? null : trim($search);

        $this->search = $trimmed === '' ? null : $trimmed;

        if ($perPage < LeadRules::MIN_PER_PAGE || $perPage > LeadRules::MAX_PER_PAGE) {
            throw InvalidLeadFilterException::invalidPerPage($perPage);
        }

        $this->page = max(LeadRules::MIN_PAGE, $page);
    }

    /**
     * Whether any narrowing criterion is set.
     *
     * @return bool
     */
    public function hasCriteria(): bool
    {
        return $this->status !== null
            || $this->source !== null
            || $this->search !== null;
    }
}
