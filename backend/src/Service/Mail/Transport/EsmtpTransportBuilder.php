<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\ResolvedMailTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Builds an `EsmtpTransport` from a resolved SMTP row, shared by the dynamic
 *  mail transport and the connection tester so the encryption/credential
 *  rules live in one place. */
final class EsmtpTransportBuilder
{
    public static function from(
        ResolvedMailTransport $resolved,
        ?EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ): EsmtpTransport {
        $implicitTls = MailEncryption::Tls === $resolved->encryption ? true : null;
        $transport = new EsmtpTransport($resolved->host, $resolved->port, $implicitTls, $dispatcher, $logger);

        if (MailEncryption::None === $resolved->encryption) {
            $transport->setAutoTls(false);
        }
        if (null !== $resolved->username) {
            $transport->setUsername($resolved->username);
        }
        if (null !== $resolved->password) {
            $transport->setPassword($resolved->password);
        }

        return $transport;
    }
}
