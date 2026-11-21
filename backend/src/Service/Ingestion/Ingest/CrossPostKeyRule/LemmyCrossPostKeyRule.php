<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\CrossPostKeyRule;

use App\Entity\CanonicalItem;
use App\Enum\CanonicalItemKind;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Ingest\Model\CrossPostModel;
use App\Service\Ingestion\Ingest\Support\CrossPostHash;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

final readonly class LemmyCrossPostKeyRule implements CrossPostKeyRuleInterface
{
    public function __construct(
        private UrlNormalizer $urlNormalizer,
    ) {
    }

    public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): ?string
    {
        if ($item->getKind() !== CanonicalItemKind::Lemmy) {
            return null;
        }

        return CrossPostHash::build(new CrossPostModel(
            $parsed->authorUrl,
            $this->urlNormalizer->normalize($parsed->linkedUrl),
            $parsed->headline->text,
            $parsed->contentHtml,
        ));
    }
}
