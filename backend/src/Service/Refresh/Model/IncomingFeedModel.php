<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Social\Model\TimelineThreadsModel;

final readonly class IncomingFeedModel
{
    public function __construct(
        public ParsedFeedModel $parsed,
        public KnownSharesModel $knownShares,
        public TimelineThreadsModel $threads,
    ) {
    }

    public static function parsed(ParsedFeedModel $parsed): self
    {
        return new self($parsed, KnownSharesModel::none(), TimelineThreadsModel::none());
    }

    public function withParsed(ParsedFeedModel $parsed): self
    {
        return new self($parsed, $this->knownShares, $this->threads);
    }
}
