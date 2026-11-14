<?php

declare(strict_types=1);

namespace App\Tests\Http\Problem\ExceptionProblems;

use App\Http\Problem\ExceptionProblems\DiscoveryProblems;
use App\Service\Sourcing\Discovery\Exception\SiteThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class DiscoveryProblemsTest extends TestCase
{
    public function testAThrottledSiteIsServiceUnavailable(): void
    {
        $resolved = (new DiscoveryProblems())->resolve(new SiteThrottledException());

        self::assertNotNull($resolved);
        self::assertSame('feed_throttled', $resolved->problem->type);
        self::assertSame('Site is rationing requests', $resolved->problem->title);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $resolved->problem->status);
        self::assertSame('The site asked us to slow down. Try again in a minute.', $resolved->problem->detail);
    }

    public function testOtherExceptionsPassThrough(): void
    {
        self::assertNull((new DiscoveryProblems())->resolve(new \RuntimeException('x')));
    }
}
