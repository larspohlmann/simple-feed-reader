<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Model;

use App\Service\Bluesky\Model\JsonNodeModel;
use PHPUnit\Framework\TestCase;

final class JsonNodeModelTest extends TestCase
{
    public function testReadsTypedFieldsAndTreatsTheWrongTypeAsAbsent(): void
    {
        $node = JsonNodeModel::of([
            '$type' => 'app.bsky.embed.images#view',
            'alt' => '  a cat  ',
            'blank' => '   ',
            'width' => 4000,
            'height' => '3000',
        ]);

        self::assertSame('app.bsky.embed.images#view', $node->type());
        self::assertSame('a cat', $node->string('alt'));
        self::assertNull($node->string('blank'));
        self::assertNull($node->string('width'));
        self::assertNull($node->string('missing'));
        self::assertSame(4000, $node->int('width'));
        self::assertNull($node->int('height'));
    }

    public function testNestedNodesAndListsDegradeToEmpty(): void
    {
        $node = JsonNodeModel::of([
            'author' => ['handle' => 'bsky.app'],
            'images' => [['alt' => 'one'], ['alt' => 'two']],
            'map' => ['first' => ['alt' => 'x']],
            'text' => 'not a node',
        ]);

        self::assertSame('bsky.app', $node->node('author')->string('handle'));
        self::assertNull($node->node('text')->string('handle'));
        self::assertSame(['one', 'two'], array_map(
            static fn (JsonNodeModel $image): ?string => $image->string('alt'),
            $node->nodes('images'),
        ));
        self::assertSame([], $node->nodes('map'));
        self::assertSame([], $node->nodes('missing'));
        self::assertNull(JsonNodeModel::of('scalar')->type());
    }
}
