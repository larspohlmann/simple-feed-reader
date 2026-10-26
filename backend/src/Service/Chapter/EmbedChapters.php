<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\EntryChapter;
use App\Service\Chapter\EmbedDescriptionSource\EmbedDescriptionSourceInterface;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Support\DescriptionChapters;
use App\Service\Text\Support\LinkedPlainText;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** An embed's chapters from its description, kept per embed so every reader of every post shares one fetch (#1824). */
final readonly class EmbedChapters
{
    public function __construct(
        private EmbedChaptersCache $cache,
        #[AutowireLocator('app.embed_description_source', defaultIndexMethod: 'provider')]
        private ContainerInterface $sources,
    ) {
    }

    /**
     * @return list<EntryChapter>
     *
     * @throws EmbedDescriptionUnreachableException
     */
    public function of(EmbedAddressModel $embed): array
    {
        $cached = $this->cache->find($embed);
        if ($cached !== null) {
            return $cached;
        }

        $chapters = self::chaptersIn($this->sourceFor($embed->provider)->descriptionOf($embed));
        $this->cache->remember($embed, $chapters);

        return $chapters;
    }

    /** @return list<EntryChapter> */
    private static function chaptersIn(string $description): array
    {
        $html = LinkedPlainText::asHtml($description);

        return $html === null ? [] : DescriptionChapters::in($html)->chapters;
    }

    private function sourceFor(ChapteredEmbed $provider): EmbedDescriptionSourceInterface
    {
        $source = $this->sources->get($provider->value);
        \assert($source instanceof EmbedDescriptionSourceInterface);

        return $source;
    }
}
