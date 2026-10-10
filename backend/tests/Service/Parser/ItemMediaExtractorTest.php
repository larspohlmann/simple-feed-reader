<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\VisualMediaKind;
use App\Tests\Support\FeedItemFixtures;
use PHPUnit\Framework\TestCase;

final class ItemMediaExtractorTest extends TestCase
{
    use FeedItemFixtures;

    private ItemMediaExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ItemMediaExtractor();
    }

    public function testPodcastEnclosureBecomesAnAttachmentWithMimeDurationAndSize(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<enclosure url="https://cdn/ep1.mp3" type="audio/mpeg" length="4200000"/>'
            . '<itunes:duration>1:02:03</itunes:duration>',
        ));

        self::assertSame([], $bundle->media);
        self::assertCount(1, $bundle->attachments);
        $attachment = $bundle->attachments[0];
        self::assertSame('https://cdn/ep1.mp3', $attachment->url);
        self::assertSame('audio/mpeg', $attachment->mimeType);
        self::assertSame(3723, $attachment->durationInSeconds);
        self::assertSame(4200000, $attachment->sizeInBytes);
    }

    public function testOnlyItunesDurationIsReadAsTheDuration(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<itunes:author>Host</itunes:author><duration>9</duration>'
            . '<enclosure url="https://cdn/ep1.mp3" type="audio/mpeg"/><itunes:duration>120</itunes:duration>',
        ));

        self::assertSame(120, $bundle->attachments[0]->durationInSeconds);
    }

    public function testAnEnclosureIsMarkedWithItsMediaKind(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<enclosure url="https://cdn/ep1.mp3" type="audio/mpeg"/>'
            . '<enclosure url="https://cdn/notes.pdf" type="application/pdf"/>',
        ));

        self::assertSame(
            [FeedMediaKind::Audio, FeedMediaKind::Other],
            array_map(static fn ($attachment) => $attachment->kind, $bundle->attachments),
        );
    }

    public function testMultipleTopLevelImagesBecomeMediaWithDimensions(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:content url="https://i/one.jpg" medium="image" width="800" height="600"/>'
            . '<media:content url="https://i/two.jpg" medium="image" width="400" height="300"/>',
        ));

        self::assertSame([], $bundle->attachments);
        self::assertCount(2, $bundle->media);
        self::assertSame('https://i/one.jpg', $bundle->media[0]->url);
        self::assertSame(VisualMediaKind::Image, $bundle->media[0]->kind);
        self::assertSame(800, $bundle->media[0]->width);
        self::assertSame(600, $bundle->media[0]->height);
        self::assertSame('https://i/two.jpg', $bundle->media[1]->url);
    }

    public function testVideoGroupYieldsAVisualWithPosterAndAPlayableAttachment(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://v/clip.mp4" medium="video" type="video/mp4" duration="90" fileSize="5000"/>'
            . '<media:thumbnail url="https://v/poster.jpg"/>'
            . '</media:group>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame(VisualMediaKind::Video, $bundle->media[0]->kind);
        self::assertSame('https://v/clip.mp4', $bundle->media[0]->url);
        self::assertSame('https://v/poster.jpg', $bundle->media[0]->previewImageUrl);

        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://v/clip.mp4', $bundle->attachments[0]->url);
        self::assertSame('video/mp4', $bundle->attachments[0]->mimeType);
        self::assertSame(90, $bundle->attachments[0]->durationInSeconds);
        self::assertSame(5000, $bundle->attachments[0]->sizeInBytes);
    }

    public function testAVideoGroupPosterUrlIsTrimmed(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://v/clip.mp4" medium="video" type="video/mp4"/>'
            . '<media:thumbnail url="  https://v/poster.jpg  "/>'
            . '</media:group>',
        ));

        self::assertSame('https://v/poster.jpg', $bundle->media[0]->previewImageUrl);
    }

    public function testATopLevelThumbnailBecomesAnImage(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:thumbnail url="https://i/thumb.jpg" width="320"/>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame('https://i/thumb.jpg', $bundle->media[0]->url);
    }

    public function testAUrlBearingElementOutsideMediaRssIsNoMedia(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<content url="https://cdn/ep.mp3" type="audio/mpeg"/>',
        ));

        self::assertSame([], $bundle->media);
        self::assertSame([], $bundle->attachments);
    }

    public function testImageGroupCollapsesToTheWidestRendition(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://i/140.jpg" medium="image" width="140"/>'
            . '<media:content url="https://i/700.jpg" medium="image" width="700"/>'
            . '</media:group>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame('https://i/700.jpg', $bundle->media[0]->url);
    }

    public function testAtomEnclosureLinkBecomesAnAttachment(): void
    {
        $bundle = $this->extractor->extract($this->atomEntry(
            '<link rel="enclosure" type="audio/mpeg" href="https://cdn/atom.mp3" length="1000"/>',
        ));

        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://cdn/atom.mp3', $bundle->attachments[0]->url);
        self::assertSame('audio/mpeg', $bundle->attachments[0]->mimeType);
        self::assertSame(1000, $bundle->attachments[0]->sizeInBytes);
    }

    public function testAttachmentCarriesItsOwnMediaTitleWhenDeclared(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:content url="https://cdn/seg.mp3" type="audio/mpeg">'
            . '<media:title>Chapter two</media:title>'
            . '</media:content>',
        ));

        self::assertCount(1, $bundle->attachments);
        self::assertSame('Chapter two', $bundle->attachments[0]->title);
    }

    public function testTopLevelVideoYieldsAVisualAndAPlayableAttachment(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<enclosure url="https://v/clip.mp4" type="video/mp4" length="5000"/>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame(VisualMediaKind::Video, $bundle->media[0]->kind);
        self::assertSame('https://v/clip.mp4', $bundle->media[0]->url);
        self::assertNull($bundle->media[0]->previewImageUrl);
        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://v/clip.mp4', $bundle->attachments[0]->url);
        self::assertSame(5000, $bundle->attachments[0]->sizeInBytes);
    }

    public function testTopLevelNonMediaEnclosureBecomesAnAttachmentOnly(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<enclosure url="https://cdn/paper.pdf" type="application/pdf" length="900"/>',
        ));

        self::assertSame([], $bundle->media);
        self::assertCount(1, $bundle->attachments);
        self::assertSame('application/pdf', $bundle->attachments[0]->mimeType);
    }

    public function testUnknownTypelessNodeIsLeftOut(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:content url="https://x/player-page"/>',
        ));

        self::assertSame([], $bundle->media);
        self::assertSame([], $bundle->attachments);
    }

    public function testAPrefixedEnclosureIsNoAttachment(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<x:enclosure xmlns:x="urn:example:other" url="https://cdn.test/a.mp3" type="audio/mpeg"/>',
        ));

        self::assertSame([], $bundle->attachments);
    }

    public function testAnEnclosureLinkOutsideTheAtomNamespaceIsNoAttachment(): void
    {
        $bundle = $this->extractor->extract($this->atomEntry(
            '<x:link xmlns:x="urn:example:other" rel="enclosure" href="https://cdn.test/a.mp3" type="audio/mpeg"/>',
        ));

        self::assertSame([], $bundle->attachments);
    }

    public function testAGroupReadsOnlyItsMediaRssSlotsWhateverComesFirst(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:group>'
            . '<media:title>Pictures</media:title>'
            . '<media:peerLink url="https://i/peer.jpg" medium="image" width="2000"/>'
            . '<media:content url="https://i/photo.jpg" medium="image" width="800"/>'
            . '</media:group>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame('https://i/photo.jpg', $bundle->media[0]->url);
    }
}
