<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

use App\Repository\EntryListRow;

/**
 * The rows plus the words actually matched, one shape for every implementation: LIKE adds nothing to the query's
 * words, an engine that stems or expands them can.
 */
final readonly class EntrySearchResultModel
{
    /** The ids the read matched before hydration dropped any; count($rows) unless the indexed search passes its own. */
    public int $matchCount;

    /**
     * @param list<EntryListRow> $rows
     * @param list<string>       $matchedWords
     * @param EntryListRow|null  $continuationRow the candidate the next cursor resumes past, when the indexed unread
     *                                            search shows fewer rows than the engine paged (EntryPage)
     */
    public function __construct(
        public array $rows,
        public array $matchedWords,
        ?int $matchCount = null,
        public ?EntryListRow $continuationRow = null,
    ) {
        $this->matchCount = $matchCount ?? \count($rows);
    }

    /** @param list<EntryListRow> $rows */
    public static function rowsOnly(array $rows): self
    {
        return new self($rows, []);
    }

    /** @param list<EntryListRow> $rows */
    public function withRows(array $rows): self
    {
        return new self($rows, $this->matchedWords, $this->matchCount, $this->continuationRow);
    }
}
