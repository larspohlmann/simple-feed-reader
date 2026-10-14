<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Model;

use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use PHPUnit\Framework\TestCase;

final class RenderedEmbedModelTest extends TestCase
{
    public function testFollowedByKeepsEveryCardWhenEitherSideHoldsSeveral(): void
    {
        $cards = array_map(self::cardOf(...), ['https://a.test/', 'https://b.test/', 'https://c.test/']);
        [$first, $second, $third] = array_map(
            static fn (LinkCardContent $card): RenderedEmbedModel => RenderedEmbedModel::card($card, null),
            $cards,
        );

        self::assertSame($cards, $first->followedBy($second)->followedBy($third)->linkCards);
        self::assertSame($cards, $first->followedBy($second->followedBy($third))->linkCards);
    }

    public function testFollowedByKeepsTheQuoteOfEitherSide(): void
    {
        $quote = new QuotedPostContent('https://bsky.app/profile/a/post/b', 'A', 'a.test', null, null, 'Hi', [], null);
        $quoted = RenderedEmbedModel::quoted($quote);
        $card = RenderedEmbedModel::card(self::cardOf('https://b.test/'), null);

        self::assertSame($quote, $quoted->followedBy($card)->quote);
        self::assertSame($quote, $card->followedBy($quoted)->quote);
        self::assertNull($card->followedBy($card)->quote);
    }

    public function testFollowedByKeepsTheUncardedLinkOfEitherSide(): void
    {
        $uncarded = RenderedEmbedModel::uncarded('https://a.test/');
        $card = RenderedEmbedModel::card(self::cardOf('https://b.test/'), null);

        self::assertSame('https://a.test/', $uncarded->followedBy($card)->uncardedLink);
        self::assertSame('https://a.test/', $card->followedBy($uncarded)->uncardedLink);
        self::assertNull($card->followedBy($card)->uncardedLink);
        self::assertSame(
            'https://a.test/',
            $uncarded->followedBy(RenderedEmbedModel::uncarded('https://b.test/'))->uncardedLink,
        );
        self::assertSame('', $uncarded->html);
        self::assertNull($uncarded->leadImage);
    }

    private static function cardOf(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Title', null, null, 'Site');
    }
}
