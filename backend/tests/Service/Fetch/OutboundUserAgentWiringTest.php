<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Catalog\CatalogUrlChecker;
use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Reader\HtmlPageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Every outbound request sends `outbound_user_agent`, and it names no host; config/services.yaml says why. */
final class OutboundUserAgentWiringTest extends KernelTestCase
{
    /**
     * A domain-shaped token, deliberately loose: it flags `example.com` in a URL, in an email address and bare, but
     * not a version like `1.0`, where no alphabetic TLD follows the dot.
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
        yield 'catalog rot check' => [CatalogUrlChecker::class, 'userAgent'];
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
