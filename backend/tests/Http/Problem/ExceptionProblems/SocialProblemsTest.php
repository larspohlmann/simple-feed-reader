<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SocialProblems;
use App\Service\Social\Exception\BlueskyProfileHiddenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SocialProblemsTest extends TestCase
{
    public function testAHiddenProfileIsAnUnprocessableRequestThatSaysWhatToDo(): void
    {
        $resolved = (new SocialProblems())->resolve(new BlueskyProfileHiddenException('Connect a Bluesky account.'));

        self::assertNotNull($resolved);
        self::assertSame('bluesky_profile_hidden', $resolved->problem->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertSame('Connect a Bluesky account.', $resolved->problem->detail);
    }
}
