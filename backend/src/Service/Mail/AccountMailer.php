<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The only three emails the application sends. Plain text on purpose: the API
 * renders no HTML anywhere else, and plain bodies survive every client.
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
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
        $link = $this->link('/verify-email', $plainToken);

        $this->send(
            $user,
            'Confirm your email address',
            <<<TEXT
                Welcome to Simple Feed Reader.

                Confirm your email address by opening this link:

                {$link}

                The link is valid for 24 hours. After confirming, an administrator
                reviews your account before you can sign in.

                If you did not sign up, ignore this message — the account is
                deleted automatically within 48 hours.
                TEXT,
        );
    }

    public function sendApproved(User $user): void
    {
        $url = rtrim($this->frontendUrl, '/');

        $this->send(
            $user,
            'Your account has been approved',
            <<<TEXT
                Your Simple Feed Reader account has been approved.

                You can now sign in at {$url}
                TEXT,
        );
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $link = $this->link('/reset-password', $plainToken);

        $this->send(
            $user,
            'Reset your password',
            <<<TEXT
                Someone requested a password reset for your Simple Feed Reader
                account.

                Choose a new password here:

                {$link}

                The link is valid for 24 hours and can be used once. If you did
                not request this, ignore this message — your password is unchanged.
                TEXT,
        );
    }

    private function link(string $path, string $plainToken): string
    {
        return rtrim($this->frontendUrl, '/') . $path . '?token=' . rawurlencode($plainToken);
    }

    private function send(User $user, string $subject, string $body): void
    {
        $this->mailer->send(
            (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($user->getEmail())
                ->subject($subject)
                ->text($body),
        );
    }
}
