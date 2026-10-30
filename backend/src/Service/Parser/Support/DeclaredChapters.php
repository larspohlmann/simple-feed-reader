<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Support\ChapterClock;
use App\Service\Chapter\Support\ChapterSequence;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Model\ParsedChaptersModel;
use App\Service\Parser\Pass\CoreElement;

final class DeclaredChapters
{
    public static function of(CoreElement $item): ParsedChaptersModel
    {
        $inline = self::inline($item);
        if ($inline !== []) {
            return new ParsedChaptersModel($inline);
        }

        return new ParsedChaptersModel([], self::linkedFile($item->in(FeedNamespace::Podcast)->child('chapters')));
    }

    /** The spec names the attribute `url`; Podigee writes `href`. */
    private static function linkedFile(?\DOMElement $link): ?string
    {
        foreach (['url', 'href'] as $attribute) {
            $url = trim($link?->getAttribute($attribute) ?? '');
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /** @return list<EntryChapter> */
    private static function inline(CoreElement $item): array
    {
        $list = $item->in(FeedNamespace::PodloveSimpleChapters)->child('chapters');
        if ($list === null) {
            return [];
        }

        $chapters = [];
        foreach (CoreElement::extension($list, FeedNamespace::PodloveSimpleChapters)->children('chapter') as $chapter) {
            $start = ChapterClock::seconds($chapter->getAttribute('start'));
            $title = trim($chapter->getAttribute('title'));
            if ($start !== null && $title !== '') {
                $chapters[] = new EntryChapter($start, $title);
            }
        }

        return ChapterSequence::ordered($chapters);
    }

    private function __construct()
    {
    }
}
