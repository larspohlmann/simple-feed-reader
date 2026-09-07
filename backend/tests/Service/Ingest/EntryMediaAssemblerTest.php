<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Service\Image\DeclaredImage;
use App\Service\Ingest\EntryMediaAssembler;
use App\Service\Parser\ParsedAttachment;
use App\Service\Parser\ParsedMedium;
use App\Service\Parser\VisualMediaKind;
use PHPUnit\Framework\TestCase;

final class EntryMediaAssemblerTest extends TestCase
{
    public function testLeadImageBecomesTheFirstMedium(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            new DeclaredImage('https://i/lead.jpg', 800, 600),
            [new ParsedMedium('https://i/extra.jpg', VisualMediaKind::Image)],
            [],
        );

        self::assertCount(2, $assembled->media);
        self::assertSame('https://i/lead.jpg', $assembled->media[0]->url);
        self::assertSame('image', $assembled->media[0]->kind);
        self::assertSame(800, $assembled->media[0]->width);
        self::assertSame('https://i/extra.jpg', $assembled->media[1]->url);
    }

    public function testAMediumMatchingTheLeadIsNotDuplicated(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            new DeclaredImage('https://i/lead.jpg', 800, 600),
            [new ParsedMedium('https://i/lead.jpg', VisualMediaKind::Image, 800, 600)],
            [],
        );

        self::assertCount(1, $assembled->media);
        self::assertSame('https://i/lead.jpg', $assembled->media[0]->url);
    }

    public function testNonHttpsMediumUrlIsDropped(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            null,
            [new ParsedMedium('http://i/insecure.jpg', VisualMediaKind::Image), new ParsedMedium('https://i/ok.jpg', VisualMediaKind::Image)],
            [],
        );

        self::assertCount(1, $assembled->media);
        self::assertSame('https://i/ok.jpg', $assembled->media[0]->url);
    }

    public function testAVideoPosterOnHttpIsDroppedButTheVideoStays(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            null,
            [new ParsedMedium('https://v/clip.mp4', VisualMediaKind::Video, null, null, 'http://v/poster.jpg')],
            [],
        );

        self::assertCount(1, $assembled->media);
        self::assertSame('https://v/clip.mp4', $assembled->media[0]->url);
        self::assertNull($assembled->media[0]->previewImageUrl);
    }

    public function testAttachmentsAreGatedAndMapped(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            null,
            [],
            [
                new ParsedAttachment('http://cdn/insecure.mp3', 'audio/mpeg'),
                new ParsedAttachment('https://cdn/ok.mp3', 'audio/mpeg', 3723, 4200000, 'Ep 1'),
            ],
        );

        self::assertCount(1, $assembled->attachments);
        self::assertSame('https://cdn/ok.mp3', $assembled->attachments[0]->url);
        self::assertSame(3723, $assembled->attachments[0]->durationInSeconds);
        self::assertSame('Ep 1', $assembled->attachments[0]->title);
    }

    public function testWhenTheLeadFailsTheGateTheNextImageLeads(): void
    {
        $assembled = EntryMediaAssembler::assemble(
            new DeclaredImage('http://i/insecure-lead.jpg'),
            [new ParsedMedium('https://i/ok.jpg', VisualMediaKind::Image)],
            [],
        );

        self::assertCount(1, $assembled->media);
        self::assertSame('https://i/ok.jpg', $assembled->media[0]->url);
    }
}
