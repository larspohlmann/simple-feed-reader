<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\CrossPostKeyRule;

use App\Entity\CanonicalItem;
use App\Entity\Headline;
use App\Enum\CanonicalItemKind;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Ingest\CrossPostKeyRule\LemmyCrossPostKeyRule;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\TestCase;

final class LemmyCrossPostKeyRuleTest extends TestCase
{
    public function testALemmyLinkPostIsKeyed(): void
    {
        $hash = $this->buildRule()->findHash(
            self::buildItem(CanonicalItemKind::Lemmy),
            self::buildPost('https://x.example/a'),
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
    }

    public function testTrackingParametersDoNotSplitAGroup(): void
    {
        $item = self::buildItem(CanonicalItemKind::Lemmy);

        $tracked = $this->buildRule()->findHash($item, self::buildPost('https://x.example/a?utm_source=l'));
        $plain = $this->buildRule()->findHash($item, self::buildPost('https://x.example/a'));

        self::assertNotNull($plain);
        self::assertSame($plain, $tracked);
    }

    public function testAnotherKindIsNeverKeyed(): void
    {
        $hash = $this->buildRule()->findHash(
            self::buildItem(CanonicalItemKind::Mastodon),
            self::buildPost('https://x.example/a'),
        );

        self::assertNull($hash);
    }

    private function buildRule(): LemmyCrossPostKeyRule
    {
        return new LemmyCrossPostKeyRule(new UrlNormalizer());
    }

    private static function buildItem(CanonicalItemKind $kind): CanonicalItem
    {
        return new CanonicalItem($kind, 'https://lemmy.world/post/1', new \DateTimeImmutable('2026-11-20T12:00:00Z'));
    }

    private static function buildPost(string $linkedUrl): ParsedEntryModel
    {
        return new ParsedEntryModel(
            'https://lemmy.world/post/1',
            'https://lemmy.world/post/1',
            Headline::fromFeed('Post'),
            null,
            null,
            null,
            null,
            authorUrl: 'https://lemmy.world/u/perfectdark',
        )->withLinkedUrl($linkedUrl);
    }
}
