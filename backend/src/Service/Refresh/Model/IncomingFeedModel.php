<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Parser\Model\ParsedFeedModel;

final readonly class IncomingFeedModel
{
    public function __construct(
        public ParsedFeedModel $parsed,
        public KnownSharesModel $knownShares,
    ) {
    }

    public static function parsed(ParsedFeedModel $parsed): self
    {
        return new self($parsed, KnownSharesModel::none());
    }
}
