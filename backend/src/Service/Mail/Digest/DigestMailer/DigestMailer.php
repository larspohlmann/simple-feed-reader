<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestMailer;

use App\Entity\User;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\Factory\DigestMailFactory;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends a rendered digest to its recipient. The message shape (plain text, or
 * HTML + text) is decided by DigestMailFactory from the user's digest_format.
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
    public function send(User $user, DigestModel $model): void
    {
        $this->mailer->send($this->mailFactory->build($user, $model));
    }
}
