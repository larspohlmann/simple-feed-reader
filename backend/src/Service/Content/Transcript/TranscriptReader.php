<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\Support\PlainText;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\ReadTranscriptModel;
use App\Service\Transcript\Support\JsonCues;
use App\Service\Transcript\Support\TimedCues;
use App\Service\Transcript\Support\TranscriptParagraphs;

final readonly class TranscriptReader
{
    public function __construct(private EntrySanitizer $sanitizer)
    {
    }

    /** @throws TranscriptUnusableException */
    public function read(string $body, TranscriptFormat $format): ReadTranscriptModel
    {
        $paragraphs = $this->paragraphsOf(mb_scrub($body, 'UTF-8'), $format);
        if ($paragraphs === []) {
            throw TranscriptUnusableException::empty();
        }

        return new ReadTranscriptModel($paragraphs, TranscriptParagraphs::searchTextOf($paragraphs));
    }

    /**
     * @return list<TranscriptParagraph>
     *
     * @throws TranscriptUnusableException
     */
    private function paragraphsOf(string $body, TranscriptFormat $format): array
    {
        return match ($format) {
            TranscriptFormat::Vtt, TranscriptFormat::Srt => TranscriptParagraphs::from(TimedCues::of($body)),
            TranscriptFormat::Json => TranscriptParagraphs::from(JsonCues::of($body)),
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
