<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\LinkListDetector;
use Dom\Element;

/**
 * Removes a site's footer links: a link list with a link that names a legal page. Unlike other boilerplate it goes
 * wherever it sits, since no article holds one, and only the innermost list goes, never the block around it.
 */
final readonly class LegalLinkListRemover implements BodyCleaningStepInterface
{
    private const int MIN_LINKS_FOR_LIST = 3;

    /** Whole link texts that name a site's legal pages, German and English. */
    private const array LEGAL_PAGE_LABELS = [
        'privacy policy', 'cookie policy', 'terms of service', 'terms of use', 'imprint',
        'datenschutz', 'datenschutzerklärung', 'impressum', 'nutzungsbedingungen',
    ];

    public function __construct(private LinkListDetector $linkLists)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $body = $pass->document->body;
        if ($body === null) {
            return;
        }

        foreach (array_filter($this->legalLinkLists($body), $this->holdsNoOtherList(...)) as $list) {
            $list->remove();
        }
    }

    /** @return list<Element> */
    private function legalLinkLists(Element $body): array
    {
        $lists = [];
        foreach ($body->querySelectorAll('*') as $element) {
            if ($this->isLegalLinkList($element)) {
                $lists[] = $element;
            }
        }

        return $lists;
    }

    private function holdsNoOtherList(Element $list): bool
    {
        return !array_any(
            iterator_to_array($list->querySelectorAll('*')),
            fn (Element $descendant): bool => $this->isLegalLinkList($descendant),
        );
    }

    private function isLegalLinkList(Element $element): bool
    {
        $links = $element->getElementsByTagName('a');

        return $links->length >= self::MIN_LINKS_FOR_LIST
            && $this->linkLists->isLinkDominated($element)
            && array_any(iterator_to_array($links), self::namesALegalPage(...));
    }

    private static function namesALegalPage(Element $link): bool
    {
        return in_array(mb_strtolower(trim((string) $link->textContent)), self::LEGAL_PAGE_LABELS, true);
    }
}
