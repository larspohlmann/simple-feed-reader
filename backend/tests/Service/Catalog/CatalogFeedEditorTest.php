<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Enum\SourceFormat;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Catalog\CatalogFeedEditor;
use App\Service\Catalog\Model\CatalogFeedDetailsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;

final class CatalogFeedEditorTest extends DbTestCase
{
    use ReloadsEntities;

    public function testCreateAppendsAFeedToItsCategoryWithEveryField(): void
    {
        $category = $this->category('feed_editor_create');
        $first = $this->editor()->create($this->details($category, 'https://first.feed-editor.example.com/rss'));

        $reloadedFirst = $this->reload($first);
        self::assertTrue($reloadedFirst->isEnabled());
        self::assertTrue($reloadedFirst->isLocked());

        $second = $this->editor()->create(new CatalogFeedDetailsModel(
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
        $feed = $this->editor()->create(new CatalogFeedDetailsModel(
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
        $feed = $this->editor()->create($this->details($from, 'https://before.feed-editor.example.com/rss'));

        $this->editor()->update($feed, new CatalogFeedDetailsModel(
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
            $this->details($this->category('feed_editor_orphan'), 'https://orphan.feed-editor.example.com/rss'),
        );

        $this->expectException(RecordNotFoundException::class);
        $this->editor()
            ->update($feed, new CatalogFeedDetailsModel(999999, 'T', 'https://x.feed-editor.example.com/rss'));
    }

    public function testDeleteRemovesTheFeed(): void
    {
        $feed = $this->editor()->create(
            $this->details($this->category('feed_editor_delete'), 'https://doomed.feed-editor.example.com/rss'),
        );
        $id = $feed->requireId();

        $this->editor()->delete($feed);

        $this->em->clear();
        self::assertNull($this->em->find(CatalogFeed::class, $id));
    }

    public function testReorderGivesEachFeedItsIndex(): void
    {
        $category = $this->category('feed_editor_order');
        $first = $this->editor()->create($this->details($category, 'https://a.feed-editor.example.com/rss'));
        $second = $this->editor()->create($this->details($category, 'https://b.feed-editor.example.com/rss'));

        $this->editor()->reorder([$second->requireId(), $first->requireId()]);

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    public function testReorderRefusesAnUnknownFeed(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->editor()->reorder([999999]);
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

    private function details(CatalogCategory $category, string $url): CatalogFeedDetailsModel
    {
        return new CatalogFeedDetailsModel($category->requireId(), 'Title', $url);
    }
}
