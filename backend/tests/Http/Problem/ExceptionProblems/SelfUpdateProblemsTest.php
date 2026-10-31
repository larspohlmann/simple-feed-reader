<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SelfUpdateProblems;
use App\Service\SelfUpdate\Exception\MalformedUpdateStateException;
use App\Service\SelfUpdate\Exception\NoUpdateAvailableException;
use App\Service\SelfUpdate\Exception\UpdateInProgressException;
use App\Service\SelfUpdate\Exception\UpdateRequestNotSavedException;
use App\Service\SelfUpdate\Exception\UpdaterUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SelfUpdateProblemsTest extends TestCase
{
    #[DataProvider('mappedExceptions')]
    public function testMapsEachRefusalToItsProblem(\Throwable $exception, string $type, int $status): void
    {
        $resolved = (new SelfUpdateProblems())->resolve($exception);

        self::assertNotNull($resolved);
        self::assertSame($type, $resolved->problem->type);
        self::assertSame($status, $resolved->problem->status);
        self::assertSame($exception->getMessage(), $resolved->problem->detail);
    }

    /** @return iterable<string, array{\Throwable, string, int}> */
    public static function mappedExceptions(): iterable
    {
        yield 'no updater' => [
            new UpdaterUnavailableException('gone'),
            'updater_unavailable',
            Response::HTTP_SERVICE_UNAVAILABLE,
        ];
        yield 'under way' => [new UpdateInProgressException('busy'), 'update_in_progress', Response::HTTP_CONFLICT];
        yield 'up to date' => [
            new NoUpdateAvailableException('latest'),
            'no_update_available',
            Response::HTTP_CONFLICT,
        ];
        yield 'unreadable state' => [
            new MalformedUpdateStateException('status.json is not valid JSON.'),
            'update_state_unreadable',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ];
        yield 'unsaved request' => [
            new UpdateRequestNotSavedException('request.json.tmp could not be written.'),
            'update_request_not_saved',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ];
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new SelfUpdateProblems())->resolve(new \RuntimeException('x')));
    }
}
