<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestMailer;

use App\Entity\DigestSettings;
use App\Service\Mail\Digest\Factory\DigestMailFactory;
use App\Service\Mail\Digest\Model\DigestModel;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends a rendered digest to its recipient. The message shape (plain text, or
 * HTML + text) is decided by DigestMailFactory from the user's digest format.
 */
final readonly class DigestMailer implements DigestMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private DigestMailFactory $mailFactory,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(DigestSettings $settings, DigestModel $model): void
    {
        $this->mailer->send($this->mailFactory->build($settings, $model));
    }
}
