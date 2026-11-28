<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

final readonly class SearchTermModel
{
    /** @param list<array{string, string}> $words each kept word with its bare form; none when it is only stop words */
    public function __construct(public string $term, public array $words, public bool $isSingleWord)
    {
    }
}
