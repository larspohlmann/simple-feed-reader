<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Model\ServedChaptersModel;
use App\Service\Chapter\Pass\DescriptionLine;
use App\Service\Html\Support\HtmlDocumentParser;
use Dom\Element;
use Dom\Text;

/** YouTube's chapter rule: the first run of three or more ascending chapter lines from `0:00`, stamped alike. */
final class DescriptionChapters
{
    private const int MINIMUM = 3;

    public static function in(string $html): ServedChaptersModel
    {
        if (!str_contains($html, '0:00')) {
            return new ServedChaptersModel([], $html);
        }

        $document = HtmlDocumentParser::parseFragment($html);
        $run = self::firstRun(DescriptionLines::of($document));
        if ($run === []) {
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
    private static function firstRun(array $lines): array
    {
        $run = [];
        foreach ($lines as $line) {
            $text = $line->text();
            if (ChapterLine::holdsNoStamp($text) && self::staysInTheBlockOf($run, $line)) {
                continue;
            }
            $chapter = ChapterLine::chapterOf($text);
            if ($chapter !== null && self::continues($run, $chapter, $text)) {
                $run[] = [$line, $chapter];
                continue;
            }
            if (self::isChapterBlock($run)) {
                return $run;
            }
            $run = $chapter === null ? [] : [[$line, $chapter]];
        }

        return self::isChapterBlock($run) ? $run : [];
    }

    /** @param list<array{DescriptionLine, EntryChapter}> $run */
    private static function staysInTheBlockOf(array $run, DescriptionLine $line): bool
    {
        return $run === [] || $line->block === $run[\count($run) - 1][0]->block;
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
    private static function isChapterBlock(array $run): bool
    {
        return \count($run) >= self::MINIMUM && $run[0][1]->startSeconds === 0;
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
