<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Model;

use App\Entity\MailConnection;
use App\Entity\ProxyConnection;

final readonly class MailSettingsOverviewModel
{
    public function __construct(
        public ?MailSettingsSnapshotModel $saved,
        public MailConnection $fallback,
        public ?ProxyConnection $proxy,
    ) {
    }
}
