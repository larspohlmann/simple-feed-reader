<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Enum\SocksReplyCode;

/**
 * Turns curl's SOCKS5 handshake messages into something an admin can act on.
 *
 * curl reports both handshake failures as raw protocol bytes and nothing else —
 * "cannot complete SOCKS5 connection to api.ipify.org. (4)" for a refused
 * CONNECT, "User was rejected by the SOCKS5 server (1 1)." for a refused login
 * — so the Proxy settings page showed numbers where it needed reasons. Any
 * other message passes through untouched.
 */
final readonly class ProxyHandshakeFailure
{
    /**
     * curl 8 writes "cannot complete SOCKS5 connection to <target>. (<reply>)";
     * older builds write "Can't", and put a port on the target. The target is
     * matched lazily so the trailing full stop stays out of it.
     */
    private const string REFUSED_CONNECT = '/SOCKS5 connection to (?<target>.+?)\.?\s*\((?<reply>\d+)\)/i';

    /** The two trailing bytes are the auth version and status of the exchange. */
    private const string REJECTED_LOGIN = '/User was rejected by the SOCKS5 server/i';

    private const string REJECTED_LOGIN_REASON = 'The proxy rejected the username and password.';

    private const string REMOTE_DNS_HINT = 'A proxy that does not resolve host names answers this for every name '
        . 'it is given — Private Internet Access is one. Turn "Resolve DNS at the proxy" off to resolve names here '
        . 'instead.';

    public static function explain(string $transportMessage): string
    {
        $reason = self::reasonFor($transportMessage);

        return null === $reason ? $transportMessage : self::withRawText($reason, $transportMessage);
    }

    private static function reasonFor(string $transportMessage): ?string
    {
        if (1 === preg_match(self::REJECTED_LOGIN, $transportMessage)) {
            return self::REJECTED_LOGIN_REASON;
        }

        if (1 !== preg_match(self::REFUSED_CONNECT, $transportMessage, $matches)) {
            return null;
        }

        $reply = SocksReplyCode::tryFrom((int) $matches['reply']);

        return null === $reply ? null : self::refusedConnect($matches['target'], $reply);
    }

    private static function refusedConnect(string $target, SocksReplyCode $reply): string
    {
        return implode(' ', array_filter([
            sprintf('The proxy refused to connect to %s: %s.', $target, $reply->meaning()),
            $reply->canMeanTheProxyDoesNotResolveNames() ? self::REMOTE_DNS_HINT : null,
        ]));
    }

    private static function withRawText(string $reason, string $rawMessage): string
    {
        return sprintf('%s (curl reported: %s)', $reason, $rawMessage);
    }
}
