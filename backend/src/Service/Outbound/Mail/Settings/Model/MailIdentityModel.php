<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Settings\Model;

final readonly class MailIdentityModel
{
    public function __construct(
        public string $address,
        public string $name,
    ) {
    }
}
