<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\AuditFindingsFile;
use App\Service\ReaderAudit\Exception\UnwritableFindingsFileException;
use PHPUnit\Framework\TestCase;

final class AuditFindingsFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/audit-findings-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }
        if (is_dir($this->directory . '/nested')) {
            rmdir($this->directory . '/nested');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testWritesOneJsonLinePerFindingIntoADirectoryItCreates(): void
    {
        $first = $this->finding(1, 'Café');
        $second = $this->finding(2, 'A/B testing');

        $file = AuditFindingsFile::create($this->path());
        $file->append($first);
        $file->append($second);
        $file->close();

        $written = (string) file_get_contents($this->path());
        self::assertSame(
            json_encode($first->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
            . json_encode($second->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n",
            $written,
        );
        self::assertStringContainsString('"title":"Café"', $written);
        self::assertStringContainsString('"title":"A/B testing"', $written);
    }

    public function testCreatingTheFileAgainStartsItEmpty(): void
    {
        $earlier = AuditFindingsFile::create($this->path());
        $earlier->append($this->finding(1, 'Earlier run'));
        $earlier->close();

        AuditFindingsFile::create($this->path())->close();

        self::assertSame('', file_get_contents($this->path()));
    }

    public function testCreatingTheFileAtAnExistingDirectoryThrows(): void
    {
        mkdir($this->directory . '/nested', 0o775, true);

        $this->expectException(UnwritableFindingsFileException::class);
        $this->expectExceptionMessage(\sprintf('Cannot write %s.', $this->directory . '/nested'));

        AuditFindingsFile::create($this->directory . '/nested');
    }

    private function path(): string
    {
        return $this->directory . '/nested/findings.jsonl';
    }

    private function finding(int $entryId, string $title): AuditFinding
    {
        return new AuditFinding(
            $entryId,
            10,
            'Feed',
            $title,
            'https://example.com/source',
            'http://localhost:4200/reader/1',
            true,
            [],
            [],
        );
    }
}
