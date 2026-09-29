<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestRecipients;

use App\Entity\Preferences;

interface DigestRecipientsInterface
{
    /** @return list<Preferences> */
    public function findWithDigestEnabled(): array;
}
