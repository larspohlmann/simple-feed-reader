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
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;

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

    public function testCreateRefusesAChildAsParent(): void
    {
        $user = $this->user('tag-grandchild@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $child = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $parent->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A parent tag cannot itself have a parent.');
        $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $child->requireId()));
    }

    public function testUpdateRefusesAParentForATagThatHasChildren(): void
    {
        $user = $this->user('tag-parent-demotion@example.com');
        $parent = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $parent->requireId()));
        $other = $this->editor()->create($user, new TagDetailsModel('Other'));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag with child tags cannot have a parent.');
        $this->editor()->update($parent, new TagDetailsModel('Reading', parentId: $other->requireId()));
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
