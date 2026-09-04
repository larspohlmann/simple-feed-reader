<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * The env transport DSN and MAIL_FROM(_NAME), read as the fallback used when no
 * DB row exists. Parses only SMTP DSNs into form defaults; a sendmail or null
 * transport is reported as enabled-but-blank so the SMTP form starts empty while
 * the env transport keeps sending until the admin saves a DB config.
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

    public function identity(): MailIdentity
    {
        return new MailIdentity($this->fromAddress, $this->fromName);
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
        );
    }

    /** Symfony's own DSN parser, so the form prefill agrees with what the
     *  transport will actually dial. Null for an empty or malformed DSN. */
    private function parsedDsn(): ?Dsn
    {
        try {
            return Dsn::fromString($this->dsn);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
