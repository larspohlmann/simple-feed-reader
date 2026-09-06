<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailKind;
use App\Entity\User;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\MailFailureRecorder;
use App\Service\Mail\Transport\ActiveMailTransportFactory;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends a real message synchronously through the transport that would actually
 * send -- the saved row, else the env fallback when none is saved -- so the admin
 * can verify before enabling. The recipient is the acting admin's own address.
 */
final readonly class MailConnectionTester
{
    public function __construct(
        private MailSettings $settings,
        private Security $security,
        private LoggerInterface $logger,
        private ActiveMailTransportFactory $transportFactory,
        private MailFailureRecorder $health,
    ) {
    }

    public function test(): MailTestResult
    {
        try {
            $resolved = $this->settings->configuredTransport();
        } catch (SecretUnreadableException $e) {
            // A config guard, not a failed send: nothing was ever attempted,
            // so the health log stays untouched.
            return MailTestResult::failed($e->getMessage());
        }

        $transport = $this->effectiveTransport($resolved);
        $recipient = $this->actingAdminEmail();

        if (null === $transport || null === $recipient) {
            return MailTestResult::failed('not_configured');
        }

        $identity = $this->settings->identity();

        if ('' === $identity->address) {
            // Address() throws RfcComplianceException on a blank address --
            // naming that state upfront as a guard clause avoids exception-
            // driven control flow. Still a config guard: nothing is recorded.
            return MailTestResult::failed('no_from_address');
        }

        return $this->sendTestMessage($transport, $recipient, $identity);
    }

    private function sendTestMessage(
        TransportInterface $transport,
        string $recipient,
        MailIdentity $identity,
    ): MailTestResult {
        try {
            $mailer = new Mailer($transport);
            $mailer->send(
                (new Email())
                    ->from(new Address($identity->address, $identity->name))
                    ->to($recipient)
                    ->subject('Simple Feed Reader test message')
                    ->text('This confirms the outgoing mail configuration works.'),
            );
        } catch (TransportExceptionInterface | RfcComplianceException $e) {
            $this->health->recordFailure(MailKind::Test, $recipient, $e->getMessage());

            return MailTestResult::failed($e->getMessage());
        }

        $this->health->recordSuccess();

        return MailTestResult::ok();
    }

    private function effectiveTransport(?ResolvedMailTransport $resolved): ?TransportInterface
    {
        if (null !== $resolved) {
            return $this->transportFactory->forResolved($resolved, null, $this->logger);
        }

        if ($this->settings->hasEnvFallback()) {
            return $this->transportFactory->forFallbackDsn(
                $this->settings->activeTransportDsnFallback(),
                null,
                $this->logger,
            );
        }

        return null;
    }

    private function actingAdminEmail(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getEmail() : null;
    }
}
