<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Model;

use App\Entity\LinkCardContent;
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

    private static function cardOf(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Title', null, null, 'Site');
    }
}
