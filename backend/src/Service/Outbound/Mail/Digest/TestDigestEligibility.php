<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest;

use App\Entity\User;
use App\Service\Outbound\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Outbound\Mail\MailCapability;

/** A preview digest goes only where a real one could: mail on for this instance, and a verified address. */
final readonly class TestDigestEligibility
{
    public function __construct(private MailCapability $mail)
    {
    }

    public function assertEligible(User $user): void
    {
        if (!$this->mail->isEnabled() || !$user->isEmailVerified()) {
            throw new TestDigestUnavailableException();
        }
    }
}
