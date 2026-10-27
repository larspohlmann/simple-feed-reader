<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Entity\EntryChapter;
use App\Service\Chapter\EmbedDetails;
use App\Service\Chapter\EmbedDetailsCache;
use App\Service\Chapter\EmbedDetailsSource\EmbedDetailsSourceInterface;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Chapter\Model\EmbedDetailsModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class EmbedDetailsTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/Chapter/';
    private const string TRACKLIST = "0:00 Intro\n3:10 Track two\n7:45 Track three";

    public function testStampsInADescriptionBecomeChapters(): void
    {
        $chapters = $this->chaptersOf(self::TRACKLIST);

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

    public function testTheSourcesTitleAndLengthTravelWithTheChapters(): void
    {
        $source = new CountingSource(new EmbedDescriptionModel('A mix', 4964, self::TRACKLIST, null));

        $details = $this->details($source, CountingSource::describing(''))->of($this->youTube());

        self::assertSame('A mix', $details->title);
        self::assertSame(4964, $details->durationSeconds);
        self::assertCount(3, $details->chapters);
    }

    public function testAChapterStartingAtOrPastTheEndIsDropped(): void
    {
        $source = new CountingSource(new EmbedDescriptionModel(null, 465, self::TRACKLIST, null));

        $details = $this->details($source, CountingSource::describing(''))->of($this->youTube());

        self::assertSame([0, 190], array_map(
            static fn (EntryChapter $chapter): int => $chapter->startSeconds,
            $details->chapters,
        ));
    }

    public function testAChapterJustBeforeTheEndStays(): void
    {
        $source = new CountingSource(new EmbedDescriptionModel(null, 466, self::TRACKLIST, null));

        self::assertCount(3, $this->details($source, CountingSource::describing(''))->of($this->youTube())->chapters);
    }

    public function testTheProviderPicksTheSource(): void
    {
        $youtube = CountingSource::describing(self::TRACKLIST);
        $soundCloud = CountingSource::describing('');

        $details = $this->details($youtube, $soundCloud)->of($this->youTube());

        self::assertCount(3, $details->chapters);
        self::assertSame(1, $youtube->calls);
        self::assertSame(0, $soundCloud->calls);
    }

    public function testASecondCallIsAnsweredFromTheCache(): void
    {
        $source = new CountingSource(new EmbedDescriptionModel('A mix', 4964, self::TRACKLIST, null));
        $details = $this->details($source, CountingSource::describing(''));

        $first = $details->of($this->youTube());

        self::assertEquals($first, $details->of($this->youTube()));
        self::assertSame(1, $source->calls);
    }

    public function testDetailsWithoutChaptersAreCachedToo(): void
    {
        $source = CountingSource::describing('');
        $details = $this->details($source, CountingSource::describing(''));

        $details->of($this->youTube());
        $details->of($this->youTube());

        self::assertSame(1, $source->calls);
    }

    public function testAnUnreachableSourceThrowsAndTheNextCallAsksAgain(): void
    {
        $source = CountingSource::failingWith(503);
        $details = $this->details($source, CountingSource::describing(''));

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $details->of($this->youTube());
                self::fail('The unreachable source should throw.');
            } catch (EmbedDescriptionUnreachableException) {
                self::assertSame($attempt, $source->calls);
            }
        }
    }

    /** @return list<EntryChapter> */
    private function chaptersOf(string $description): array
    {
        return $this->detailsOf($description)->chapters;
    }

    private function detailsOf(string $description): EmbedDetailsModel
    {
        return $this->details(
            CountingSource::describing($description),
            CountingSource::describing($description),
        )->of($this->youTube());
    }

    private function details(CountingSource $youtube, CountingSource $soundCloud): EmbedDetails
    {
        return new EmbedDetails(
            new EmbedDetailsCache(new ArrayAdapter()),
            new ServiceLocator([
                ChapteredEmbed::YouTube->value => static fn (): EmbedDetailsSourceInterface => $youtube,
                ChapteredEmbed::SoundCloud->value => static fn (): EmbedDetailsSourceInterface => $soundCloud,
            ]),
        );
    }

    private function youTube(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'x');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }
}
