<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SocialNetwork;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class SocialNetworkTest extends TestCase
{
    public function testEachNetworkHasItsDisplayName(): void
    {
        self::assertSame('Bluesky', SocialNetwork::Bluesky->displayName());
        self::assertSame('Mastodon', SocialNetwork::Mastodon->displayName());
    }

    public function testARequestValueNamesItsNetwork(): void
    {
        self::assertSame(SocialNetwork::Mastodon, SocialNetwork::fromRequestValue('mastodon'));
    }

    public function testAnAbsentRequestValueNamesNoNetwork(): void
    {
        self::assertNull(SocialNetwork::fromRequestValue(null));
    }

    public function testAnUnknownRequestValueIsAValidationError(): void
    {
        try {
            SocialNetwork::fromRequestValue('myspace');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['network' => ['Unknown network. Use one of: bluesky, mastodon.']], $exception->errors);
        }
    }
}
