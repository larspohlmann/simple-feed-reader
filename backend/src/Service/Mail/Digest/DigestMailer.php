<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends a rendered digest to its recipient. The message shape (plain text, or
 * HTML + text) is decided by DigestMailBuilder from the user's digest_format.
 */
final readonly class DigestMailer implements DigestMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private DigestMailBuilder $builder,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(User $user, DigestModel $model): void
    {
        $this->mailer->send($this->builder->build($user, $model));
    }
}
