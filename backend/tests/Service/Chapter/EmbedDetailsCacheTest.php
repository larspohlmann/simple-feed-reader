<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Entity\EntryChapter;
use App\Service\Chapter\EmbedDetailsCache;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDetailsModel;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EmbedDetailsCacheTest extends TestCase
{
    public function testChapteredDetailsLiveThirtyDays(): void
    {
        $this->assertLifetime(new EmbedDetailsModel('A mix', 4964, [new EntryChapter(0, 'Intro')]), 2592000);
    }

    public function testDetailsWithoutChaptersLiveADay(): void
    {
        $this->assertLifetime(new EmbedDetailsModel('A mix', 4964, []), 86400);
    }

    public function testRememberedDetailsReadBackEqual(): void
    {
        $cache = new EmbedDetailsCache(new ArrayAdapter());
        $details = new EmbedDetailsModel('A mix', 4964, [new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Two')]);

        $cache->remember($this->youTube(), $details);

        self::assertEquals($details, $cache->find($this->youTube()));
    }

    public function testRememberedEmptyDetailsReadBackAsEmptyNotAMiss(): void
    {
        $cache = new EmbedDetailsCache(new ArrayAdapter());
        $details = new EmbedDetailsModel(null, null, []);

        $cache->remember($this->youTube(), $details);

        self::assertEquals($details, $cache->find($this->youTube()));
    }

    public function testAnUnknownEmbedIsAMiss(): void
    {
        self::assertNull((new EmbedDetailsCache(new ArrayAdapter()))->find($this->youTube()));
    }

    public function testAMalformedEntryIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store($pool, 'embed_details.youtube.abc', 'garbage');

        self::assertNull((new EmbedDetailsCache($pool))->find($this->youTube()));
    }

    public function testAnEntryTheChaptersOnlyCodeLeftIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store($pool, 'embed_chapters.youtube.abc', [['startSeconds' => 0, 'title' => 'Intro']]);
        $this->store($pool, 'embed_details.youtube.abc', [['startSeconds' => 0, 'title' => 'Intro']]);

        self::assertNull((new EmbedDetailsCache($pool))->find($this->youTube()));
    }

    public function testTwoProvidersWithTheSameIdDoNotShareAnEntry(): void
    {
        $cache = new EmbedDetailsCache(new ArrayAdapter());
        $cache->remember(
            new EmbedAddressModel(ChapteredEmbed::YouTube, '123'),
            new EmbedDetailsModel('Video', null, [new EntryChapter(0, 'Video')]),
        );

        self::assertNull($cache->find(new EmbedAddressModel(ChapteredEmbed::SoundCloud, '123')));
    }

    private function assertLifetime(EmbedDetailsModel $details, int $seconds): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with($seconds);
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        (new EmbedDetailsCache($pool))->remember($this->youTube(), $details);
    }

    private function youTube(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'abc');
    }

    private function store(ArrayAdapter $pool, string $key, mixed $value): void
    {
        $item = $pool->getItem($key);
        $item->set($value);
        $pool->save($item);
    }
}
