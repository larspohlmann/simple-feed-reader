<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Json\Model;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use PHPUnit\Framework\TestCase;

final class JsonNodeModelTest extends TestCase
{
    public function testReadsTypedFieldsAndTreatsTheWrongTypeAsAbsent(): void
    {
        $node = JsonNodeModel::fromValue([
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
        $node = JsonNodeModel::fromValue([
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
        self::assertNull(JsonNodeModel::fromValue('scalar')->type());
    }

    public function testFirstReadsAListsFirstNodeOrAnEmptyOne(): void
    {
        $node = JsonNodeModel::fromValue(['images' => [['alt' => 'one'], ['alt' => 'two']], 'none' => []]);

        self::assertSame('one', $node->first('images')->string('alt'));
        self::assertNull($node->first('none')->type());
        self::assertNull($node->first('missing')->string('alt'));
    }

    public function testOnlyAListFieldCountsAsAList(): void
    {
        $node = JsonNodeModel::fromValue(
            ['empty' => [], 'images' => [['alt' => 'one']], 'map' => ['a' => 1], 'text' => 'x'],
        );

        self::assertSame([], $node->listAt('empty'));
        self::assertSame('one', ($node->listAt('images') ?? [])[0]->string('alt'));
        self::assertNull($node->listAt('map'));
        self::assertNull($node->listAt('text'));
        self::assertNull($node->listAt('missing'));
    }

    public function testOnlyAListValueReadsAsNodes(): void
    {
        $nodes = JsonNodeModel::parseNodes([['alt' => 'one'], ['alt' => 'two']]) ?? [];

        self::assertSame(
            ['one', 'two'],
            array_map(static fn (JsonNodeModel $node): ?string => $node->string('alt'), $nodes),
        );
        self::assertSame([], JsonNodeModel::parseNodes([]));
        self::assertNull(JsonNodeModel::parseNodes(['a' => 1]));
        self::assertNull(JsonNodeModel::parseNodes('text'));
        self::assertNull(JsonNodeModel::parseNodes(null));
    }

    public function testTextKeepsTheStringAsSentWhereStringTrimsIt(): void
    {
        $node = JsonNodeModel::fromValue(['text' => "  hi\n", 'count' => 3]);

        self::assertSame("  hi\n", $node->text('text'));
        self::assertSame('hi', $node->string('text'));
        self::assertNull($node->text('count'));
        self::assertNull($node->text('absent'));
    }

    public function testHasTellsAPresentFieldFromAnAbsentOrNullOne(): void
    {
        $node = JsonNodeModel::fromValue(['reply' => ['parent' => []], 'reblog' => null]);

        self::assertTrue($node->has('reply'));
        self::assertFalse($node->has('reblog'));
        self::assertFalse($node->has('reason'));
    }

    public function testIsTrueReadsOnlyTheBooleanTrue(): void
    {
        $node = JsonNodeModel::fromValue(['muted' => true, 'blockedBy' => false, 'flag' => 'true', 'count' => 1]);

        self::assertTrue($node->isTrue('muted'));
        self::assertFalse($node->isTrue('blockedBy'));
        self::assertFalse($node->isTrue('flag'));
        self::assertFalse($node->isTrue('count'));
        self::assertFalse($node->isTrue('absent'));
    }
}
