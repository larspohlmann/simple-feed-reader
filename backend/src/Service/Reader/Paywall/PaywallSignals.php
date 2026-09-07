<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * The reader paywall verdict. Trust the publisher's schema.org
 * `isAccessibleForFree` declaration: premium marks a preview, free ends it. A
 * page that declares nothing falls back to the mere presence of a gated block
 * outside page furniture (#908).
 */
final readonly class PaywallSignals
{
    private function __construct(
        private ?bool $declaredPaywalled,
        private bool $hasGateBlock,
    ) {
    }

    public static function fromPage(string $html, ?HTMLDocument $normalized): self
    {
        return new self(
            SchemaOrgAccess::paywalledIn($html),
            $normalized !== null && PaywallBlocks::existOutsideFurnitureIn($normalized),
        );
    }

    /** True when the page is the free preview of a paywalled article. */
    public function isPreview(): bool
    {
        return $this->declaredPaywalled ?? $this->hasGateBlock;
    }
}
