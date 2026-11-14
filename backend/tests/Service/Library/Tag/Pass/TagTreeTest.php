<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag\Pass;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Tag\Pass\TagTree;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use PHPUnit\Framework\Attributes\DataProvider;

final class TagTreeTest extends DbTestCase
{
    use SeedsUsers;

    #[DataProvider('placements')]
    public function testCanPlaceFollowsTheNestingRule(int $tagId, int $parentId, bool $expected): void
    {
        $tree = new TagTree([1 => null, 2 => 1, 3 => 2, 4 => null, 5 => 4]);

        self::assertSame($expected, $tree->canPlace($tagId, $parentId));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function placements(): iterable
    {
        yield 'under itself' => [4, 4, false];
        yield 'under its own child' => [4, 5, false];
        yield 'under its own grandchild' => [1, 3, false];
        yield 'a leaf under a grandchild is too deep' => [5, 3, false];
        yield 'a leaf under a child fits' => [5, 2, true];
        yield 'a parent under a root fits' => [4, 1, true];
        yield 'a parent under a child is too deep' => [4, 2, false];
    }

    public function testFitsCountsTheParentsDepthPlusOnePlusTheHeight(): void
    {
        self::assertTrue(TagTree::fits(0, 1));
        self::assertTrue(TagTree::fits(1, 0));
        self::assertFalse(TagTree::fits(1, 1));
        self::assertFalse(TagTree::fits(0, 2));
    }

    public function testARootSitsAtDepthZeroAndEachLevelBelowAddsOne(): void
    {
        [$reading, $longReads, $essays] = $this->chain($this->user('tag-tree-depth@example.com'));
        $tree = TagTree::fromTags([$reading, $longReads, $essays]);

        self::assertSame(0, $tree->depthOf($reading->requireId()));
        self::assertSame(1, $tree->depthOf($longReads->requireId()));
        self::assertSame(2, $tree->depthOf($essays->requireId()));
    }

    public function testHeightCountsTheDeepestBranchBelowATag(): void
    {
        $user = $this->user('tag-tree-height@example.com');
        [$reading, $longReads, $essays] = $this->chain($user);
        $shallow = $this->tag($user, 'shallow', $reading);
        $tree = TagTree::fromTags([$reading, $longReads, $essays, $shallow]);

        self::assertSame(2, $tree->heightOf($reading->requireId()));
        self::assertSame(1, $tree->heightOf($longReads->requireId()));
        self::assertSame(0, $tree->heightOf($essays->requireId()));
    }

    public function testATagIsWithinItselfAndEveryAncestorButNotASibling(): void
    {
        $user = $this->user('tag-tree-within@example.com');
        [$reading, $longReads, $essays] = $this->chain($user);
        $sibling = $this->tag($user, 'sibling', $reading);
        $tree = TagTree::fromTags([$reading, $longReads, $essays, $sibling]);

        self::assertTrue($tree->isWithin($essays->requireId(), $essays->requireId()));
        self::assertTrue($tree->isWithin($essays->requireId(), $reading->requireId()));
        self::assertFalse($tree->isWithin($essays->requireId(), $sibling->requireId()));
        self::assertFalse($tree->isWithin($reading->requireId(), $essays->requireId()));
    }

    /** @return array{0: Tag, 1: Tag, 2: Tag} */
    private function chain(User $user): array
    {
        $reading = $this->tag($user, 'reading', null);
        $longReads = $this->tag($user, 'long reads', $reading);

        return [$reading, $longReads, $this->tag($user, 'essays', $longReads)];
    }

    private function tag(User $user, string $name, ?Tag $parent): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setParent($parent);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }
}
