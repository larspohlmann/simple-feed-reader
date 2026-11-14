<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(ArticleExtractor::class)]
final readonly class PlayerPageExtractor implements ArticleExtractorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ArticleExtractorInterface $inner,
        private EmbedProviders $embeds,
    ) {
    }

    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel
    {
        if ($this->embeds->resolve($url) !== null) {
            return ExtractionResultModel::failed($url, ExtractionFailure::PlayerPage);
        }

        return $this->inner->extract($url, $hints);
    }
}
