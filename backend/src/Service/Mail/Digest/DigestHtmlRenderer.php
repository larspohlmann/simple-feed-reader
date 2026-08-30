<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a capped DigestPage to the airy/light HTML email (#726). Table-based
 * for Outlook, every style inlined, light-only. The whole card is the reader
 * deep link; images are referenced by their CID from the DigestImageSet.
 */
final readonly class DigestHtmlRenderer
{
    public const string LOGO_CID = 'digestlogo';

    public function __construct(
        private TranslatorInterface $translator,
        private DigestLinkBuilder $links,
    ) {
    }

    public function render(DigestPage $page, DigestImageSet $images, string $locale): string
    {
        $dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');
        $render = fn (DigestPageGroup $group): string => $this->group($group, $images, $locale, $dateFormatter);
        $groups = array_map($render, $page->groups);

        $body = $this->header($page->totalCount, $locale)
            . $this->intro($locale)
            . implode('', $groups)
            . $this->footer($locale);

        return $this->document($body);
    }

    private function document(string $body): string
    {
        $outer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;">';
        $font = 'system-ui,-apple-system,\'Segoe UI\',roboto,sans-serif';
        $sheet = '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;font-family:' . $font . ';color:#2a2a2a;">';

        return '<!doctype html><html><body style="margin:0;background:#f5f5f4;">'
            . $outer . '<tr><td align="center" style="padding:24px 12px;">'
            . $sheet . $body
            . '</table></td></tr></table></body></html>';
    }

    private function header(int $totalCount, string $locale): string
    {
        $parameters = ['%date%' => $this->today($locale), '%count%' => (string) $totalCount];
        $line = $this->trans('digest.header', $parameters, $locale);

        $logo = '<img src="cid:' . self::LOGO_CID . '" width="20" height="20" alt="" '
            . 'style="display:inline-block;width:20px;height:20px;vertical-align:middle;margin-right:8px;border:0;">';

        return '<tr><td style="padding:24px 24px 18px;border-bottom:1px solid #e4e4e2;">'
            . $logo . '<span style="font-size:15px;font-weight:600;color:#2a2a2a;">simple feed reader</span>'
            . '<div style="margin-top:14px;font-size:13px;color:#8f8f8b;">' . $this->escapeText($line) . '</div>'
            . '</td></tr>';
    }

    private function intro(string $locale): string
    {
        return '<tr><td style="padding:16px 24px 0;font-size:14px;line-height:1.5;color:#5f5f5c;">'
            . $this->escapeText($this->trans('digest.intro', [], $locale)) . '</td></tr>';
    }

    private function group(
        DigestPageGroup $group,
        DigestImageSet $images,
        string $locale,
        \IntlDateFormatter $dateFormatter,
    ): string {
        $parameters = ['%term%' => $group->term, '%count%' => (string) $group->totalCount];
        $heading = $this->trans('digest.group_heading', $parameters, $locale);
        $cards = $this->cards($group->cards, $images, $locale, $dateFormatter);
        $more = $group->remaining > 0 ? $this->moreLink($group, $locale) : '';
        $headingStyle = 'padding-bottom:10px;border-bottom:1px solid #e4e4e2;font-size:13px;'
            . 'font-weight:600;color:#5f5f5c;';

        return '<tr><td style="padding:20px 24px 4px;">'
            . '<div style="' . $headingStyle . '">'
            . $this->escapeText($heading) . '</div>'
            . $cards . $more . '</td></tr>';
    }

    /** @param list<DigestEntry> $cards */
    private function cards(
        array $cards,
        DigestImageSet $images,
        string $locale,
        \IntlDateFormatter $dateFormatter,
    ): string {
        $html = '';
        foreach ($cards as $index => $card) {
            $html .= $this->cardSeparator($index) . $this->card($card, $images, $locale, $dateFormatter);
        }

        return $html;
    }

    /** Spaces the heading's own underline from the first card; a hairline between cards after that. */
    private function cardSeparator(int $index): string
    {
        if ($index === 0) {
            return '<div style="padding-top:20px;"></div>';
        }

        return '<div style="margin-top:20px;padding-top:20px;border-top:1px solid #e4e4e2;"></div>';
    }

    private function card(
        DigestEntry $card,
        DigestImageSet $images,
        string $locale,
        \IntlDateFormatter $dateFormatter,
    ): string {
        $thumbnailCid = $images->cidFor($card->imageUrl);
        $thumbnail = $thumbnailCid === null ? '' : '<td valign="top" width="88" style="width:88px;padding:0 12px 0 0;">'
            . '<img src="cid:' . $thumbnailCid . '" width="88" height="66" alt="" '
            . 'style="display:block;width:88px;height:66px;border-radius:8px;object-fit:cover;"></td>';

        $titleStyle = 'display:block;font-size:15px;font-weight:500;line-height:1.35;color:#2a2a2a;'
            . 'text-decoration:none;margin:4px 0;';
        $title = '<a href="' . $this->escape($card->url) . '" style="' . $titleStyle . '">'
            . $this->escapeText($card->title) . '</a>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . $thumbnail
            . '<td valign="top">' . $this->kicker($card, $images, $dateFormatter)
            . $title
            . $this->dek($card)
            . '</td></tr></table>';
    }

    private function kicker(DigestEntry $card, DigestImageSet $images, \IntlDateFormatter $dateFormatter): string
    {
        $faviconCid = $images->cidFor($card->faviconUrl);
        $favicon = $faviconCid === null ? '' : '<img src="cid:' . $faviconCid . '" width="16" height="16" alt="" '
            . 'style="width:16px;height:16px;border-radius:4px;vertical-align:middle;margin-right:6px;">';
        $when = $this->when($card->publishedAt, $dateFormatter);
        $time = $when === '' ? '' : '<span style="color:#c4c4c1;"> · </span>' . $this->escapeText($when);

        return '<div style="font-size:13px;color:#8f8f8b;">' . $favicon
            . $this->escapeText($card->feedName) . $time . '</div>';
    }

    private function dek(DigestEntry $card): string
    {
        if ($card->shortDescription === '') {
            return '';
        }

        return '<div style="font-size:13px;line-height:1.4;color:#5f5f5c;margin-top:4px;">'
            . $this->escapeText($card->shortDescription) . '</div>';
    }

    private function moreLink(DigestPageGroup $group, string $locale): string
    {
        $parameters = ['%count%' => (string) $group->remaining, '%term%' => $group->term];
        $label = $this->trans('digest.more_link', $parameters, $locale);
        $style = 'display:inline-block;margin:12px 0 2px;font-size:13px;color:#3f8676;'
            . 'text-decoration:none;font-weight:500;';

        return '<a href="' . $this->escape($group->moreUrl) . '" style="' . $style . '">'
            . $this->escapeText($label) . ' →</a>';
    }

    private function footer(string $locale): string
    {
        $manageLinkLabel = $this->trans('digest.manage_link_label', [], $locale);
        $manageLink = '<a href="' . $this->escape($this->links->settingsEmailUrl()) . '" '
            . 'style="color:#8f8f8b;text-decoration:underline;">'
            . $this->escapeText($manageLinkLabel) . '</a>';
        $manageHtml = $this->trans('digest.manage_html', ['%link%' => "\0"], $locale);
        $manage = strtr($this->escapeText($manageHtml), ["\0" => $manageLink]);
        $openReaderLabel = $this->trans('digest.open_reader', [], $locale);
        $openReader = '<a href="' . $this->escape($this->links->base()) . '" '
            . 'style="font-size:13px;color:#3f8676;text-decoration:none;">'
            . $this->escapeText($openReaderLabel) . ' →</a>';

        return '<tr><td style="padding:22px 24px 26px;border-top:1px solid #e4e4e2;">'
            . '<div style="margin-bottom:12px;">' . $openReader . '</div>'
            . '<div style="font-size:12px;line-height:1.5;color:#a7a7a3;">' . $manage . '</div>'
            . '</td></tr>';
    }

    private function today(string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC');

        return (string) $formatter->format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function when(?\DateTimeImmutable $publishedAt, \IntlDateFormatter $dateFormatter): string
    {
        if ($publishedAt === null) {
            return '';
        }

        return (string) $dateFormatter->format($publishedAt);
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, 'emails', $locale);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Text-node content needs no quote-escaping; keeps translation quotes (e.g. "%term%") literal. */
    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
