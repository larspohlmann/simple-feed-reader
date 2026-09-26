<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Enum\SourceFormat;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Catalog\CatalogFeedEditor;
use App\Tests\DbTestCase;

final class CatalogFeedEditorTest extends DbTestCase
{
    public function testCreateAppendsAFeedToItsCategoryWithEveryField(): void
    {
        $category = $this->category('feed_editor_create');
        $first = $this->editor()->create($this->request($category, 'https://first.feed-editor.example.com/rss'));

        $second = $this->editor()->create(new CatalogFeedRequest(
            $category->requireId(),
            'Second',
            'https://second.feed-editor.example.com/rss',
            'https://second.feed-editor.example.com',
            'About the second',
            SourceFormat::SCRAPED,
            false,
            false,
        ));

        $reloaded = $this->reload($second);
        self::assertSame($category->requireId(), $reloaded->getCategory()->requireId());
        self::assertSame('Second', $reloaded->getTitle());
        self::assertSame('https://second.feed-editor.example.com/rss', $reloaded->getUrl());
        self::assertSame('https://second.feed-editor.example.com', $reloaded->getSiteUrl());
        self::assertSame('About the second', $reloaded->getDescription());
        self::assertSame(SourceFormat::SCRAPED, $reloaded->getSourceFormat());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
        self::assertSame($first->getPosition() + 1, $reloaded->getPosition());
    }

    public function testCreateCanLockAFeed(): void
    {
        $category = $this->category('feed_editor_locked');
        $feed = $this->editor()->create(new CatalogFeedRequest(
            $category->requireId(),
            'Locked',
            'https://locked.feed-editor.example.com/rss',
            locked: true,
        ));

        self::assertTrue($this->reload($feed)->isLocked());
    }

    public function testUpdateMovesTheFeedAndRewritesItsFields(): void
    {
        $from = $this->category('feed_editor_from');
        $to = $this->category('feed_editor_to');
        $feed = $this->editor()->create($this->request($from, 'https://before.feed-editor.example.com/rss'));

        $this->editor()->update($feed, new CatalogFeedRequest(
            $to->requireId(),
            'After',
            'https://after.feed-editor.example.com/rss',
            null,
            null,
            SourceFormat::XML,
            false,
            false,
        ));

        $reloaded = $this->reload($feed);
        self::assertSame($to->requireId(), $reloaded->getCategory()->requireId());
        self::assertSame('After', $reloaded->getTitle());
        self::assertSame('https://after.feed-editor.example.com/rss', $reloaded->getUrl());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
    }

    public function testUpdateRefusesAnUnknownCategory(): void
    {
        $feed = $this->editor()->create(
            $this->request($this->category('feed_editor_orphan'), 'https://orphan.feed-editor.example.com/rss'),
        );

        $this->expectException(RecordNotFoundException::class);
        $this->editor()->update($feed, new CatalogFeedRequest(999999, 'T', 'https://x.feed-editor.example.com/rss'));
    }

    public function testDeleteRemovesTheFeed(): void
    {
        $feed = $this->editor()->create(
            $this->request($this->category('feed_editor_delete'), 'https://doomed.feed-editor.example.com/rss'),
        );
        $id = $feed->requireId();

        $this->editor()->delete($feed);

        $this->em->clear();
        self::assertNull($this->em->find(CatalogFeed::class, $id));
    }

    public function testReorderGivesEachFeedItsIndex(): void
    {
        $category = $this->category('feed_editor_order');
        $first = $this->editor()->create($this->request($category, 'https://a.feed-editor.example.com/rss'));
        $second = $this->editor()->create($this->request($category, 'https://b.feed-editor.example.com/rss'));

        $this->editor()->reorder(new ReorderRequest([$second->requireId(), $first->requireId()]));

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    private function editor(): CatalogFeedEditor
    {
        $editor = self::getContainer()->get(CatalogFeedEditor::class);
        self::assertInstanceOf(CatalogFeedEditor::class, $editor);

        return $editor;
    }

    private function category(string $key): CatalogCategory
    {
        $category = new CatalogCategory($key, $key, 'star', '#000000');
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function request(CatalogCategory $category, string $url): CatalogFeedRequest
    {
        return new CatalogFeedRequest($category->requireId(), 'Title', $url);
    }

    private function reload(CatalogFeed $feed): CatalogFeed
    {
        $id = $feed->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(CatalogFeed::class, $id);
        self::assertInstanceOf(CatalogFeed::class, $reloaded);

        return $reloaded;
    }
}
