<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Entity\EntryChapter;
use App\Service\Chapter\EmbedChapters;
use App\Service\Chapter\EmbedChaptersCache;
use App\Service\Chapter\EmbedDescriptionSource\EmbedDescriptionSourceInterface;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class EmbedChaptersTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/Chapter/';

    public function testStampsInADescriptionBecomeChapters(): void
    {
        $chapters = $this->chaptersOf("0:00 Intro\n3:10 Track two\n7:45 Track three");

        self::assertSame([0, 190, 465], array_map(static fn ($chapter): int => $chapter->startSeconds, $chapters));
        self::assertSame(
            ['Intro', 'Track two', 'Track three'],
            array_map(static fn ($chapter): string => $chapter->title, $chapters),
        );
    }

    public function testARealYouTubeDescriptionWithTrackNumbersBeforeItsStampsGivesYouTubesNineteenChapters(): void
    {
        $chapters = $this->chaptersOf($this->fixture('youtube-description-570hdDp8l4g.txt'));

        self::assertCount(19, $chapters);
        self::assertSame('01. IKØN - A World Between Us [Iboga Records]', $chapters[0]->title);
        self::assertSame(4543, $chapters[18]->startSeconds);
    }

    public function testARealSoundCloudDescriptionGivesItsThirtyTwoChapters(): void
    {
        $chapters = $this->chaptersOf($this->fixture('soundcloud-description-catching-flies.txt'));

        self::assertCount(32, $chapters);
        self::assertSame(0, $chapters[0]->startSeconds);
        self::assertSame('1. Jon Hopkins/Olafur Arnalds - Forever Held', $chapters[0]->title);
        self::assertSame(4447, $chapters[31]->startSeconds);
    }

    public function testADescriptionWithoutStampsGivesNoChapters(): void
    {
        self::assertSame([], $this->chaptersOf('Just words, no stamps.'));
    }

    public function testAnEmptyDescriptionGivesNoChapters(): void
    {
        self::assertSame([], $this->chaptersOf(''));
    }

    public function testTheProviderPicksTheSource(): void
    {
        $youtube = new CountingSource("0:00 A\n1:00 B\n2:00 C");
        $soundCloud = new CountingSource('');

        $chapters = $this->chapters($youtube, $soundCloud)->of(new EmbedAddressModel(ChapteredEmbed::YouTube, 'x'));

        self::assertCount(3, $chapters);
        self::assertSame(1, $youtube->calls);
        self::assertSame(0, $soundCloud->calls);
    }

    public function testASecondCallIsAnsweredFromTheCache(): void
    {
        $source = new CountingSource("0:00 A\n1:00 B\n2:00 C");
        $chapters = $this->chapters($source, new CountingSource(''));
        $embed = new EmbedAddressModel(ChapteredEmbed::YouTube, 'x');

        $first = $chapters->of($embed);

        self::assertEquals($first, $chapters->of($embed));
        self::assertSame(1, $source->calls);
    }

    public function testAnEmptyAnswerIsCachedToo(): void
    {
        $source = new CountingSource('');
        $chapters = $this->chapters($source, new CountingSource(''));
        $embed = new EmbedAddressModel(ChapteredEmbed::YouTube, 'x');

        $chapters->of($embed);
        $chapters->of($embed);

        self::assertSame(1, $source->calls);
    }

    public function testAnUnreachableSourceThrowsAndTheNextCallAsksAgain(): void
    {
        $source = new CountingSource('', 503);
        $chapters = $this->chapters($source, new CountingSource(''));
        $embed = new EmbedAddressModel(ChapteredEmbed::YouTube, 'x');

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $chapters->of($embed);
                self::fail('The unreachable source should throw.');
            } catch (EmbedDescriptionUnreachableException) {
                self::assertSame($attempt, $source->calls);
            }
        }
    }

    /** @return list<EntryChapter> */
    private function chaptersOf(string $description): array
    {
        return $this->chapters(
            new CountingSource($description),
            new CountingSource($description),
        )->of(new EmbedAddressModel(ChapteredEmbed::YouTube, 'x'));
    }

    private function chapters(CountingSource $youtube, CountingSource $soundCloud): EmbedChapters
    {
        return new EmbedChapters(
            new EmbedChaptersCache(new ArrayAdapter()),
            new ServiceLocator([
                ChapteredEmbed::YouTube->value => static fn (): EmbedDescriptionSourceInterface => $youtube,
                ChapteredEmbed::SoundCloud->value => static fn (): EmbedDescriptionSourceInterface => $soundCloud,
            ]),
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }
}
