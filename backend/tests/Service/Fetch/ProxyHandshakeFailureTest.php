<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ProxyHandshakeFailure;
use PHPUnit\Framework\TestCase;

/**
 * curl reports a refused SOCKS5 CONNECT as the raw reply byte from RFC 1928 —
 * "cannot complete SOCKS5 connection to api.ipify.org. (4)" — which tells an
 * admin nothing. These are the messages the Proxy settings page shows instead.
 */
final class ProxyHandshakeFailureTest extends TestCase
{
    public function testHostUnreachableNamesTheProxyAsTheOneRefusing(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'cannot complete SOCKS5 connection to api.ipify.org. (4)',
        );

        self::assertStringContainsString('api.ipify.org', $explained);
        self::assertStringContainsString('could not reach', $explained);
    }

    /**
     * The one failure with a fix the admin can act on: a proxy that does not
     * resolve names answers host-unreachable for every name it is given.
     */
    public function testHostUnreachableSuggestsTurningRemoteDnsOff(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'cannot complete SOCKS5 connection to api.ipify.org. (4)',
        );

        self::assertStringContainsString('does not resolve host names', $explained);
    }

    public function testAddressTypeNotSupportedAlsoSuggestsTurningRemoteDnsOff(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'cannot complete SOCKS5 connection to api.ipify.org. (8)',
        );

        self::assertStringContainsString('does not resolve host names', $explained);
    }

    public function testRulesetRefusalPointsAtTheCredentialsInstead(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'cannot complete SOCKS5 connection to api.ipify.org. (2)',
        );

        self::assertStringContainsString('username and password', $explained);
        self::assertStringNotContainsString('does not resolve host names', $explained);
    }

    /** Older curl builds write "Can't", and include the port. */
    public function testTheOlderCurlPhrasingIsRecognisedToo(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            "Can't complete SOCKS5 connection to proxy.example:443. (5)",
        );

        self::assertStringContainsString('refused', $explained);
        self::assertStringContainsString('proxy.example:443', $explained);
    }

    public function testTheRawCurlTextIsKeptForDiagnosis(): void
    {
        $raw = 'cannot complete SOCKS5 connection to api.ipify.org. (4)';

        self::assertStringContainsString($raw, ProxyHandshakeFailure::explain($raw));
    }

    /**
     * The other unreadable SOCKS5 message: curl appends the version and status
     * bytes of the failed auth exchange, which say nothing to an admin.
     */
    public function testARejectedLoginIsNamedAsACredentialProblem(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'User was rejected by the SOCKS5 server (1 1).',
        );

        self::assertStringContainsString('username and password', $explained);
        self::assertStringContainsString('User was rejected by the SOCKS5 server (1 1).', $explained);
    }

    public function testAnUnrelatedTransportMessageIsLeftAlone(): void
    {
        $raw = 'Could not resolve host: feeds.example.org';

        self::assertSame($raw, ProxyHandshakeFailure::explain($raw));
    }

    public function testAReplyCodeOutsideTheRfcIsLeftAlone(): void
    {
        $raw = 'cannot complete SOCKS5 connection to api.ipify.org. (99)';

        self::assertSame($raw, ProxyHandshakeFailure::explain($raw));
    }
}
