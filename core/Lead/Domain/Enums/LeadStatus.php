<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Enums;

/**
 * The lifecycle state of a lead.
 */
enum LeadStatus: string
{
    case new = 'new';
    case contacted = 'contacted';
    case qualified = 'qualified';
    case converted = 'converted';
    case lost = 'lost';

    /**
     * The translation key for this state. Core carries keys, never wording.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return 'lead.status.' . $this->value;
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
