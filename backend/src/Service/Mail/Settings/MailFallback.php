<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailConnection;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * The env DSN and MAIL_FROM(_NAME), used while no DB row exists. Only an SMTP DSN prefills the form: sendmail or null
 * reads as enabled-but-blank, and the env transport keeps sending until the admin saves a DB config.
 */
final readonly class MailFallback
{
    public function __construct(
        #[Autowire('%env(MAILER_FALLBACK_DSN)%')]
        private string $dsn,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function transportDsn(): string
    {
        return $this->dsn;
    }

    public function identity(): MailIdentityModel
    {
        return new MailIdentityModel($this->fromAddress, $this->fromName);
    }

    public function connection(): MailConnection
    {
        $dsn = $this->parsedDsn();
        $scheme = $dsn?->getScheme();

        if (null === $dsn || ('smtp' !== $scheme && 'smtps' !== $scheme)) {
            return new MailConnection(
                '' !== trim($this->dsn) && 'null' !== $scheme,
                '',
                MailConnection::DEFAULT_PORT,
                null,
                MailEncryption::Starttls,
                $this->fromAddress,
                $this->fromName,
                useProxy: false,
            );
        }

        return new MailConnection(
            true,
            $dsn->getHost(),
            $dsn->getPort() ?? MailConnection::DEFAULT_PORT,
            $dsn->getUser(),
            'smtps' === $scheme ? MailEncryption::Tls : MailEncryption::Starttls,
            $this->fromAddress,
            $this->fromName,
            useProxy: false,
        );
    }

    /** Symfony's own DSN parser, so the form prefill agrees with what the
     *  transport will dial. Null when it refuses the DSN: not a failure here,
     *  the caller reports that as a non-SMTP transport and the send fails loudly. */
    private function parsedDsn(): ?Dsn
    {
        try {
            return Dsn::fromString($this->dsn);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
