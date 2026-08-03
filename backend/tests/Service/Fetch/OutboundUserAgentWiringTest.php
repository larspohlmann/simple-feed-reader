<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Command\CheckCatalogUrlsCommand;
use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Reader\HtmlPageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every outbound request must announce ONE User-Agent, and that string must not
 * advertise a host.
 *
 * Both halves are load-bearing, and both were learned the hard way (#255).
 *
 * The host rule: Akamai Bot Manager rejects a User-Agent containing a
 * domain-shaped token. It does not answer 403 — it completes the TLS handshake
 * and then resets the HTTP/2 stream, so the transport reports nothing at all and
 * the reader tells the user to "check the address" for a feed that is perfectly
 * fine. The courteous `(+https://…)` self-identification cost us every cbc.ca
 * feed. An invented domain is blocked exactly like a real one, so this is a
 * pattern match on "the agent advertises a host", not domain reputation.
 *
 * The one-value rule: the catalog rot check used to send a User-Agent of its
 * own. A publisher that blocks the fetcher but not the checker would then leave
 * the rot check reporting a healthy catalog while no user could subscribe — the
 * probe has to be indistinguishable from the traffic it is a probe for.
 */
final class OutboundUserAgentWiringTest extends KernelTestCase
{
    /**
     * Matches a domain-shaped token: labels joined by dots ending in a
     * two-letter-or-longer TLD. Deliberately loose — it flags `example.com`
     * inside a URL, inside an email address and bare, while leaving a version
     * like `1.0` alone (no alphabetic TLD follows the dot).
     */
    private const string HOST_SHAPED = '/[a-z0-9-]+\.[a-z]{2,}/i';

    /**
     * The services that talk to publisher infrastructure, and the property each
     * one keeps its agent string in.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function outboundServices(): iterable
    {
        yield 'feed fetcher' => [ConcurrentFeedFetcher::class, 'userAgent'];
        yield 'reader page fetcher' => [HtmlPageFetcher::class, 'userAgent'];
        yield 'catalog rot check' => [CheckCatalogUrlsCommand::class, 'userAgent'];
    }

    public function testTheConfiguredAgentAdvertisesNoHost(): void
    {
        self::bootKernel();

        $userAgent = self::getContainer()->getParameter('outbound_user_agent');

        self::assertDoesNotMatchRegularExpression(
            self::HOST_SHAPED,
            $userAgent,
            'The User-Agent must not name a host: a domain-shaped token in it makes Akamai '
            . 'reset the connection, which surfaces to the user as an unreachable site.',
        );
        self::assertStringNotContainsString('://', $userAgent);
    }

    /**
     * @param class-string $serviceClass
     */
    #[DataProvider('outboundServices')]
    public function testEveryOutboundServiceSendsTheConfiguredAgent(
        string $serviceClass,
        string $propertyName,
    ): void {
        self::bootKernel();
        $container = self::getContainer();

        $service = $container->get($serviceClass);
        $injected = (new \ReflectionProperty($serviceClass, $propertyName))->getValue($service);

        self::assertSame($container->getParameter('outbound_user_agent'), $injected);
    }
}
