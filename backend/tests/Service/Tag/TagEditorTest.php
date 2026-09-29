<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
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

    public function testDeleteDetachesTheTagFromItsFeedsAndRemovesIt(): void
    {
        $user = $this->user('tag-deleter@example.com');
        $tag = $this->editor()->create($user, new TagDetailsModel('Doomed'));
        $subscription = $this->taggedSubscription($user, $tag);
        $tagId = $tag->requireId();

        $this->editor()->delete($tag);

        self::assertTrue($subscription->getTags()->isEmpty());
        $subscriptionId = $subscription->requireId();
        $this->em->clear();
        self::assertNull($this->em->find(Tag::class, $tagId));
        $reloaded = $this->em->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertTrue($reloaded->getTags()->isEmpty());
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
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $subscription->addTag($tag);
        $this->em->flush();

        return $subscription;
    }
}
