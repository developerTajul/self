<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Utilities;

use Core\Shared\Domain\Contracts\UuidGeneratorContract;

/**
 * UUIDs from WordPress's own version-4 generator.
 *
 * @implements UuidGeneratorContract
 */
final class WpUuidGenerator implements UuidGeneratorContract
{
    /**
     * Returns a version-4 UUID in canonical 36-character form.
     *
     * @return string
     */
    public function generate(): string
    {
        return wp_generate_uuid4();
    }
}
