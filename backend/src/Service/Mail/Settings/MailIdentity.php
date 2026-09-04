<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailIdentity
{
    public function __construct(
        public string $address,
        public string $name,
    ) {
    }
}
