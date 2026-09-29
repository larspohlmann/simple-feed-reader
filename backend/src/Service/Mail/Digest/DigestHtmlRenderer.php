<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Mail\Digest\Model\DigestEntryModel;
use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestPageGroupModel;
use App\Service\Mail\Digest\Model\DigestPageModel;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/** Shapes a capped DigestPageModel into the context of templates/emails/digest/, which own the markup and styles. */
final readonly class DigestHtmlRenderer
{
    public const string LOGO_CID = 'digestlogo';

    public function __construct(
        private Environment $twig,
        private DigestLinkBuilder $links,
        private ClockInterface $clock,
    ) {
    }

    public function render(DigestPageModel $page, DigestImageSetModel $images, string $locale): string
    {
        $dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');
        $group = fn (DigestPageGroupModel $group): array => $this->group($group, $images, $dateFormatter);

        return $this->twig->render('emails/digest/digest.html.twig', [
            'locale' => $locale,
            'today' => $this->today($locale),
            'totalCount' => $page->totalCount,
            'logoCid' => self::LOGO_CID,
            'openReaderUrl' => $this->links->savedSearchesUrl(),
            'settingsUrl' => $this->links->settingsEmailUrl(),
            'groups' => array_map($group, $page->groups),
        ]);
    }

    /** @return array<string, mixed> */
    private function group(
        DigestPageGroupModel $group,
        DigestImageSetModel $images,
        \IntlDateFormatter $dateFormatter,
    ): array {
        $card = fn (DigestEntryModel $card): array => $this->card($card, $images, $dateFormatter);

        return [
            'term' => $group->term,
            'totalCount' => $group->totalCount,
            'remaining' => $group->remaining,
            'moreUrl' => $group->moreUrl,
            'cards' => array_map($card, $group->cards),
        ];
    }

    /** @return array<string, mixed> */
    private function card(DigestEntryModel $card, DigestImageSetModel $images, \IntlDateFormatter $dateFormatter): array
    {
        return [
            'title' => $card->title,
            'feedName' => $card->feedName,
            'shortDescription' => $card->shortDescription,
            'url' => $card->url,
            'when' => $this->when($card->publishedAt, $dateFormatter),
            'imageCid' => $images->cidFor($card->imageUrl),
            'faviconCid' => $images->cidFor($card->faviconUrl),
        ];
    }

    private function today(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC');

        return (string) $formatter->format($this->clock->now());
    }

    private function when(?\DateTimeImmutable $publishedAt, \IntlDateFormatter $dateFormatter): string
    {
        return $publishedAt === null ? '' : (string) $dateFormatter->format($publishedAt);
    }
}
