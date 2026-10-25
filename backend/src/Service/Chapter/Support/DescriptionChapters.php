<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Model\DescribedChaptersModel;
use App\Service\Chapter\Pass\DescriptionLine;
use App\Service\Html\Support\HtmlDocumentParser;
use Dom\Element;
use Dom\Text;

/** YouTube's chapter rule over a video description: the first `0:00`-led block of three or more ascending stamps. */
final class DescriptionChapters
{
    private const string LINE = '/^\s*[(\[]?((?:\d{1,2}:)?\d{1,2}:\d{2})(?!\d)[)\]]?\s*[-–—:|]?\s*(\S.*?)\s*$/u';
    private const int MINIMUM = 3;

    public static function in(string $html): DescribedChaptersModel
    {
        $document = HtmlDocumentParser::parseFragment($html);
        $run = self::firstRun(DescriptionLines::of($document));
        if ($run === []) {
            return new DescribedChaptersModel([], $html);
        }

        $chapters = array_map(static fn (array $pair): EntryChapter => $pair[1], $run);
        foreach ($run as [$line]) {
            $line->remove();
        }
        foreach (self::blocksOf($run) as $block) {
            self::tidy($block);
        }

        return new DescribedChaptersModel($chapters, $document->body->innerHTML ?? $html);
    }

    /**
     * @param list<DescriptionLine> $lines
     *
     * @return list<array{DescriptionLine, EntryChapter}>
     */
    private static function firstRun(array $lines): array
    {
        $run = [];
        foreach ([...$lines, null] as $line) {
            $chapter = $line === null ? null : self::chapterOf($line->text());
            if ($line !== null && $chapter !== null) {
                $run[] = [$line, $chapter];
                continue;
            }
            if (self::isChapterBlock($run)) {
                return $run;
            }
            $run = [];
        }

        return [];
    }

    /**
     * @param list<array{DescriptionLine, EntryChapter}> $run
     *
     * @return list<Element>
     */
    private static function blocksOf(array $run): array
    {
        $blocks = [];
        foreach ($run as [$line]) {
            if (!\in_array($line->block, $blocks, true)) {
                $blocks[] = $line->block;
            }
        }

        return $blocks;
    }

    /** @param list<array{DescriptionLine, EntryChapter}> $run */
    private static function isChapterBlock(array $run): bool
    {
        $chapters = array_map(static fn (array $pair): EntryChapter => $pair[1], $run);

        return \count($chapters) >= self::MINIMUM
            && $chapters[0]->startSeconds === 0
            && ChapterSequence::ascends($chapters);
    }

    private static function chapterOf(string $text): ?EntryChapter
    {
        if (preg_match(self::LINE, $text, $match) !== 1) {
            return null;
        }
        $start = ChapterClock::seconds($match[1]);

        return $start === null ? null : new EntryChapter($start, $match[2]);
    }

    private static function tidy(Element $block): void
    {
        $last = $block->lastChild;
        while ($last instanceof Text && trim($last->data) === '') {
            $last = $last->previousSibling;
        }
        if ($last instanceof Element && $last->localName === 'br') {
            $last->remove();
        }
        self::dropIfEmpty($block);
    }

    private static function dropIfEmpty(Element $block): void
    {
        if (trim((string) $block->textContent) !== '' || $block->firstElementChild !== null) {
            return;
        }
        $parent = $block->parentElement;
        $block->remove();
        if ($parent !== null && \in_array($parent->localName, ['p', 'li'], true)) {
            self::dropIfEmpty($parent);
        }
    }

    private function __construct()
    {
    }
}
