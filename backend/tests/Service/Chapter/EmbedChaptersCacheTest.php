<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Entity\EntryChapter;
use App\Service\Chapter\EmbedChaptersCache;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EmbedChaptersCacheTest extends TestCase
{
    public function testAFoundListLivesThirtyDays(): void
    {
        $this->assertLifetime([new EntryChapter(0, 'Intro')], 2592000);
    }

    public function testAnEmptyListLivesADay(): void
    {
        $this->assertLifetime([], 86400);
    }

    public function testARememberedListReadsBackEqual(): void
    {
        $cache = new EmbedChaptersCache(new ArrayAdapter());
        $embed = new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc');
        $chapters = [new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Two')];

        $cache->remember($embed, $chapters);

        self::assertEquals($chapters, $cache->find($embed));
    }

    public function testARememberedEmptyListReadsBackAsEmptyNotAMiss(): void
    {
        $cache = new EmbedChaptersCache(new ArrayAdapter());
        $embed = new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc');

        $cache->remember($embed, []);

        self::assertSame([], $cache->find($embed));
    }

    public function testAnUnknownEmbedIsAMiss(): void
    {
        self::assertNull(
            (new EmbedChaptersCache(new ArrayAdapter()))->find(new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc')),
        );
    }

    public function testAStoredNonListIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store($pool, 'embed_chapters.youtube.abc', 'garbage');

        self::assertNull($this->findYouTube($pool));
    }

    public function testAListWithAnIncompleteChapterIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store(
            $pool,
            'embed_chapters.youtube.abc',
            [['startSeconds' => 0, 'title' => 'Intro'], ['title' => 'x']],
        );

        self::assertNull($this->findYouTube($pool));
    }

    public function testAnAssociativeStoredValueIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store($pool, 'embed_chapters.youtube.abc', ['a' => ['startSeconds' => 0, 'title' => 'Intro']]);

        self::assertNull($this->findYouTube($pool));
    }

    public function testTwoProvidersWithTheSameIdDoNotShareAnEntry(): void
    {
        $cache = new EmbedChaptersCache(new ArrayAdapter());
        $cache->remember(new EmbedAddressModel(ChapteredEmbed::YouTube, '123'), [new EntryChapter(0, 'Video')]);

        self::assertNull($cache->find(new EmbedAddressModel(ChapteredEmbed::SoundCloud, '123')));
    }

    /** @param list<EntryChapter> $chapters */
    private function assertLifetime(array $chapters, int $seconds): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with($seconds);
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        (new EmbedChaptersCache($pool))->remember(new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc'), $chapters);
    }

    private function findYouTube(ArrayAdapter $pool): mixed
    {
        return (new EmbedChaptersCache($pool))->find(new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc'));
    }

    private function store(ArrayAdapter $pool, string $key, mixed $value): void
    {
        $item = $pool->getItem($key);
        $item->set($value);
        $pool->save($item);
    }
}
