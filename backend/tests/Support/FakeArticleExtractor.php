<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\EntryHints;
use App\Service\Reader\ExtractionResult;

/**
 * Test double for the reader endpoint: returns a preconfigured outcome and
 * records every call, so a test can both control the response and assert the
 * extractor was (or was NOT) invoked without any outbound network I/O.
 */
final class FakeArticleExtractor implements ArticleExtractorInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<EntryHints> */
    public array $hints = [];

    private ?ExtractionResult $result = null;

    public function willReturn(ExtractionResult $result): void
    {
        $this->result = $result;
    }

    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult
    {
        $this->calls[] = $url;
        $this->hints[] = $hints;

        return $this->result
            ?? throw new \LogicException('FakeArticleExtractor::extract called without a configured result.');
    }
}
