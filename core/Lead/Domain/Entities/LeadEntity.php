<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Entities;

use Core\Lead\Domain\Enums\LeadSource;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\LeadContactKeyMissingException;
use Core\Lead\Domain\ValueObjects\EmailAddress;
use Core\Lead\Domain\ValueObjects\PhoneNumber;
use DateTimeImmutable;

/**
 * Domain entity representing a lead.
 *
 * Every behaviour method returns a NEW instance: the entity is immutable, so a
 * caller never sees a half-applied change and a rollback needs no undo. Time
 * and the uuid arrive as arguments, because the entity asks the outside world
 * for nothing.
 *
 * The contact fields are held as the canonical strings the columns store, not
 * as the Value Objects that validate them: the Value Objects are the boundary
 * that guarantees normalization, and what survives into the entity is the
 * normalized text. That keeps the entity and the EntityMapper in agreement
 * about what a field holds.
 */
readonly class LeadEntity
{
    /**
     * @param int|null               $id
     * @param string                 $uuid           The non-sequential public identifier sent to CRMs.
     * @param string|null            $name
     * @param string|null            $email          Normalized (trimmed, lowercased) or null.
     * @param string|null            $phone          Normalized to E.164 or null.
     * @param string|null            $companyName
     * @param string|null            $websiteUrl
     * @param LeadStatus             $status
     * @param LeadSource             $source
     * @param array<string, mixed>   $meta           Source-specific attributes.
     * @param int|null               $assignedTo     WordPress user id, or null.
     * @param DateTimeImmutable|null $createdAt      UTC.
     * @param DateTimeImmutable|null $updatedAt      UTC.
     */
    public function __construct(
        public ?int $id,
        public string $uuid,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public ?string $websiteUrl,
        public LeadStatus $status,
        public LeadSource $source,
        public array $meta = [],
        public ?int $assignedTo = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * Captures a brand-new lead from one submission.
     *
     * The rule the whole module rests on (A2): a lead must carry at least one
     * contact key, because that key — and nothing else — is what makes it
     * possible to recognise the same person arriving twice. A lead with neither
     * could be stored but never deduplicated, so it is refused at the door
     * rather than allowed to become a duplicate later.
     *
     * @param string|null            $name
     * @param string|null            $companyName
     * @param string|null            $websiteUrl
     * @param array<string, mixed>   $meta          Source-specific attributes.
     * @param int|null               $assignedTo    WordPress user id, or null.
     *
     * @return self A new lead in the `new` state, not yet persisted.
     *
     * @throws LeadContactKeyMissingException When both contact keys are absent.
     */
    public static function capture(
        string $uuid,
        ?EmailAddress $email,
        ?PhoneNumber $phone,
        ?string $name,
        ?string $companyName,
        ?string $websiteUrl,
        LeadSource $source,
        array $meta,
        DateTimeImmutable $now,
        ?int $assignedTo = null,
    ): self {
        if ($email === null && $phone === null) {
            throw LeadContactKeyMissingException::forCapture();
        }

        return new self(
            id: null,
            uuid: $uuid,
            name: $name,
            email: $email?->value,
            phone: $phone?->value,
            companyName: $companyName,
            websiteUrl: $websiteUrl,
            status: LeadStatus::new,
            source: $source,
            meta: $meta,
            assignedTo: $assignedTo,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Fills this lead's blanks from a later submission about the same person.
     *
     * Non-destructive on purpose: a field the lead already carries wins, because
     * the value already stored was the one a human or a CRM may have corrected,
     * and a second webhook repeating an old value must not undo that. Only
     * blanks are filled. The contact keys are the one exception worth naming —
     * they are compared by the caller before enriching, so an incoming key that
     * disagrees with a stored one is a merge decision (see {@see absorb()}),
     * never something this method silently overwrites.
     *
     * @param string|null          $name
     * @param string|null          $companyName
     * @param string|null          $websiteUrl
     * @param array<string, mixed> $meta      Merged into the existing map; incoming keys win.
     *
     * @return self A new instance; the receiver is unchanged.
     */
    public function enrichWith(
        ?EmailAddress $email,
        ?PhoneNumber $phone,
        ?string $name,
        ?string $companyName,
        ?string $websiteUrl,
        array $meta,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: $this->id,
            uuid: $this->uuid,
            name: $this->name ?? $name,
            email: $this->email ?? $email?->value,
            phone: $this->phone ?? $phone?->value,
            companyName: $this->companyName ?? $companyName,
            websiteUrl: $this->websiteUrl ?? $websiteUrl,
            status: $this->status,
            source: $this->source,
            meta: $meta === [] ? $this->meta : [...$this->meta, ...$meta],
            assignedTo: $this->assignedTo,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );
    }

    /**
     * Absorbs a duplicate lead into this one, which survives.
     *
     * The merge the capture use case reaches when two submissions resolve to
     * different leads that are the same person (S5): this lead is retained —
     * its id, uuid, status and source all stand, because they are what the CRM
     * and any existing links already reference — and only its blanks are filled
     * from the secondary. The secondary's values never displace a stored one,
     * so a merge cannot lose data that the survivor already holds.
     *
     * The caller deletes the secondary row and re-points its CRM mappings after
     * this returns; nothing here touches storage.
     *
     * @param self $secondary The duplicate to fold in. Left unchanged.
     *
     * @return self A new instance carrying the survivor's identity.
     */
    public function absorb(self $secondary, DateTimeImmutable $now): self
    {
        return new self(
            id: $this->id,
            uuid: $this->uuid,
            name: $this->name ?? $secondary->name,
            email: $this->email ?? $secondary->email,
            phone: $this->phone ?? $secondary->phone,
            companyName: $this->companyName ?? $secondary->companyName,
            websiteUrl: $this->websiteUrl ?? $secondary->websiteUrl,
            status: $this->status,
            source: $this->source,
            meta: [...$this->meta, ...$secondary->meta],
            assignedTo: $this->assignedTo ?? $secondary->assignedTo,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );
    }

    /**
     * Whether this lead carries a usable contact key.
     *
     * The invariant {@see capture()} enforces, exposed so a caller can ask the
     * question without duplicating the rule.
     *
     * @return bool
     */
    public function hasContactKey(): bool
    {
        return $this->email !== null || $this->phone !== null;
    }
}
