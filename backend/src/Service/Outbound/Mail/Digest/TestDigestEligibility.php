<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Mail\MailCapability;

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
