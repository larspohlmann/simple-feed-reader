<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Service\Fetch\ProxyConfig;

final readonly class MailSettingsOverview
{
    public function __construct(
        public ?MailServerSettings $saved,
        public MailConnection $fallback,
        public ?ProxyConfig $proxy,
    ) {
    }
}
