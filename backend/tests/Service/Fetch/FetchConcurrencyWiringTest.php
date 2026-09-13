<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sweep's in-flight cap is dialled per install through the environment:
 * the Strato worker dies from an OS-level cap far below memory_limit once eight
 * fetches hold their threads, TLS buffers and parsed documents at once (#1025).
 */
final class FetchConcurrencyWiringTest extends KernelTestCase
{
    private string $committedDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $committedDefault = $_ENV['FETCH_CONCURRENCY'];
        self::assertIsString($committedDefault);
        $this->committedDefault = $committedDefault;
    }

    protected function tearDown(): void
    {
        self::setEnvironment($this->committedDefault);
        parent::tearDown();
    }

    /**
     * Dotenv populates both superglobals and the processor reads $_ENV first,
     * so an override has to land in both to be seen and to be undone.
     */
    private static function setEnvironment(string $value): void
    {
        $_ENV['FETCH_CONCURRENCY'] = $value;
        $_SERVER['FETCH_CONCURRENCY'] = $value;
    }

    public function testTheDefaultKeepsEightFetchesInFlight(): void
    {
        self::bootKernel();

        self::assertSame(8, self::getContainer()->getParameter('fetch_concurrency'));
    }

    public function testTheEnvironmentDialsTheCapDown(): void
    {
        self::setEnvironment('3');

        self::bootKernel();

        self::assertSame(3, self::getContainer()->getParameter('fetch_concurrency'));
    }
}
