<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use Twig\Environment;

/**
 * Renders a capped DigestPage to the airy/light HTML email (#726) from Twig
 * templates in templates/emails/digest/. The layout is fluid under a device-width
 * viewport so iPhone Mail keeps it readable (#886); an Outlook ghost table holds
 * the 600px column on Outlook desktop. Styles live in a single <style> block and
 * are inlined at render by twig/cssinliner-extra. This service only shapes a view
 * context; the markup and its classes live in the templates.
 */
final readonly class DigestHtmlRenderer
{
    public const string LOGO_CID = 'digestlogo';

    public function __construct(
        private Environment $twig,
        private DigestLinkBuilder $links,
    ) {
    }

    public function render(DigestPage $page, DigestImageSet $images, string $locale): string
    {
        $dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');
        $group = fn (DigestPageGroup $group): array => $this->group($group, $images, $dateFormatter);

        return $this->twig->render('emails/digest/digest.html.twig', [
            'locale' => $locale,
            'today' => $this->today($locale),
            'totalCount' => $page->totalCount,
            'logoCid' => self::LOGO_CID,
            'openReaderUrl' => $this->links->base(),
            'settingsUrl' => $this->links->settingsEmailUrl(),
            'groups' => array_map($group, $page->groups),
        ]);
    }

    /** @return array<string, mixed> */
    private function group(DigestPageGroup $group, DigestImageSet $images, \IntlDateFormatter $dateFormatter): array
    {
        $card = fn (DigestEntry $card): array => $this->card($card, $images, $dateFormatter);

        return [
            'term' => $group->term,
            'totalCount' => $group->totalCount,
            'remaining' => $group->remaining,
            'moreUrl' => $group->moreUrl,
            'cards' => array_map($card, $group->cards),
        ];
    }

    /** @return array<string, mixed> */
    private function card(DigestEntry $card, DigestImageSet $images, \IntlDateFormatter $dateFormatter): array
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

        return (string) $formatter->format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function when(?\DateTimeImmutable $publishedAt, \IntlDateFormatter $dateFormatter): string
    {
        return $publishedAt === null ? '' : (string) $dateFormatter->format($publishedAt);
    }
}
