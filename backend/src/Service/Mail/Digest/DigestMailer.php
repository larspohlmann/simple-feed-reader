<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a rendered digest to its recipient. Plain text on purpose, matching
 * AccountMailer: the API renders no HTML anywhere else, and plain bodies
 * survive every client. Rendered per User::$locale (#636).
 */
final readonly class DigestMailer implements DigestMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private DigestTextRenderer $renderer,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(User $user, DigestModel $model): void
    {
        $mail = $this->renderer->render($model, $user->getLocale());

        $this->mailer->send(
            (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject($mail->subject)
                ->text($mail->body),
        );
    }
}
