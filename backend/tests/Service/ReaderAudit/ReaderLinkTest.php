<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use PHPUnit\Framework\TestCase;

final class ReaderLinkTest extends TestCase
{
    public function testOpensTheEntryInsideTheSubscriptionThatHoldsIt(): void
    {
        $entry = $this->entry(514, 42, 'Wadephul: Afrika Chancenkontinent');
        $link = (new ReaderLink('http://localhost:4200'))->to($entry);

        self::assertSame(
            'http://localhost:4200/?subscription=42&entry=514-wadephul-afrika-chancenkontinent',
            $link,
        );
    }

    public function testFoldsAccentsAndUmlautsTheWayTheSpaSlugDoes(): void
    {
        $link = (new ReaderLink('http://localhost:4200'))->to($this->entry(7, 1, 'Grüße aus Zürich'));

        self::assertStringEndsWith('entry=7-grusse-aus-zurich', $link);
    }

    public function testATitleWithoutOneSlugCharacterFallsBackToTheBareId(): void
    {
        // The id is what the SPA parses; the slug is decoration, so a headline
        // that folds to nothing must not produce a trailing hyphen.
        $link = (new ReaderLink('http://localhost:4200/'))->to($this->entry(9, 1, '???'));

        self::assertSame('http://localhost:4200/?subscription=1&entry=9', $link);
    }

    private function entry(int $entryId, int $subscriptionId, string $title): SampledEntry
    {
        return new SampledEntry(
            $entryId,
            $subscriptionId,
            3,
            'Ein Feed',
            $title,
            'https://example.test/a',
            null,
            false,
        );
    }
}
