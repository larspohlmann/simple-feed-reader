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

    public function testLemmyHasItsDisplayName(): void
    {
        self::assertSame('Lemmy', SocialNetwork::Lemmy->displayName());
    }

    public function testOnlyLemmyTakesNoLinkPosts(): void
    {
        self::assertTrue(SocialNetwork::Bluesky->takesLinkPosts());
        self::assertTrue(SocialNetwork::Mastodon->takesLinkPosts());
        self::assertFalse(SocialNetwork::Lemmy->takesLinkPosts());
    }

    public function testOnlyLemmyLeavesSearchResultsToTheInstance(): void
    {
        self::assertTrue(SocialNetwork::Bluesky->checksSearchResults());
        self::assertTrue(SocialNetwork::Mastodon->checksSearchResults());
        self::assertFalse(SocialNetwork::Lemmy->checksSearchResults());
    }

    public function testTheRouteRequirementNamesEveryNetwork(): void
    {
        self::assertSame(
            implode('|', SocialNetwork::valuesOf(SocialNetwork::cases())),
            SocialNetwork::ROUTE_REQUIREMENT,
        );
    }

    public function testNetworksListTheirWireValues(): void
    {
        $networks = [SocialNetwork::Mastodon, SocialNetwork::Bluesky];

        self::assertSame(['mastodon', 'bluesky'], SocialNetwork::valuesOf($networks));
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
            self::assertSame(
                ['network' => ['Unknown network. Use one of: bluesky, mastodon, lemmy.']],
                $exception->errors,
            );
        }
    }

    public function testEveryNetworkParsesFromARequest(): void
    {
        self::assertSame(SocialNetwork::Bluesky, SocialNetwork::fromRequestValue('bluesky'));
        self::assertSame(SocialNetwork::Mastodon, SocialNetwork::fromRequestValue('mastodon'));
        self::assertSame(SocialNetwork::Lemmy, SocialNetwork::fromRequestValue('lemmy'));
    }
}
