<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

final readonly class TimelinePageModel
{
    public function __construct(
        public ParsedFeedModel $parsed,
        public KnownSharesModel $knownShares,
        public ?string $newestId,
        /** @var list<TimelineSelfReplyModel> */
        public array $selfReplies = [],
    ) {
    }

    /**
     * No title or site: EntryIngestor keeps what the private feed was created with only while the page names none.
     *
     * @param list<ParsedEntryModel> $entries
     */
    public static function of(array $entries, KnownSharesModel $knownShares, ?string $newestId): self
    {
        return new self(new ParsedFeedModel(null, null, null, null, $entries), $knownShares, $newestId);
    }

    /** @param list<TimelineSelfReplyModel> $selfReplies */
    public function withSelfReplies(array $selfReplies): self
    {
        return new self($this->parsed, $this->knownShares, $this->newestId, $selfReplies);
    }
}
