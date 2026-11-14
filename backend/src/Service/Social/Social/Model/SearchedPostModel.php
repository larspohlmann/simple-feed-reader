<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

/** A search result as the reader judges it: every name its author goes by, and the texts that are its own. */
final readonly class SearchedPostModel
{
    /** @var list<string> */
    public array $lowerCasedTexts;

    /** @var list<string> */
    private array $authorNames;

    /**
     * @param list<string> $authorNames
     * @param list<string> $texts
     */
    public function __construct(array $authorNames, array $texts)
    {
        $this->authorNames = array_map(self::authorName(...), $authorNames);
        $this->lowerCasedTexts = array_map(mb_strtolower(...), $texts);
    }

    public function isBy(string $author): bool
    {
        return \in_array(self::authorName($author), $this->authorNames, true);
    }

    private static function authorName(string $name): string
    {
        return mb_strtolower(ltrim($name, '@'));
    }
}
