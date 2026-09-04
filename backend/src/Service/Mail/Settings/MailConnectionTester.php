<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\User;
use App\Service\Mail\Settings\Crypto\Exception\MailPasswordUnreadableException;
use App\Service\Mail\Transport\EsmtpTransportBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a real message through the SAVED SMTP config (independent of the enable
 * switch), synchronously, so the admin can verify before turning mail on. The
 * recipient is the acting admin's own address: the instance cannot be driven to
 * mail an arbitrary target.
 */
final readonly class MailConnectionTester
{
    public function __construct(
        private MailSettings $settings,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function test(): MailTestResult
    {
        try {
            $resolved = $this->settings->configuredTransport();
        } catch (MailPasswordUnreadableException $e) {
            return MailTestResult::failed($e->getMessage());
        }

        $recipient = $this->actingAdminEmail();

        if (null === $resolved || null === $recipient) {
            return MailTestResult::failed('not_configured');
        }

        $identity = $this->settings->identity();

        try {
            $mailer = new Mailer(EsmtpTransportBuilder::from($resolved, null, $this->logger));
            $mailer->send(
                (new Email())
                    ->from(new Address($identity->address, $identity->name))
                    ->to($recipient)
                    ->subject('Simple Feed Reader test message')
                    ->text('This confirms the outgoing mail configuration works.'),
            );
        } catch (TransportExceptionInterface $e) {
            return MailTestResult::failed($e->getMessage());
        }

        return MailTestResult::ok();
    }

    private function actingAdminEmail(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getEmail() : null;
    }
}
