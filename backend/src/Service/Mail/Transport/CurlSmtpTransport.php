<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport;

use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends one message over SMTP through the configured egress proxy, using ext-curl
 * (which tunnels SMTP over SOCKS5/HTTP natively). Extends the public
 * AbstractTransport, so it keeps Mailer's event/logging integration and depends
 * on no @internal Mailer class -- a Mailer upgrade cannot silently break it.
 */
final class CurlSmtpTransport extends AbstractTransport
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ResolvedMailTransport $resolved,
        private readonly ProxyConfig $proxy,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $body = $message->toString();
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new TransportException('Unable to buffer the message for the proxied SMTP send.');
        }
        fwrite($stream, $body);
        rewind($stream);

        $handle = curl_init();
        if (false === $handle) {
            throw new TransportException('Unable to initialise curl for the proxied SMTP send.');
        }

        curl_setopt_array($handle, CurlSmtpOptions::for($this->resolved, $this->proxy, $message->getEnvelope()) + [
            \CURLOPT_UPLOAD => true,
            \CURLOPT_INFILE => $stream,
            \CURLOPT_INFILESIZE => \strlen($body),
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_RETURNTRANSFER => true,
        ]);

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($stream);

        if (false === $ok) {
            throw new TransportException(\sprintf('Proxied SMTP send failed: %s', $error));
        }
    }

    public function __toString(): string
    {
        return \sprintf('smtp+proxy://%s:%d', $this->resolved->host, $this->resolved->port);
    }
}
