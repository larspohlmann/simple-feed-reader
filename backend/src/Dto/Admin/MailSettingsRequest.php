<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailConnection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Full-replace payload for the mail settings. The password is a three-state
 * intent: null keeps the stored secret, a string replaces it, and
 * `removePassword` clears it. Both are inbound-only, never echoed back.
 */
final readonly class MailSettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled = false,
        #[Assert\Length(max: 255)]
        public string $host = '',
        #[Assert\Range(min: 1, max: 65535)]
        public int $port = MailConnection::DEFAULT_PORT,
        #[Assert\Length(max: 255)]
        public ?string $username = null,
        #[Assert\Choice(choices: [
            MailEncryption::None->value,
            MailEncryption::Starttls->value,
            MailEncryption::Tls->value,
        ])]
        public string $encryption = MailEncryption::Starttls->value,
        #[Assert\Length(max: 255)]
        #[Assert\Email]
        public string $fromAddress = '',
        #[Assert\Length(max: 255)]
        public string $fromName = '',
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }
}
