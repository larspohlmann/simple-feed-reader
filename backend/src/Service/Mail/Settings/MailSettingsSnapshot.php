<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;

final readonly class MailSettingsSnapshot
{
    public function __construct(
        public MailConnection $connection,
        public bool $hasPassword,
    ) {
    }

    public static function fromEntity(MailServerSettings $settings): self
    {
        return new self($settings->connection(), $settings->hasPassword());
    }
}
