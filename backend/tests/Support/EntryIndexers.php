<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** An EntryIndexer over a given index writer and no transcript texts: the assembly most tests need. */
final class EntryIndexers
{
    private function __construct()
    {
    }

    public static function with(SearchIndexWriterInterface $writer, ?LoggerInterface $logger = null): EntryIndexer
    {
        return new EntryIndexer($writer, new NoTranscriptTexts(), $logger ?? new NullLogger());
    }
}
