<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestMailer;

use App\Entity\DigestSettings;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\MailCapability;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** On a mailless instance a digest send is a logged no-op, not a silent send into null://null, as for account mail. */
#[AsDecorator(decorates: DigestMailer::class)]
final readonly class MailGatedDigestMailer implements DigestMailerInterface
{
    public function __construct(
        private DigestMailerInterface $inner,
        private MailCapability $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function send(DigestSettings $settings, DigestModel $model): void
    {
        if (!$this->mail->isEnabled()) {
            $this->logger->info('Mail disabled; skipped digest mail to {email}.', [
                'email' => $settings->getUser()->getEmail(),
            ]);

            return;
        }

        $this->inner->send($settings, $model);
    }
}
