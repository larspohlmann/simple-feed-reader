<?php

declare(strict_types=1);

namespace App\Service\Transcript\Pass;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;

final class ParagraphRun
{
    private const int CLOSING_LENGTH = 400;
    private const int FORCED_CLOSING_LENGTH = 2000;
    private const string SENTENCE_END = '/[.?!…]["\'”’»)\]]*$/u';

    /** @var list<TranscriptParagraph> */
    private array $paragraphs = [];
    private ?TranscriptCueModel $opening = null;
    private ?string $openSpeaker = null;
    /** @var list<string> */
    private array $openParts = [];
    private int $openLength = 0;
    private ?string $carriedSpeaker = null;

    public function add(TranscriptCueModel $cue): void
    {
        if ($this->opening !== null && $this->joins($cue)) {
            $this->append($cue->text, 1);
        } else {
            $this->close();
            $this->open($cue);
            $this->carriedSpeaker = null;
        }

        if ($this->isComplete($cue->text)) {
            $this->carriedSpeaker = $this->openSpeaker;
            $this->close();
        }
    }

    /** @return list<TranscriptParagraph> */
    public function finish(): array
    {
        $this->close();

        return $this->paragraphs;
    }

    private function open(TranscriptCueModel $cue): void
    {
        $this->opening = $cue;
        $this->openSpeaker = $cue->speaker ?? $this->carriedSpeaker;
        $this->openParts = [];
        $this->openLength = 0;
        $this->append($cue->text, 0);
    }

    private function append(string $text, int $separatorLength): void
    {
        $this->openParts[] = $text;
        $this->openLength += $separatorLength + mb_strlen($text);
    }

    private function joins(TranscriptCueModel $cue): bool
    {
        return $cue->speaker === null || $cue->speaker === $this->openSpeaker;
    }

    private function isComplete(string $lastCueText): bool
    {
        return $this->openLength >= self::FORCED_CLOSING_LENGTH
            || ($this->openLength >= self::CLOSING_LENGTH && preg_match(self::SENTENCE_END, $lastCueText) === 1);
    }

    private function close(): void
    {
        if ($this->opening !== null) {
            $this->paragraphs[] = new TranscriptParagraph(
                $this->opening->startSeconds,
                $this->openSpeaker,
                implode(' ', $this->openParts),
            );
            $this->opening = null;
        }
    }
}
