<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The only three emails the application sends. Plain text on purpose: the API
 * renders no HTML anywhere else, and plain bodies survive every client. Subject
 * and body are translated into the recipient's own language (User::$locale).
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function sendVerification(User $user, string $plainToken): void
    {
        $this->send($user, 'verify', ['%link%' => $this->link('/verify-email', $plainToken)]);
    }

    public function sendApproved(User $user): void
    {
        $this->send($user, 'approved', ['%url%' => rtrim($this->frontendUrl, '/')]);
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $this->send($user, 'reset', ['%link%' => $this->link('/reset-password', $plainToken)]);
    }

    private function link(string $path, string $plainToken): string
    {
        return rtrim($this->frontendUrl, '/') . $path . '?token=' . rawurlencode($plainToken);
    }

    /** @param array<string, string> $params */
    private function send(User $user, string $key, array $params): void
    {
        $locale = $user->getLocale();
        $subject = $this->translator->trans("$key.subject", [], 'emails', $locale);
        $body = $this->translator->trans("$key.body", $params, 'emails', $locale);

        $this->mailer->send(
            (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject($subject)
                ->text($body),
        );
    }
}
