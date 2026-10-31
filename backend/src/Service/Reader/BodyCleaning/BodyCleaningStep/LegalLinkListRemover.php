<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\LinkListDetector;
use Dom\Element;

/**
 * Removes a site's footer links: the innermost link list around a link that names a legal page. Unlike other
 * boilerplate it goes wherever it sits, since no article holds one.
 */
final readonly class LegalLinkListRemover implements BodyCleaningStepInterface
{
    private const array WHOLE_LEGAL_LINK_TEXTS = [
        'privacy policy', 'cookie policy', 'terms of service', 'terms of use', 'imprint',
        'datenschutz', 'datenschutzerklärung', 'impressum', 'nutzungsbedingungen',
    ];

    public function __construct(private LinkListDetector $linkLists)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach ($pass->document->querySelectorAll('a') as $link) {
            if ($link->isConnected && self::namesALegalPage($link)) {
                $this->innermostListAround($link)?->remove();
            }
        }
    }

    private function innermostListAround(Element $link): ?Element
    {
        for ($ancestor = $link->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($this->linkLists->isLinkList($ancestor)) {
                return $ancestor;
            }
        }

        return null;
    }

    private static function namesALegalPage(Element $link): bool
    {
        return in_array(mb_strtolower(trim((string) $link->textContent)), self::WHOLE_LEGAL_LINK_TEXTS, true);
    }
}
