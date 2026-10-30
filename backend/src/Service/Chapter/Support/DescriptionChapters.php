<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Model\ChapterRunRule;
use App\Service\Chapter\Model\ServedChaptersModel;
use App\Service\Chapter\Pass\DescriptionLine;
use App\Service\Html\Support\HtmlDocumentParser;
use Dom\Element;
use Dom\Text;

/** A description's first run of three or more ascending chapter lines, stamped alike, taken out of its body. */
final class DescriptionChapters
{
    private const int MINIMUM = 3;

    /** YouTube's chapter rule: the run starts at `0:00`. */
    public static function in(string $html): ServedChaptersModel
    {
        return self::read($html, ChapterRunRule::YouTube, null);
    }

    /** A show often opens on an intro its notes leave out, so an episode's run may start later. */
    public static function inEpisode(string $html, ?int $durationInSeconds): ServedChaptersModel
    {
        return self::read($html, ChapterRunRule::Episode, $durationInSeconds);
    }

    private static function read(string $html, ChapterRunRule $rule, ?int $endSeconds): ServedChaptersModel
    {
        if (ChapterLine::holdsNoStamp($html)) {
            return new ServedChaptersModel([], $html);
        }

        $document = HtmlDocumentParser::parseFragment($html);
        $run = self::startingBefore(self::firstRun(DescriptionLines::of($document), $rule), $endSeconds);
        if (\count($run) < self::MINIMUM) {
            return new ServedChaptersModel([], $html);
        }

        $chapters = array_map(static fn (array $pair): EntryChapter => $pair[1], $run);
        foreach ($run as [$line]) {
            $line->remove();
        }
        foreach (self::blocksOf($run) as $block) {
            self::tidy($block);
        }

        return new ServedChaptersModel($chapters, $document->body->innerHTML ?? $html);
    }

    /**
     * @param list<DescriptionLine> $lines
     *
     * @return list<array{DescriptionLine, EntryChapter}>
     */
    private static function firstRun(array $lines, ChapterRunRule $rule): array
    {
        $run = [];
        foreach ($lines as $line) {
            $text = $line->text();
            if (self::passesOver($rule, $run, $line, $text)) {
                continue;
            }
            $chapter = ChapterLine::chapterOf($text);
            if ($chapter !== null && self::continues($run, $chapter, $text)) {
                $run[] = [$line, $chapter];
                continue;
            }
            if (self::isChapterBlock($run, $rule)) {
                return $run;
            }
            $run = $chapter === null ? [] : [[$line, $chapter]];
        }

        return self::isChapterBlock($run, $rule) ? $run : [];
    }

    /**
     * @param list<array{DescriptionLine, EntryChapter}> $run
     *
     * @return list<array{DescriptionLine, EntryChapter}>
     */
    private static function startingBefore(array $run, ?int $endSeconds): array
    {
        $chapters = array_map(static fn (array $pair): EntryChapter => $pair[1], $run);

        return \array_slice($run, 0, \count(ChapterSequence::before($chapters, $endSeconds)));
    }

    /** @param list<array{DescriptionLine, EntryChapter}> $run */
    private static function passesOver(ChapterRunRule $rule, array $run, DescriptionLine $line, string $text): bool
    {
        return $rule->skipsUnstampedLines()
            && ChapterLine::holdsNoStamp($text)
            && ($run === [] || $line->block === $run[\count($run) - 1][0]->block);
    }

    /** @param list<array{DescriptionLine, EntryChapter}> $run */
    private static function continues(array $run, EntryChapter $chapter, string $text): bool
    {
        if ($run === []) {
            return true;
        }

        return $chapter->startSeconds > $run[\count($run) - 1][1]->startSeconds
            && ChapterLine::positionOf($text) === ChapterLine::positionOf($run[0][0]->text());
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
    private static function isChapterBlock(array $run, ChapterRunRule $rule): bool
    {
        return \count($run) >= self::MINIMUM && $rule->admitsStart($run[0][1]->startSeconds);
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
        if (
            $block->localName === 'body'
            || trim((string) $block->textContent) !== ''
            || $block->firstElementChild !== null
        ) {
            return;
        }
        $parent = $block->parentElement;
        $block->remove();
        if ($parent !== null && \in_array($parent->localName, ['p', 'li', 'ul', 'ol'], true)) {
            self::dropIfEmpty($parent);
        }
    }

    private function __construct()
    {
    }
}
