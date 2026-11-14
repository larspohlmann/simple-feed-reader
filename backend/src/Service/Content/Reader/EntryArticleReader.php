<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\Reader\Model\FeedMediaModel;

final readonly class EntryArticleReader
{
    public function __construct(
        private ArticleExtractorInterface $extractor,
        private ExtractionCoverageGate $coverageGate,
    ) {
    }

    public function hasPageToFetch(Entry $entry): bool
    {
        return $this->pageUrl($entry) !== null;
    }

    public function read(Entry $entry): ExtractionResultModel
    {
        $url = $this->pageUrl($entry);
        if ($url === null) {
            return ExtractionResultModel::failed(null, $this->missingPageReason($entry));
        }

        return $this->coverageGate->verify(
            $this->extractor->extract($url, $this->hints($entry)),
            $entry->getContentHtml(),
        );
    }

    private function pageUrl(Entry $entry): ?string
    {
        $url = $entry->getUrl();

        return $entry->isTitleDerived() || $url === '' ? null : $url;
    }

    private function missingPageReason(Entry $entry): ExtractionFailure
    {
        return $entry->isTitleDerived() ? ExtractionFailure::FeedBodyIsPost : ExtractionFailure::NoUrl;
    }

    private function hints(Entry $entry): EntryHintsModel
    {
        return new EntryHintsModel(
            title: $entry->getTitle(),
            author: $entry->getAuthor(),
            feedMedia: FeedMediaModel::fromEntry($entry),
        );
    }
}
