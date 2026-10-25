<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\InvalidTagParentException;
use App\Service\Tag\Exception\TagNameTakenException;
use App\Service\Tag\Model\TagDetailsModel;
use App\Service\Tag\TagEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class TagEditorTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    public function testCreateAppendsTheTagAfterTheUsersExistingOnes(): void
    {
        $user = $this->user('tag-creator@example.com');
        $this->editor()->create($user, new TagDetailsModel('First'));

        $tag = $this->editor()->create($user, new TagDetailsModel('Second', '#ff8800', 'star'));

        $reloaded = $this->reload($tag);
        self::assertSame('Second', $reloaded->getName());
        self::assertSame('#ff8800', $reloaded->getColor());
        self::assertSame('star', $reloaded->getIcon());
        self::assertSame(1, $reloaded->getPosition());
    }

    public function testCreateRefusesANameTheUserAlreadyHasInAnyCase(): void
    {
        $user = $this->user('tag-duplicate@example.com');
        $this->editor()->create($user, new TagDetailsModel('News'));

        $this->expectException(TagNameTakenException::class);
        $this->editor()->create($user, new TagDetailsModel('NEWS'));
    }

    public function testCreateAllowsANameAnotherUserHas(): void
    {
        $this->editor()->create($this->user('tag-owner@example.com'), new TagDetailsModel('News'));

        $tag = $this->editor()->create($this->user('tag-other@example.com'), new TagDetailsModel('News'));

        self::assertSame('News', $this->reload($tag)->getName());
    }

    public function testUpdateMayKeepTheTagsOwnNameInAnotherCase(): void
    {
        $user = $this->user('tag-renamer@example.com');
        $tag = $this->editor()->create($user, new TagDetailsModel('news'));

        $this->editor()->update($tag, new TagDetailsModel('News', '#000000', 'label'));

        $reloaded = $this->reload($tag);
        self::assertSame('News', $reloaded->getName());
        self::assertSame('#000000', $reloaded->getColor());
        self::assertSame('label', $reloaded->getIcon());
    }

    public function testUpdateRefusesAnotherTagsName(): void
    {
        $user = $this->user('tag-clash@example.com');
        $this->editor()->create($user, new TagDetailsModel('News'));
        $tech = $this->editor()->create($user, new TagDetailsModel('Tech'));

        $this->expectException(TagNameTakenException::class);
        $this->editor()->update($tech, new TagDetailsModel('news'));
    }

    public function testCreateUnderAParentStoresTheParent(): void
    {
        $user = $this->user('tag-child-creator@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));

        $child = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $parent->requireId()));

        self::assertSame($parent->requireId(), $this->reload($child)->getParent()?->getId());
    }

    public function testCreateRefusesAnUnknownParent(): void
    {
        $user = $this->user('tag-unknown-parent@example.com');

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('The parent tag does not exist.');
        $this->editor()->create($user, new TagDetailsModel('Orphan', parentId: 999999));
    }

    public function testCreateRefusesAnotherUsersTagAsParent(): void
    {
        $foreign = $this->editor()->create($this->user('tag-foreign-owner@example.com'), new TagDetailsModel('Theirs'));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('The parent tag does not exist.');
        $this->editor()->create(
            $this->user('tag-foreign-child@example.com'),
            new TagDetailsModel('Mine', parentId: $foreign->requireId()),
        );
    }

    public function testCreateNestsATagTwoLevelsDown(): void
    {
        $user = $this->user('tag-grandchild-create@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));

        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));

        self::assertSame($longReads->requireId(), $this->reload($essays)->getParent()?->getId());
    }

    public function testCreateRefusesAThirdLevelBelowTheTop(): void
    {
        $user = $this->user('tag-too-deep-create@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag can sit at most 2 levels below the top.');
        $this->editor()->create($user, new TagDetailsModel('Short essays', parentId: $essays->requireId()));
    }

    public function testUpdateMovesATagWithChildrenUnderARoot(): void
    {
        $user = $this->user('tag-move-subtree@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $books = $this->editor()->create($user, new TagDetailsModel('Books'));
        $novels = $this->editor()->create($user, new TagDetailsModel('Novels', parentId: $books->requireId()));

        $this->editor()->update($books, new TagDetailsModel('Books', parentId: $reading->requireId()));

        self::assertSame($reading->requireId(), $this->reload($books)->getParent()?->getId());
        self::assertSame($books->requireId(), $this->reload($novels)->getParent()?->getId());
    }

    public function testUpdateRefusesAMoveThatPushesAChildPastTheLimit(): void
    {
        $user = $this->user('tag-move-too-deep@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $books = $this->editor()->create($user, new TagDetailsModel('Books'));
        $this->editor()->create($user, new TagDetailsModel('Novels', parentId: $books->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag can sit at most 2 levels below the top.');
        $this->editor()->update($books, new TagDetailsModel('Books', parentId: $longReads->requireId()));
    }

    public function testUpdateRefusesAMoveUnderTheTagsOwnChild(): void
    {
        $user = $this->user('tag-move-under-child@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag cannot move under one of its own child tags.');
        $this->editor()->update($reading, new TagDetailsModel('Reading', parentId: $longReads->requireId()));
    }

    public function testUpdateChecksTheCommittedTreeSoTwoCrossingMovesCannotLoop(): void
    {
        $user = $this->user('tag-move-crossing@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $tech = $this->editor()->create($user, new TagDetailsModel('Tech'));
        $this->moveBehindTheEditor($reading, $tech);

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag cannot move under one of its own child tags.');
        $this->editor()->update($tech, new TagDetailsModel('Tech', parentId: $reading->requireId()));
    }

    public function testCreateUpdateAndDeleteEachHoldTheTagStructureLock(): void
    {
        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no row locks; it serialises writers itself.');
        }
        $user = $this->user('tag-editor-locks@example.com');
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $this->editor()->update($reading, new TagDetailsModel('Read'));
        $this->editor()->delete($reading);

        self::assertCount(3, $recorder->queriesMatching('for update'));
    }

    public function testAnEditThatKeepsTheParentReadsNoTagTree(): void
    {
        $user = $this->user('tag-keep-parent-cheap@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->editor()->update($longReads, new TagDetailsModel('Longreads', parentId: $reading->requireId()));

        self::assertSame([], $recorder->queriesMatching('parent_id as'));
    }

    public function testCreateChecksTheCommittedTreeForDepth(): void
    {
        $user = $this->user('tag-create-crossing@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads'));
        $tech = $this->editor()->create($user, new TagDetailsModel('Tech'));
        $this->moveBehindTheEditor($longReads, $reading);
        $this->moveBehindTheEditor($reading, $tech);

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag can sit at most 2 levels below the top.');
        $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));
    }

    public function testDeletingAMiddleTagMovesItsChildrenUpUnderItsParentAfterTheOthers(): void
    {
        $user = $this->user('tag-middle-deleter@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));
        $poems = $this->editor()->create($user, new TagDetailsModel('Poems', parentId: $reading->requireId()));

        $this->editor()->delete($longReads);

        $this->entityManager->clear();
        $reloadedEssays = $this->entityManager->find(Tag::class, $essays->requireId());
        $reloadedPoems = $this->entityManager->find(Tag::class, $poems->requireId());
        self::assertInstanceOf(Tag::class, $reloadedEssays);
        self::assertInstanceOf(Tag::class, $reloadedPoems);
        self::assertSame($reading->requireId(), $reloadedEssays->getParent()?->getId());
        self::assertGreaterThan($reloadedPoems->getPosition(), $reloadedEssays->getPosition());
    }

    public function testDeletingARootLiftsItsChildToRootAndKeepsItsGrandchildUnderIt(): void
    {
        $user = $this->user('tag-root-deleter@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));

        $this->editor()->delete($reading);

        $this->entityManager->clear();
        $reloadedLongReads = $this->entityManager->find(Tag::class, $longReads->requireId());
        $reloadedEssays = $this->entityManager->find(Tag::class, $essays->requireId());
        self::assertInstanceOf(Tag::class, $reloadedLongReads);
        self::assertInstanceOf(Tag::class, $reloadedEssays);
        self::assertNull($reloadedLongReads->getParent());
        self::assertSame($longReads->requireId(), $reloadedEssays->getParent()?->getId());
    }

    public function testUpdateRefusesTheTagItselfAsParent(): void
    {
        $user = $this->user('tag-self-parent@example.com');
        $tag = $this->editor()->create($user, new TagDetailsModel('Loop'));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag cannot be its own parent.');
        $this->editor()->update($tag, new TagDetailsModel('Loop', parentId: $tag->requireId()));
    }

    public function testUpdateMovesATagUnderAParentAndBackToRoot(): void
    {
        $user = $this->user('tag-reparent@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $tag = $this->editor()->create($user, new TagDetailsModel('Essays'));

        $this->editor()->update($tag, new TagDetailsModel('Essays', parentId: $parent->requireId()));
        self::assertSame($parent->requireId(), $this->reload($tag)->getParent()?->getId());

        $this->editor()->update($this->reload($tag), new TagDetailsModel('Essays'));
        self::assertNull($this->reload($tag)->getParent());
    }

    public function testReparentingAppendsTheTagAfterItsNewSiblings(): void
    {
        $user = $this->user('tag-reparent-position@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $child = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $parent->requireId()));
        $root = $this->editor()->create($user, new TagDetailsModel('Other'));
        $mover = $this->editor()->create($user, new TagDetailsModel('Poems'));

        $this->editor()->update($child, new TagDetailsModel('Essays'));
        $this->editor()->update($mover, new TagDetailsModel('Poems', parentId: $parent->requireId()));

        self::assertGreaterThan($root->getPosition(), $this->reload($child)->getPosition());
        self::assertGreaterThan($this->reload($child)->getPosition(), $this->reload($mover)->getPosition());
    }

    public function testAnEditThatKeepsTheParentKeepsThePosition(): void
    {
        $user = $this->user('tag-keep-position@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $child = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $parent->requireId()));
        $this->editor()->create($user, new TagDetailsModel('Other'));
        $position = $child->getPosition();

        $this->editor()->update($child, new TagDetailsModel('Long essays', parentId: $parent->requireId()));

        self::assertSame($position, $this->reload($child)->getPosition());
    }

    public function testAParentMayBeRenamedWhileItStaysARoot(): void
    {
        $user = $this->user('tag-parent-rename@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $parent->requireId()));

        $this->editor()->update($parent, new TagDetailsModel('Books'));

        self::assertSame('Books', $this->reload($parent)->getName());
    }

    public function testDeleteDetachesTheTagFromItsFeedsAndRemovesIt(): void
    {
        $user = $this->user('tag-deleter@example.com');
        $tag = $this->editor()->create($user, new TagDetailsModel('Doomed'));
        $subscription = $this->taggedSubscription($user, $tag);
        $tagId = $tag->requireId();

        $this->editor()->delete($tag);

        self::assertTrue($subscription->getTags()->isEmpty());
        $subscriptionId = $subscription->requireId();
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Tag::class, $tagId));
        $reloaded = $this->entityManager->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertTrue($reloaded->getTags()->isEmpty());
    }

    public function testDeletingAParentMovesItsChildrenToRootAfterTheLastRoot(): void
    {
        $user = $this->user('tag-parent-deleter@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $first = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $parent->requireId()));
        $other = $this->editor()->create($user, new TagDetailsModel('Other'));
        $second = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $parent->requireId()));

        $this->editor()->delete($parent);

        self::assertNull($first->getParent());
        $this->entityManager->clear();
        $reloadedFirst = $this->entityManager->find(Tag::class, $first->requireId());
        $reloadedSecond = $this->entityManager->find(Tag::class, $second->requireId());
        self::assertInstanceOf(Tag::class, $reloadedFirst);
        self::assertInstanceOf(Tag::class, $reloadedSecond);
        self::assertNull($reloadedFirst->getParent());
        self::assertNull($reloadedSecond->getParent());
        self::assertSame([$other->requireId(), $first->requireId(), $second->requireId()], array_map(
            static fn (Tag $tag): int => $tag->requireId(),
            self::getContainer()->get(TagRepository::class)->findForUser($user->requireId()),
        ));
    }

    private function editor(): TagEditor
    {
        $editor = self::getContainer()->get(TagEditor::class);
        self::assertInstanceOf(TagEditor::class, $editor);

        return $editor;
    }

    /** A concurrent request that committed: the row changes, the entities this request holds do not. */
    private function moveBehindTheEditor(Tag $tag, Tag $parent): void
    {
        $this->entityManager->getConnection()->update(
            'tag',
            ['parent_id' => $parent->requireId()],
            ['id' => $tag->requireId()],
        );
    }

    private function taggedSubscription(User $user, Tag $tag): Subscription
    {
        $feed = new Feed('https://tag-editor.example.com/rss');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $subscription->addTag($tag);
        $this->entityManager->flush();

        return $subscription;
    }
}
