<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Model;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use PHPUnit\Framework\TestCase;

final class RenderedEmbedModelTest extends TestCase
{
    public function testFollowedByKeepsEveryCardInPostOrder(): void
    {
        $first = self::cardOf('https://a.test/');
        $second = self::cardOf('https://b.test/');

        $both = RenderedEmbedModel::card($first, null)->followedBy(RenderedEmbedModel::card($second, null));

        self::assertSame([$first, $second], $both->linkCards);
    }

    public function testFollowedByKeepsEveryCardWhenEitherSideHoldsSeveral(): void
    {
        $first = self::cardOf('https://a.test/');
        $second = self::cardOf('https://b.test/');
        $third = self::cardOf('https://c.test/');
        $firstEmbed = RenderedEmbedModel::card($first, null);
        $secondEmbed = RenderedEmbedModel::card($second, null);
        $thirdEmbed = RenderedEmbedModel::card($third, null);

        $leftHeavy = $firstEmbed->followedBy($secondEmbed)->followedBy($thirdEmbed);
        $rightHeavy = $firstEmbed->followedBy($secondEmbed->followedBy($thirdEmbed));

        self::assertSame([$first, $second, $third], $leftHeavy->linkCards);
        self::assertSame([$first, $second, $third], $rightHeavy->linkCards);
    }

    private static function cardOf(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Title', null, null, 'Site');
    }
}
