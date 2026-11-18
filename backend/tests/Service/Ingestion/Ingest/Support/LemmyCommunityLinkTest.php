<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\Support;

use App\Entity\PostCommunity;
use App\Service\Ingestion\Ingest\Support\LemmyCommunityLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyCommunityLinkTest extends TestCase
{
    public function testALocalCommunityTakesTheLinksHost(): void
    {
        self::assertEquals(
            PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology'),
            LemmyCommunityLink::of('https://lemmy.world/c/technology'),
        );
    }

    public function testAFederatedCommunityLinkNamesItsHome(): void
    {
        self::assertEquals(
            PostCommunity::of('technology@lemmy.ml', 'https://lemmy.ml/c/technology'),
            LemmyCommunityLink::of('https://lemmy.world/c/Technology@lemmy.ML'),
        );
    }

    public function testAPortStaysWithTheHost(): void
    {
        self::assertSame('tech@lemmy.test:8536', LemmyCommunityLink::of('https://lemmy.test:8536/c/tech/')?->identity);
    }

    /** @return iterable<string, array{string}> */
    public static function notCommunities(): iterable
    {
        yield 'http' => ['http://lemmy.world/c/technology'];
        yield 'profile' => ['https://lemmy.world/u/someone'];
        yield 'no name' => ['https://lemmy.world/c/'];
        yield 'deeper path' => ['https://lemmy.world/c/tech/extra'];
        yield 'relative' => ['/c/technology'];
        yield 'empty' => [''];
        yield 'no path' => ['https://lemmy.world'];
    }

    #[DataProvider('notCommunities')]
    public function testAnythingElseIsNoCommunity(string $href): void
    {
        self::assertNull(LemmyCommunityLink::of($href));
    }
}
