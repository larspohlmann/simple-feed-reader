<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\BlueskyProblems;
use App\Service\Social\Bluesky\Exception\BlueskyProfileHiddenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyProblemsTest extends TestCase
{
    public function testAHiddenProfileIsAnUnprocessableRequestThatSaysWhatToDo(): void
    {
        $resolved = (new BlueskyProblems())->resolve(new BlueskyProfileHiddenException('Connect a Bluesky account.'));

        self::assertNotNull($resolved);
        self::assertSame('bluesky_profile_hidden', $resolved->problem->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertSame('Profile hidden from logged-out readers', $resolved->problem->title);
        self::assertSame('Connect a Bluesky account.', $resolved->problem->detail);
    }

    public function testAnotherExceptionBelongsToAnotherModule(): void
    {
        self::assertNull((new BlueskyProblems())->resolve(new \RuntimeException('x')));
    }
}
