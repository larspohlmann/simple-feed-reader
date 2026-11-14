<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter;

use App\Entity\EntryChapter;
use App\Service\Content\Chapter\EmbedDetailsSource\EmbedDetailsSourceInterface;
use App\Service\Content\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Content\Chapter\Model\ChapteredEmbed;
use App\Service\Content\Chapter\Model\EmbedAddressModel;
use App\Service\Content\Chapter\Model\EmbedDescriptionModel;
use App\Service\Content\Chapter\Model\EmbedDetailsModel;
use App\Service\Content\Chapter\Support\ChapterSequence;
use App\Service\Content\Chapter\Support\DescriptionChapters;
use App\Service\Foundation\Text\Support\LinkedPlainText;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** An embed's title, length, artwork and description chapters, kept per embed so every reader of every post shares one fetch (#1824). */
final readonly class EmbedDetails
{
    public function __construct(
        private EmbedDetailsCache $cache,
        #[AutowireLocator('app.embed_details_source')]
        private ContainerInterface $sources,
    ) {
    }

    /** @throws EmbedDescriptionUnreachableException */
    public function of(EmbedAddressModel $embed): EmbedDetailsModel
    {
        $cached = $this->cache->find($embed);
        if ($cached !== null) {
            return $cached;
        }

        $details = self::detailsFrom($this->sourceFor($embed->provider)->detailsOf($embed));
        $this->cache->remember($embed, $details);

        return $details;
    }

    private static function detailsFrom(EmbedDescriptionModel $described): EmbedDetailsModel
    {
        return new EmbedDetailsModel(
            $described->title,
            $described->durationSeconds,
            ChapterSequence::before(self::chaptersIn($described->description), $described->durationSeconds),
            $described->artworkUrl,
        );
    }

    /** @return list<EntryChapter> */
    private static function chaptersIn(string $description): array
    {
        $html = LinkedPlainText::asHtml($description);

        return $html === null ? [] : DescriptionChapters::in($html)->chapters;
    }

    private function sourceFor(ChapteredEmbed $provider): EmbedDetailsSourceInterface
    {
        $source = $this->sources->get($provider->value);
        \assert($source instanceof EmbedDetailsSourceInterface);

        return $source;
    }
}
