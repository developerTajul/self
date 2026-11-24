<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Enums;

/**
 * The local entity a mapping starts from.
 */
enum LocalEntityType: string
{
    case lead = 'lead';
    case order = 'order';

    /**
     * The translation key for this entity type. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'crm_sync.entity_type.' . $this->value;
    }

    /**
     * The raw values the storage column accepts.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
