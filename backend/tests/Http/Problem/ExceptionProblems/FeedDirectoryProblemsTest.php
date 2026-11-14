<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\FeedDirectoryProblems;
use App\Service\Sourcing\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\Sourcing\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class FeedDirectoryProblemsTest extends TestCase
{
    public function testAnUnknownCountryIsNotFound(): void
    {
        $resolved = (new FeedDirectoryProblems())->resolve(new UnknownFeedDirectoryCountryException('No FR.'));

        self::assertNotNull($resolved);
        self::assertSame('feed_directory_country_not_found', $resolved->problem->type);
        self::assertSame(Response::HTTP_NOT_FOUND, $resolved->problem->status);
        self::assertSame('No FR.', $resolved->problem->detail);
    }

    public function testAnInvalidScopeIsUnprocessable(): void
    {
        $resolved = (new FeedDirectoryProblems())->resolve(new InvalidFeedDirectoryScopeException('Bad scope.'));

        self::assertNotNull($resolved);
        self::assertSame('feed_directory_scope_invalid', $resolved->problem->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertSame('Bad scope.', $resolved->problem->detail);
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new FeedDirectoryProblems())->resolve(new \RuntimeException('x')));
    }
}
