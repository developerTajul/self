<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Enums;

/**
 * Where a lead was ingested from.
 */
enum LeadSource: string
{
    case contact_form_7 = 'contact_form_7';
    case woocommerce = 'woocommerce';
    case hubspot = 'hubspot';
    case manual = 'manual';

    /**
     * The translation key for this source. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'lead.source.' . $this->value;
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
