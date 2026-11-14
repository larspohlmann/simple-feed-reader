<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

use App\Entity\EntryChapter;

final readonly class ServedChaptersModel
{
    /** @param list<EntryChapter> $chapters */
    public function __construct(public array $chapters, public ?string $contentHtml)
    {
    }
}
