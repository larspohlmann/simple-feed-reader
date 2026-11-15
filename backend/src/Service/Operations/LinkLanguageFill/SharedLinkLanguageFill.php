<?php

declare(strict_types=1);

namespace App\Service\Operations\LinkLanguageFill;

use App\Entity\LinkLanguage;
use App\Entity\SharedLink;
use App\Repository\SharedLinkRepository;
use App\Service\Ingestion\Language\PreviewLanguage;
use App\Service\Operations\LinkLanguageFill\Model\LinkLanguageFillReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** One-off (#2239): gives every stored shared link without a language the one its preview decides, if any. */
final readonly class SharedLinkLanguageFill
{
    public function __construct(
        private SharedLinkRepository $sharedLinks,
        private PreviewLanguage $previewLanguage,
        private EntityManagerInterface $entityManager,
        private int $batchSize = 500,
    ) {
    }

    public function count(): LinkLanguageFillReportModel
    {
        return $this->walk(static function (): void {
        });
    }

    public function fill(): LinkLanguageFillReportModel
    {
        return $this->walk(static fn (SharedLink $link, LinkLanguage $language) => $link->settleLanguage($language));
    }

    private function walk(\Closure $settle): LinkLanguageFillReportModel
    {
        $report = new LinkLanguageFillReportModel();
        $afterId = 0;
        do {
            $batch = $this->sharedLinks->unsetLanguageAfterId($afterId, $this->batchSize);
            $afterId = $batch === [] ? $afterId : $batch[array_key_last($batch)]->requireId();
            $report = $report->plus($this->entityManager->wrapInTransaction(
                fn (): LinkLanguageFillReportModel => $this->batch($batch, $settle),
            ));
            $this->entityManager->clear();
        } while (\count($batch) === $this->batchSize);

        return $report;
    }

    /** @param list<SharedLink> $batch */
    private function batch(array $batch, \Closure $settle): LinkLanguageFillReportModel
    {
        $report = new LinkLanguageFillReportModel();
        foreach ($batch as $link) {
            $language = $this->previewLanguage->of($link->preview(), null);
            if ($language === null) {
                $report = $report->leftShort();
                continue;
            }
            $settle($link, $language);
            $report = $report->filled($language->language);
        }

        return $report;
    }
}
