<?php

declare(strict_types=1);

namespace App\Service\Content\Transcript;

use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Service\Content\Transcript\Exception\TranscriptUnusableException;
use App\Service\Content\Transcript\Model\ReadTranscriptModel;
use App\Service\Content\Transcript\Support\JsonCues;
use App\Service\Content\Transcript\Support\TimedCues;
use App\Service\Content\Transcript\Support\TranscriptParagraphs;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\Support\PlainText;

final readonly class TranscriptReader
{
    public function __construct(private EntrySanitizer $sanitizer)
    {
    }

    /** @throws TranscriptUnusableException */
    public function read(string $body, TranscriptFormat $format): ReadTranscriptModel
    {
        $paragraphs = $this->parseParagraphs(mb_scrub($body, 'UTF-8'), $format);
        if ($paragraphs === []) {
            throw TranscriptUnusableException::empty();
        }

        return new ReadTranscriptModel($paragraphs, TranscriptParagraphs::toSearchText($paragraphs));
    }

    /**
     * @return list<TranscriptParagraph>
     *
     * @throws TranscriptUnusableException
     */
    private function parseParagraphs(string $body, TranscriptFormat $format): array
    {
        return match ($format) {
            TranscriptFormat::Vtt, TranscriptFormat::Srt => TranscriptParagraphs::from(TimedCues::parseCues($body)),
            TranscriptFormat::Json => TranscriptParagraphs::from(JsonCues::parseCues($body)),
            TranscriptFormat::Html => $this->htmlParagraphs($body),
        };
    }

    /** @return list<TranscriptParagraph> */
    private function htmlParagraphs(string $body): array
    {
        return array_map(
            static fn (string $line): TranscriptParagraph => new TranscriptParagraph(null, null, $line),
            PlainText::linesFromHtmlBlocks($this->sanitizer->sanitize($body)),
        );
    }
}
