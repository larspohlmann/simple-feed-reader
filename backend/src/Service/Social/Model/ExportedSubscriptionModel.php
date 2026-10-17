<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\Subscription;

final readonly class ExportedSubscriptionModel
{
    public function __construct(
        public Subscription $subscription,
        public string $feedUrl,
        public string $feedSourceFormat,
    ) {
    }
}
