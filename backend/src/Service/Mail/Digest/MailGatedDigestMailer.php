<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * On a mailless instance (issue #230) a digest send is a no-op that leaves a
 * log line, instead of a send that silently succeeds into null://null. The
 * log line is what makes "no digest went out" visible to the operator rather
 * than a mystery. Decorates DigestMailer so no send site has to know whether
 * mail is on (#636), mirroring MailGatedAccountMailer.
 */
#[AsDecorator(decorates: DigestMailer::class)]
final readonly class MailGatedDigestMailer implements DigestMailerInterface
{
    public function __construct(
        private DigestMailerInterface $inner,
        private MailCapability $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function send(User $user, DigestModel $model): void
    {
        if (!$this->mail->isEnabled()) {
            $this->logger->info('Mail disabled; skipped digest mail to {email}.', [
                'email' => $user->getEmail(),
            ]);

            return;
        }

        $this->inner->send($user, $model);
    }
}
