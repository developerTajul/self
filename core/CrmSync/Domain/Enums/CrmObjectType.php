<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Enums;

/**
 * The remote object a mapping points at.
 */
enum CrmObjectType: string
{
    case contact = 'contact';
    case deal = 'deal';

    /**
     * The translation key for this object type. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'crm_sync.object_type.' . $this->value;
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
