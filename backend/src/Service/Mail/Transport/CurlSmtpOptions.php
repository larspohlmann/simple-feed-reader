<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

/** Builds the curl option map for one proxied SMTP send. Kept pure and free of
 *  the upload stream so the scheme/TLS/proxy/envelope decisions are unit-tested
 *  without a socket. */
final class CurlSmtpOptions
{
    /** @return array<int, mixed> */
    public static function for(ResolvedMailTransport $resolved, ProxyConfig $proxy, Envelope $envelope): array
    {
        $options = [
            \CURLOPT_URL => self::url($resolved),
            \CURLOPT_PROXY => $proxy->dsn(),
            \CURLOPT_MAIL_FROM => \sprintf('<%s>', $envelope->getSender()->getAddress()),
            \CURLOPT_MAIL_RCPT => array_map(
                static fn (Address $recipient): string => \sprintf('<%s>', $recipient->getAddress()),
                $envelope->getRecipients(),
            ),
            \CURLOPT_USE_SSL => MailEncryption::None === $resolved->encryption ? \CURLUSESSL_NONE : \CURLUSESSL_ALL,
        ];

        if ($proxy->resolvesLocally()) {
            // Hand the proxy an IPv4 address, the one type RFC 1928 makes every
            // SOCKS5 proxy support; a locally resolved IPv6 draws a "not
            // supported" reply from proxies that implement IPv4 only (#861).
            $options[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
        }
        if (null !== $resolved->username) {
            $options[\CURLOPT_USERNAME] = $resolved->username;
        }
        if (null !== $resolved->password) {
            $options[\CURLOPT_PASSWORD] = $resolved->password;
        }

        return $options;
    }

    private static function url(ResolvedMailTransport $resolved): string
    {
        $scheme = MailEncryption::Tls === $resolved->encryption ? 'smtps' : 'smtp';

        return \sprintf('%s://%s:%d', $scheme, $resolved->host, $resolved->port);
    }
}
