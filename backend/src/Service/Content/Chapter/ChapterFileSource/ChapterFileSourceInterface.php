<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\ChapterFileSource;

use App\Entity\EntryChapter;
use App\Service\Content\Chapter\Exception\ChapterFileUnreachableException;

interface ChapterFileSourceInterface
{
    /**
     * @return list<EntryChapter> empty when the file answered without usable chapters
     *
     * @throws ChapterFileUnreachableException
     */
    public function chaptersAt(string $url): array;
}
