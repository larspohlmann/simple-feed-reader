<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest\DigestRecipients;

use App\Entity\DigestSettings;

interface DigestRecipientsInterface
{
    /** @return list<DigestSettings> */
    public function findWithDigestEnabled(): array;
}
