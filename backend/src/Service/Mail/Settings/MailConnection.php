<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/**
 * The non-secret mail connection fields, carried as one value so the entity
 * mutators and the service take a single argument. The sealed password travels
 * separately (it may be absent on an update).
 */
final readonly class MailConnection
{
    /** The SMTP submission port with STARTTLS. The one definition the entity
     *  default, the request DTO and the "not configured yet" payload read. */
    public const int DEFAULT_PORT = 587;

    public function __construct(
        public bool $enabled,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
    ) {
    }
}
