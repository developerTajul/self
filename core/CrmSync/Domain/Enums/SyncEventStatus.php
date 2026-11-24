<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Enums;

/**
 * Where a sync event stands in its retry lifecycle.
 */
enum SyncEventStatus: string
{
    case pending = 'pending';
    case processing = 'processing';
    case succeeded = 'succeeded';
    case dead = 'dead';

    /**
     * The translation key for this state. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'crm_sync.status.' . $this->value;
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
