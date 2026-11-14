<?php

declare(strict_types=1);

namespace App\Service\Outbound\Mail\Digest;

use App\Entity\User;
use App\Repository\DigestSettingsRepository;
use App\Service\Outbound\Mail\Digest\DigestMailer\DigestMailerInterface;
use Psr\Clock\ClockInterface;

/**
 * The "send me a test digest" action: composes over the last N days and sends at once, but never advances
 * the digest settings' last-sent time, because it is a preview, not a real send.
 */
final readonly class SendTestDigest
{
    public function __construct(
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private DigestSettingsRepository $digestSettings,
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

        $this->mailer->send($this->digestSettings->forUser($user), $model);

        return true;
    }
}
