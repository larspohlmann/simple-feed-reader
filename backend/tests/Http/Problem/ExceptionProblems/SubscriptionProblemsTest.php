<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\SubscriptionProblems;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\NotAFeedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionProblemsTest extends TestCase
{
    public function testTheLimitIsAConflictWithItsOwnMessage(): void
    {
        $resolved = (new SubscriptionProblems())->resolve(new SubscriptionLimitReachedException(500));

        self::assertNotNull($resolved);
        self::assertSame('subscription_limit_reached', $resolved->problem->type);
        self::assertSame('Subscription limit reached', $resolved->problem->title);
        self::assertSame(Response::HTTP_CONFLICT, $resolved->problem->status);
        self::assertSame('You can subscribe to at most 500 feeds.', $resolved->problem->detail);
    }

    public function testAnExistingSubscriptionIsAConflict(): void
    {
        $resolved = (new SubscriptionProblems())->resolve(new AlreadySubscribedException());

        self::assertNotNull($resolved);
        self::assertSame('already_subscribed', $resolved->problem->type);
        self::assertSame('Already subscribed to that feed', $resolved->problem->title);
        self::assertSame(Response::HTTP_CONFLICT, $resolved->problem->status);
    }

    public function testANonFeedIsUnprocessableAndPointsAtDiscover(): void
    {
        $resolved = (new SubscriptionProblems())->resolve(new NotAFeedException());

        self::assertNotNull($resolved);
        self::assertSame('not_a_feed', $resolved->problem->type);
        self::assertSame('Not a feed', $resolved->problem->title);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resolved->problem->status);
        self::assertStringContainsString('/api/feeds/discover', (string) $resolved->problem->detail);
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new SubscriptionProblems())->resolve(new \RuntimeException('x')));
    }
}
