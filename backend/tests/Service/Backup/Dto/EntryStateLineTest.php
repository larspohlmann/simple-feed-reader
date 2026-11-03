<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\EntryStateLine;
use PHPUnit\Framework\TestCase;

final class EntryStateLineTest extends TestCase
{
    public function testAnOlderBackupHasNoShareInstant(): void
    {
        self::assertNull(EntryStateLine::fromLine($this->line())->sharedAt);
    }

    public function testItReadsTheShareInstant(): void
    {
        $line = EntryStateLine::fromLine($this->line(['sharedAt' => '2026-10-02T10:00:00+00:00']));

        self::assertSame('2026-10-02T10:00:00+00:00', $line->sharedAt?->format(\DATE_ATOM));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function line(array $extra = []): array
    {
        return $extra + [
            'feedUrl' => 'https://f.example/feed.xml',
            'guidHash' => hash('sha256', 'guid'),
            'isHidden' => false,
            'isFavorite' => false,
            'isKept' => false,
            'isViewed' => false,
        ];
    }
}
