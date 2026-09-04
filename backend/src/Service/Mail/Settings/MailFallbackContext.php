<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/** The env fallback expressed as form defaults. host is '' when the fallback is
 *  not an SMTP DSN (sendmail/null), so the SMTP form starts blank there. */
final readonly class MailFallbackContext
{
    public function __construct(
        public bool $isReal,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
    ) {
    }
}
