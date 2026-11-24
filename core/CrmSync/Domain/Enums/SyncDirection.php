<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Enums;

/**
 * Which way a sync event travels.
 */
enum SyncDirection: string
{
    case outbound = 'outbound';
    case inbound = 'inbound';

    /**
     * The translation key for this direction. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'crm_sync.direction.' . $this->value;
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
