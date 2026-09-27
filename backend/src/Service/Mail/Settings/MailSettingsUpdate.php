<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Service\Crypto\SecretChange;

final readonly class MailSettingsUpdate
{
    public function __construct(
        public MailConnection $connection,
        public SecretChange $password,
    ) {
    }
}
