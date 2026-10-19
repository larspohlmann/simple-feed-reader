<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\MastodonFollowedTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonFollowedTagTest extends TestCase
{
    public function testATagIsOfferedUnderItsHashedName(): void
    {
        self::assertEquals(
            new SocialCollectionModel('Caturday', '#Caturday'),
            MastodonFollowedTag::collectionOf(JsonNodeModel::of(['name' => 'Caturday', 'following' => true])),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableTags(): iterable
    {
        yield 'no name' => [['following' => true]];
        yield 'a name that is no hashtag' => [['name' => 'cat/../day']];
        yield 'a numeric name' => [['name' => 42]];
    }

    /** @param array<string, mixed> $tag */
    #[DataProvider('unusableTags')]
    public function testATagWithoutAUsableNameIsLeftOut(array $tag): void
    {
        self::assertNull(MastodonFollowedTag::collectionOf(JsonNodeModel::of($tag)));
    }
}
