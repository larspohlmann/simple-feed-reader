<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiExceptionIsLoggedAndAnsweredTest extends KernelTestCase
{
    public function testAnApiExceptionIsLoggedAndAnsweredAsProblemJson(): void
    {
        $kernel = self::bootKernel();
        $requestLogger = self::getContainer()->get('monolog.logger.request');
        self::assertInstanceOf(Logger::class, $requestLogger);
        $recorder = new TestHandler();
        $requestLogger->pushHandler($recorder);

        $response = $kernel->handle(Request::create('/api/no-such-route-1169'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertCount(
            1,
            self::uncaughtNotFound($recorder),
            'ErrorListener::logKernelException logged the exception the API answered',
        );
    }

    /** @return list<LogRecord> */
    private static function uncaughtNotFound(TestHandler $recorder): array
    {
        return array_values(array_filter(
            $recorder->getRecords(),
            static fn (LogRecord $record): bool => str_starts_with(
                $record->message,
                'Uncaught PHP Exception ' . NotFoundHttpException::class,
            ),
        ));
    }
}
