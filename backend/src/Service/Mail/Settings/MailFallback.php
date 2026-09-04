<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Enum\MailEncryption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The env transport DSN and MAIL_FROM(_NAME), read as the fallback used when no
 * DB row exists. Parses only SMTP DSNs into form defaults; a sendmail or null
 * transport is reported as real-but-blank so the SMTP form starts empty while
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

    public function context(): MailFallbackContext
    {
        $parts = parse_url($this->dsn);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';

        if ('' === trim($this->dsn) || 'null' === $scheme) {
            return $this->blank(false);
        }

        if ('smtp' !== $scheme && 'smtps' !== $scheme) {
            return $this->blank(true);
        }

        $encryption = 'smtps' === $scheme ? MailEncryption::Tls : MailEncryption::Starttls;

        return new MailFallbackContext(
            true,
            $parts['host'] ?? '',
            $parts['port'] ?? MailConnection::DEFAULT_PORT,
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            $encryption,
            $this->fromAddress,
            $this->fromName,
        );
    }

    private function blank(bool $isReal): MailFallbackContext
    {
        return new MailFallbackContext(
            $isReal,
            '',
            MailConnection::DEFAULT_PORT,
            null,
            MailEncryption::Starttls,
            $this->fromAddress,
            $this->fromName,
        );
    }
}
