<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\SharedLink;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use App\Service\Content\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Content\Reader\Model\ExtractionResultModel;
use App\Service\Content\Reader\Model\PagePreviewModel;
use App\Service\Ingestion\Language\PreviewLanguage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opens a shared link's article and refreshes its stored row from the page just read. Only an ok extraction
 * refreshes, so a consent wall or a bot challenge, which fail, never replace a good preview; no row is created.
 */
final readonly class LinkArticleReader
{
    public function __construct(
        private ArticleExtractorInterface $extractor,
        private SharedLinkWriter $linkWriter,
        private PreviewLanguage $previewLanguage,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function extract(string $url): ExtractionResultModel
    {
        $result = $this->extractor->extract($url);
        if (!$result->ok || $result->preview === null) {
            return $result;
        }
        $link = $this->linkWriter->existing($url);
        if ($link !== null) {
            $this->refresh($link, $result->preview);
        }

        return $result;
    }

    private function refresh(SharedLink $link, PagePreviewModel $preview): void
    {
        $card = StorableLinkCard::of($preview->card());
        if ($card === null) {
            return;
        }
        $link->refresh($card);
        $language = $this->previewLanguage->resolveLanguage($card, $preview->language);
        if ($language !== null) {
            $link->settleLanguage($language);
        }
        $this->entityManager->flush();
    }
}
