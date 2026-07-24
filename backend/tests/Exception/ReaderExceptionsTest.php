<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\AlreadySubscribedException;
use App\Exception\SubscriptionLimitReachedException;
use App\Exception\TagNameTakenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReaderExceptionsTest extends TestCase
{
    public function testTypesAndStatuses(): void
    {
        self::assertSame('already_subscribed', (new AlreadySubscribedException())->type);
        self::assertSame(Response::HTTP_CONFLICT, (new AlreadySubscribedException())->status);

        self::assertSame('subscription_limit_reached', (new SubscriptionLimitReachedException(500))->type);
        self::assertSame(Response::HTTP_CONFLICT, (new SubscriptionLimitReachedException(500))->status);

        self::assertSame('tag_name_taken', (new TagNameTakenException())->type);
        self::assertSame(Response::HTTP_CONFLICT, (new TagNameTakenException())->status);
    }
}
