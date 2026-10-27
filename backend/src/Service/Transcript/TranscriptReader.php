<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Enum\TranscriptFormat;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\Support\PlainText;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\ReadTranscriptModel;
use App\Service\Transcript\Model\TranscriptCueModel;
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
        $paragraphs = TranscriptParagraphs::from($this->cuesOf($body, $format));
        if ($paragraphs === []) {
            throw TranscriptUnusableException::empty();
        }

        return new ReadTranscriptModel($paragraphs, TranscriptParagraphs::searchTextOf($paragraphs));
    }

    /**
     * @return list<TranscriptCueModel>
     *
     * @throws TranscriptUnusableException
     */
    private function cuesOf(string $body, TranscriptFormat $format): array
    {
        return match ($format) {
            TranscriptFormat::Vtt, TranscriptFormat::Srt => TimedCues::of($body),
            TranscriptFormat::Json => JsonCues::of($body),
            TranscriptFormat::Html => $this->htmlCues($body),
        };
    }

    /** @return list<TranscriptCueModel> */
    private function htmlCues(string $body): array
    {
        return array_map(
            static fn (string $line): TranscriptCueModel => new TranscriptCueModel(null, null, $line),
            PlainText::linesFromHtmlBlocks($this->sanitizer->sanitize($body)),
        );
    }
}
