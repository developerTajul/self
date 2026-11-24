<?php

declare(strict_types=1);

namespace Core\CrmSync\Domain\Enums;

/**
 * The CRM providers this release can talk to.
 */
enum CrmProviderName: string
{
    case hubspot = 'hubspot';

    /**
     * The translation key for this provider. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'crm_sync.provider.' . $this->value;
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
