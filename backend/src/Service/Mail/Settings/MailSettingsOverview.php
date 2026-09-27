<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Service\Proxy\ProxyConnection;

final readonly class MailSettingsOverview
{
    public function __construct(
        public ?MailSettingsSnapshot $saved,
        public MailConnection $fallback,
        public ?ProxyConnection $proxy,
    ) {
    }
}
