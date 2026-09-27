<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailEncryption;

/**
 * The non-secret mail fields. The sealed password travels separately, because an update may leave it out. The env
 * fallback uses the same shape, with host '' when its DSN is not SMTP (sendmail, null).
 */
final readonly class MailConnection
{
    /** SMTP submission with STARTTLS: a fresh row's port, and the env fallback's when its DSN names none. */
    public const int DEFAULT_PORT = 587;

    public function __construct(
        public bool $enabled,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
        public bool $useProxy = false,
    ) {
    }
}
