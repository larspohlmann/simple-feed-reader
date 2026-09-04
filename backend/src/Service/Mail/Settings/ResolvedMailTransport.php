<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;

/** A fully resolved SMTP transport, plaintext password included. Never serialised. */
final readonly class ResolvedMailTransport
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public MailEncryption $encryption,
    ) {
    }

    /** Stable across sends with the same settings, so the transport is built once. */
    public function signature(): string
    {
        return implode('|', [
            $this->host,
            (string) $this->port,
            $this->username ?? '',
            $this->encryption->value,
            null === $this->password ? 'no-pass' : 'has-pass',
        ]);
    }
}
