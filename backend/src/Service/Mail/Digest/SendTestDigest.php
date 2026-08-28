<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Psr\Clock\ClockInterface;

/**
 * The "send me a test digest" action (#636): compose over the last N days and
 * send immediately, WITHOUT advancing digestLastSentAt — a preview, not a real
 * send. Returns whether there was anything to send.
 */
final readonly class SendTestDigest
{
    public function __construct(
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    public function send(User $user, int $days): bool
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', $days));
        $model = $this->composer->compose($user, $since);
        if (null === $model) {
            return false;
        }

        $this->mailer->send($user, $model);

        return true;
    }
}
