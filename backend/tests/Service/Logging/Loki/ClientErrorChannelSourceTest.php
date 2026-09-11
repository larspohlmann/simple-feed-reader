<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiPushHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClientErrorChannelSourceTest extends KernelTestCase
{
    public function testAClientErrorsChannelLogIsBufferedWithSourceFrontend(): void
    {
        self::bootKernel();
        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get('monolog.logger.' . LokiPushHandler::CLIENT_ERRORS_CHANNEL);

        $logger->error('render blew up', ['kind' => 'TypeError']);

        $labels = $this->firstBufferedLabels();
        self::assertSame('frontend', $labels['source']);
        self::assertSame('client_errors', $labels['channel']);
    }

    /** @return array<string, string> */
    private function firstBufferedLabels(): array
    {
        /** @var LokiPushHandler $handler */
        $handler = self::getContainer()->get(LokiPushHandler::class);
        $buffer = new \ReflectionProperty(LokiPushHandler::class, 'buffer');
        /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
        $lines = $buffer->getValue($handler);
        self::assertNotSame([], $lines, 'the channel logger must reach the container LokiPushHandler');

        return $lines[0]['labels'];
    }
}
