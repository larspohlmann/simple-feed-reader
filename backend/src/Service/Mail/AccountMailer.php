<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dto\Mail\PendingApprovalNotice;
use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Settings\PublicBaseUrl;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The account emails the application sends. Plain text on purpose: the API
 * renders no HTML anywhere else, and plain bodies survive every client. Subject
 * and body are translated into the recipient's own language (User::$locale).
 */
final readonly class AccountMailer implements AccountMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private MailSettings $mailSettings,
        private PublicBaseUrl $publicBaseUrl,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendVerification(User $user, string $plainToken): void
    {
        $this->send($user, 'verify', ['%link%' => $this->link('/verify-email', $plainToken)]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendApproved(User $user): void
    {
        $this->send($user, 'approved', ['%url%' => $this->publicBaseUrl->get()]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $this->send($user, 'reset', ['%link%' => $this->link('/reset-password', $plainToken)]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void
    {
        $this->send($admin, 'admin_pending_approval', [
            '%applicant_email%' => $notice->applicantEmail,
            '%method%' => $this->methodLabel($notice, $admin->getLocale()),
            '%pending_count%' => (string) $notice->pendingApprovalCount,
            '%review_url%' => $notice->reviewUrl,
        ]);
    }

    private function link(string $path, string $plainToken): string
    {
        return $this->publicBaseUrl->get() . $path . '?token=' . rawurlencode($plainToken);
    }

    private function methodLabel(PendingApprovalNotice $notice, string $locale): string
    {
        if (RegistrationMethod::OAuth === $notice->method) {
            \assert(null !== $notice->oauthProvider);

            return ucfirst($notice->oauthProvider);
        }

        return $this->translator->trans('admin_pending_approval.method_email_password', [], 'emails', $locale);
    }

    /**
     * @param array<string, string> $params
     * @throws TransportExceptionInterface
     */
    private function send(User $user, string $key, array $params): void
    {
        $locale = $user->getLocale();
        $subject = $this->translator->trans("$key.subject", [], 'emails', $locale);
        $body = $this->translator->trans("$key.body", $params, 'emails', $locale);
        $identity = $this->mailSettings->identity();

        $this->mailer->send(
            // Parenthesised for PDepend 2.16.2 (composer md), which cannot parse
            // the PHP 8.4 "new without parentheses" chain yet — keep the parens.
            // See #183.
            (new Email())
                ->from(new Address($identity->address, $identity->name))
                ->to($user->getEmail())
                ->subject($subject)
                ->text($body),
        );
    }
}
