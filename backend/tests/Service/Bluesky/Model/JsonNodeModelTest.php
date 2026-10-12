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

    public function testFirstReadsAListsFirstNodeOrAnEmptyOne(): void
    {
        $node = JsonNodeModel::of(['images' => [['alt' => 'one'], ['alt' => 'two']], 'none' => []]);

        self::assertSame('one', $node->first('images')->string('alt'));
        self::assertNull($node->first('none')->type());
        self::assertNull($node->first('missing')->string('alt'));
    }

    public function testOnlyAListFieldCountsAsAList(): void
    {
        $node = JsonNodeModel::of(['empty' => [], 'images' => [['alt' => 'one']], 'map' => ['a' => 1], 'text' => 'x']);

        self::assertSame([], $node->listAt('empty'));
        self::assertSame('one', ($node->listAt('images') ?? [])[0]->string('alt'));
        self::assertNull($node->listAt('map'));
        self::assertNull($node->listAt('text'));
        self::assertNull($node->listAt('missing'));
    }

    public function testOnlyAListValueReadsAsNodes(): void
    {
        $nodes = JsonNodeModel::listOf([['alt' => 'one'], ['alt' => 'two']]) ?? [];

        self::assertSame(
            ['one', 'two'],
            array_map(static fn (JsonNodeModel $node): ?string => $node->string('alt'), $nodes),
        );
        self::assertSame([], JsonNodeModel::listOf([]));
        self::assertNull(JsonNodeModel::listOf(['a' => 1]));
        self::assertNull(JsonNodeModel::listOf('text'));
        self::assertNull(JsonNodeModel::listOf(null));
    }

    public function testTextKeepsTheStringAsSentWhereStringTrimsIt(): void
    {
        $node = JsonNodeModel::of(['text' => "  hi\n", 'count' => 3]);

        self::assertSame("  hi\n", $node->text('text'));
        self::assertSame('hi', $node->string('text'));
        self::assertNull($node->text('count'));
        self::assertNull($node->text('absent'));
    }

    public function testHasTellsAPresentFieldFromAnAbsentOrNullOne(): void
    {
        $node = JsonNodeModel::of(['reply' => ['parent' => []], 'reblog' => null]);

        self::assertTrue($node->has('reply'));
        self::assertFalse($node->has('reblog'));
        self::assertFalse($node->has('reason'));
    }
}
