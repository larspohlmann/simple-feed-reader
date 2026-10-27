<?php

declare(strict_types=1);

namespace App\Service\Transcript\Pass;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;

final class ParagraphRun
{
    private const int CLOSING_LENGTH = 400;
    private const string SENTENCE_END = '/[.?!…]["\'”’»)\]]*$/u';

    /** @var list<TranscriptParagraph> */
    private array $paragraphs = [];
    private ?TranscriptParagraph $open = null;
    private ?string $carriedSpeaker = null;

    public function add(TranscriptCueModel $cue): void
    {
        if ($this->open !== null && $this->joins($cue, $this->open)) {
            $this->open = new TranscriptParagraph(
                $this->open->startSeconds,
                $this->open->speaker,
                $this->open->text . ' ' . $cue->text,
            );
        } else {
            $this->close();
            $speaker = $cue->speaker ?? $this->carriedSpeaker;
            $this->open = new TranscriptParagraph($cue->startSeconds, $speaker, $cue->text);
            $this->carriedSpeaker = null;
        }

        if ($this->isComplete($this->open)) {
            $this->carriedSpeaker = $this->open->speaker;
            $this->close();
        }
    }

    /** @return list<TranscriptParagraph> */
    public function finish(): array
    {
        $this->close();

        return $this->paragraphs;
    }

    private function joins(TranscriptCueModel $cue, TranscriptParagraph $open): bool
    {
        return $cue->speaker === null || $cue->speaker === $open->speaker;
    }

    private function isComplete(TranscriptParagraph $paragraph): bool
    {
        return mb_strlen($paragraph->text) >= self::CLOSING_LENGTH
            && preg_match(self::SENTENCE_END, $paragraph->text) === 1;
    }

    private function close(): void
    {
        if ($this->open !== null) {
            $this->paragraphs[] = $this->open;
            $this->open = null;
        }
    }
}
