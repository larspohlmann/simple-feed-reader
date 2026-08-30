<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestFormat;
use PHPUnit\Framework\TestCase;

final class PreferencesDigestFormatTest extends TestCase
{
    public function testDefaultsToHtml(): void
    {
        $preferences = new Preferences(new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        self::assertSame(DigestFormat::Html, $preferences->getDigestFormat());
    }

    public function testAcceptsText(): void
    {
        $preferences = new Preferences(new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $preferences->setDigestFormat(DigestFormat::Text);

        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
    }
}
