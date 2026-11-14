<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\RequestIdProvider;
use PHPUnit\Framework\TestCase;

final class RequestIdProviderTest extends TestCase
{
    public function testCurrentIsStableAcrossCalls(): void
    {
        $provider = new RequestIdProvider();

        self::assertSame($provider->current(), $provider->current());
    }

    public function testStartNewReplacesTheCurrentId(): void
    {
        $provider = new RequestIdProvider();
        $first = $provider->current();

        $second = $provider->startNew();

        self::assertNotSame($first, $second);
        self::assertSame($second, $provider->current());
    }

    public function testSetOverridesTheCurrentId(): void
    {
        $provider = new RequestIdProvider();

        $provider->set('01J000000000000000000TEST');

        self::assertSame('01J000000000000000000TEST', $provider->current());
    }

    public function testCurrentIsALowercaseUlidShape(): void
    {
        $provider = new RequestIdProvider();

        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $provider->current());
    }
}
